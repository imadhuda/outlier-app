<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/apify.php';
require_once __DIR__ . '/anthropic.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/billing.php';

/**
 * The 15-day cycle as a state machine. Each step is one short cron tick:
 * start a scrape, or poll one, or score, or generate. Nothing blocks.
 */
class Pipeline {

    public static function beginRun($customerId) {
        $c = one("SELECT * FROM customers WHERE id=?", [$customerId]);
        if (!$c) throw new RuntimeException("No customer $customerId");
        /* Everything else in this app stamps UTC; the column default is the
           MySQL server's local clock, which made runs look hours out. */
        q("INSERT INTO runs (customer_id, status, started_at) VALUES (?, 'running', UTC_TIMESTAMP())", [$customerId]);
        $runId = lastId();
        Queue::push($customerId, 'scrape_ig_start', [], $runId);
        return $runId;
    }

    /** Trial gate: scrape only the creator's own account (cheap) and count recent videos. */
    public static function beginTrialCheck($customerId) {
        $c = one("SELECT * FROM customers WHERE id=?", [$customerId]);
        if (!$c) throw new RuntimeException("No customer $customerId");
        q("INSERT INTO runs (customer_id, kind, status, started_at) VALUES (?, 'trial_check', 'running', UTC_TIMESTAMP())", [$customerId]);
        $runId = lastId();
        Queue::push($customerId, 'trial_check_start', [], $runId);
        return $runId;
    }

    public static function handle(array $job) {
        $m = 'step_' . $job['step'];
        if (!method_exists(__CLASS__, $m)) throw new RuntimeException('Unknown step: ' . $job['step']);
        return self::$m($job);
    }

    private static function cfg() { return outlier_config(); }
    private static function apify() { return new Apify(self::cfg()['apify_token'] ?? ''); }
    private static function customer($id) { return one("SELECT * FROM customers WHERE id=?", [$id]); }

    private static function handles($c) {
        $own = trim((string)$c['ig_handle']);
        $comp = json_decode((string)$c['competitors'], true) ?: [];
        $comp = array_values(array_filter(array_map('trim', $comp)));
        return ['own' => $own, 'competitors' => $comp,
                'all' => array_values(array_unique(array_filter(array_merge([$own], $comp))))];
    }

    /* ── 0. trial gate: own account only ────────────────────────────────── */
    private static function step_trial_check_start(array $job) {
        $c = self::customer($job['customer_id']);
        $own = strtolower(trim((string)$c['ig_handle']));
        if (!$own) throw new RuntimeException('Customer has no Instagram handle');
        $runId = self::apify()->start(Apify::IG_SCRAPER, Apify::igPostsInput([$own], 15), 1024, 300);
        Queue::push($job['customer_id'], 'trial_check_poll', ['apifyRun' => $runId], $job['run_id'], 45);
        return "checking @$own's recent posts (Apify $runId)";
    }

    private static function step_trial_check_poll(array $job) {
        $ap = self::apify();
        $st = $ap->status($job['payload']['apifyRun']);
        if (!Apify::isTerminal($st['status'])) {
            Queue::push($job['customer_id'], 'trial_check_poll', $job['payload'], $job['run_id'], 45);
            return 'still ' . $st['status'];
        }
        if ($st['status'] !== 'SUCCEEDED') throw new RuntimeException('Apify check ' . $st['status']);
        $c = self::customer($job['customer_id']);
        $own = strtolower(trim((string)$c['ig_handle']));
        $rows = $ap->items($st['datasetId'], 100);
        $posts = array_filter(array_map([Apify::class, 'normalisePost'], $rows),
                              fn($p) => $p['owner'] === $own && $p['is_video'] && $p['code'] !== '');
        $since = time() - Billing::TRIAL_WINDOW * 86400;
        $recent = 0;
        $ins = db()->prepare("INSERT IGNORE INTO reels (run_id,customer_id,is_own,handle,code,url,plays,views,likes,comment_count,duration_s,posted_at,age_days)
                              VALUES (?,?,1,?,?,?,?,?,?,?,?,?,?)");
        foreach ($posts as $p) {
            $ts = strtotime($p['timestamp']);
            if (!$ts) continue;
            if ($ts >= $since) $recent++;
            $ins->execute([$job['run_id'], $job['customer_id'], $own, $p['code'], $p['url'], $p['plays'], $p['views'],
                           $p['likes'], $p['comments'], $p['duration'], gmdate('Y-m-d H:i:s', $ts), round((time() - $ts) / 86400, 2)]);
        }
        $need = Billing::TRIAL_MIN_POSTS;
        $cost = count($rows) * Apify::COST_IG_POST;
        if ($recent >= $need) {
            q("UPDATE runs SET status='done', finished_at=UTC_TIMESTAMP(), posts_scored=?, apify_cost_usd=? WHERE id=?",
              [$recent, $cost, $job['run_id']]);
            /* Passed: the real analysis starts right away. */
            $full = self::beginRun($job['customer_id']);
            return "posted $recent/$need in the last week — starting full run #$full";
        }
        $deadline = $c['onboarded_at'] ? strtotime($c['onboarded_at'] . ' UTC') + (Billing::TRIAL_WINDOW + 1) * 86400 : null;
        $msg = "You have posted $recent of $need videos in the last 7 days. "
             . ($deadline && time() > $deadline
                ? 'The 7-day window has passed — keep posting and the check runs again in two days, or choose a plan to start now.'
                : 'Keep posting; we check again in two days and start your analysis as soon as you reach ' . $need . '.');
        q("UPDATE runs SET status='skipped', finished_at=UTC_TIMESTAMP(), posts_scored=?, apify_cost_usd=?, error=? WHERE id=?",
          [$recent, $cost, $msg, $job['run_id']]);
        $cfg = self::cfg();
        $m = new Mailer($cfg['resend_key'] ?? '', $cfg['from_email'] ?? '', $cfg['from_name'] ?? '');
        if ($m->enabled() && !empty($c['email']))
            $m->send($c['email'], "Trial check: $recent of $need videos posted", '<p>' . htmlspecialchars($msg) . '</p>');
        return "posted $recent/$need — not yet";
    }

    /* ── 1. start the Instagram scrape ──────────────────────────────────── */
    private static function step_scrape_ig_start(array $job) {
        $c = self::customer($job['customer_id']);
        $h = self::handles($c);
        if (!$h['all']) throw new RuntimeException('Customer has no Instagram handles');
        $cfg = self::cfg();
        /* The creator's own posts are only needed to score their content, so keep that
           shallow. Competitors are where the patterns come from, so go deeper there. */
        $per = max((int)($cfg['own_posts'] ?? 20), (int)($cfg['competitor_posts'] ?? 30));
        $runId = self::apify()->start(Apify::IG_SCRAPER, Apify::igPostsInput($h['all'], $per));
        Queue::push($job['customer_id'], 'scrape_ig_poll',
                    ['apifyRun' => $runId, 'ownLimit' => (int)($cfg['own_posts'] ?? 20)],
                    $job['run_id'], 45);
        return "started Apify run $runId for " . count($h['all']) . ' account(s)';
    }

    /* ── 2. poll it, then score everything ──────────────────────────────── */
    private static function step_scrape_ig_poll(array $job) {
        $ap = self::apify();
        $st = $ap->status($job['payload']['apifyRun']);
        if (!Apify::isTerminal($st['status'])) {
            Queue::push($job['customer_id'], 'scrape_ig_poll', $job['payload'], $job['run_id'], 45);
            return 'still ' . $st['status'] . ' — will check again';
        }
        if ($st['status'] !== 'SUCCEEDED') throw new RuntimeException('Apify scrape ' . $st['status']);

        $rows = [];
        for ($off = 0; $off < 2000; $off += 200) {
            $page = $ap->items($st['datasetId'], 200, $off);
            if (!$page) break;
            $rows = array_merge($rows, $page);
            if (count($page) < 200) break;
        }
        if (!$rows) throw new RuntimeException('Scrape returned no posts — are the accounts public?');

        $c = self::customer($job['customer_id']);
        $h = self::handles($c);
        $posts = array_map([Apify::class, 'normalisePost'], $rows);
        /* Instagram returns tagged and collaborator posts owned by other people. */
        $posts = array_values(array_filter($posts, fn($p) => in_array($p['owner'], $h['all'], true)));

        $accounts = []; $failed = [];
        foreach ($h['all'] as $handle) {
            $mine = array_values(array_filter($posts, fn($p) => $p['owner'] === $handle));
            if (!$mine) { $failed[] = $handle; continue; }
            $a = Engine::scoreAccount($handle, $mine);
            if (!$a['counted']) { $failed[] = $handle; continue; }
            $a['is_own'] = ($handle === $h['own']);
            $accounts[] = $a;
        }
        if (!$accounts) throw new RuntimeException('No account returned scoreable video posts');

        self::storeReels($job['run_id'], $job['customer_id'], $accounts);

        $all = array_merge(...array_map(fn($a) => $a['reels'], $accounts));
        $mined = Engine::mineComments($all, 2);
        self::storeCommentLeads($job['customer_id'], $job['run_id'], $accounts);
        $tooFresh = count(array_filter($all, fn($r) => $r['age_days'] < (float)(self::cfg()['min_days_to_score'] ?? 7)));

        q("UPDATE runs SET posts_scored=?, too_fresh=?, outliers_found=?, false_outliers=?,
                  apify_cost_usd=apify_cost_usd+?, findings=? WHERE id=?", [
            count($all), $tooFresh,
            count(array_filter($all, fn($r) => $r['is_outlier'])),
            count(array_filter($all, fn($r) => $r['flag'] === 'false')),
            count($rows) * Apify::COST_IG_POST,
            json_encode(['accounts' => array_map(fn($a) => [
                'handle' => $a['handle'], 'is_own' => $a['is_own'], 'counted' => $a['counted'],
                'median_plays' => $a['median_plays'], 'band' => $a['band']], $accounts),
                'mined' => $mined, 'failed' => $failed], JSON_UNESCAPED_UNICODE),
            $job['run_id']]);

        $cfg2 = self::cfg();
        $depth = (int)($cfg2['competitor_transcripts'] ?? 8);
        $comp = array_values(array_filter($accounts, fn($a) => !$a['is_own']));
        $mineAcc = array_values(array_filter($accounts, fn($a) => $a['is_own']));

        /* Competitors give the hook patterns, every run. The creator's own reels are
           transcribed ONCE, to build the voice profile — after that it is reused, so
           the same person is never paid for twice. */
        $td = $depth > 0 && $comp
            ? array_slice(Engine::pickTeardown($comp, max(1, (int)ceil($depth / count($comp)))), 0, $depth)
            : [];
        $ownTd = [];
        if ($mineAcc && self::voiceIsStale($c)) {
            $n = (int)($cfg2['own_transcripts'] ?? 6);
            $ownTd = array_slice(Engine::pickTeardown($mineAcc, $n), 0, $n);
        }
        $all = array_merge($td, $ownTd);

        if ($all) {
            Queue::push($job['customer_id'], 'transcribe_start',
                        ['urls' => array_values(array_unique(array_column($all, 'url'))),
                         'own'  => array_values(array_column($ownTd, 'code'))],
                        $job['run_id']);
        } else {
            Queue::push($job['customer_id'], 'generate', [], $job['run_id']);
        }
        return 'scored ' . count($all) . ' reels across ' . count($accounts) . ' account(s)'
             . ($failed ? '; failed: ' . implode(', ', $failed) : '');
    }

    private static function storeReels($runId, $customerId, array $accounts) {
        $sql = "INSERT INTO reels (run_id,customer_id,is_own,handle,code,url,plays,views,likes,comment_count,
                    duration_s,posted_at,age_days,ratio,outlier_score,velocity,flag,is_outlier)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE plays=VALUES(plays), views=VALUES(views), velocity=VALUES(velocity)";
        $st = db()->prepare($sql);
        foreach ($accounts as $a) {
            foreach ($a['reels'] as $r) {
                $ts = strtotime((string)$r['timestamp']);
                $st->execute([$runId, $customerId, $a['is_own'] ? 1 : 0, $r['handle'], $r['code'], $r['url'],
                    $r['plays'], $r['views'], $r['likes'], $r['comments'], $r['duration'],
                    $ts ? gmdate('Y-m-d H:i:s', $ts) : null, $r['age_days'], $r['ratio'],
                    $r['outlier_score'], $r['velocity'], $r['flag'], $r['is_outlier'] ? 1 : 0]);
            }
        }
    }

    /* Best-effort: question comments on the creator's OWN posts, surfaced as reply /
       video ideas. Scraped data rarely tells us if the owner replied, so these are
       shown as "questions to answer", not asserted as strictly unanswered. */
    private static function storeCommentLeads($customerId, $runId, array $accounts) {
        $own = array_values(array_filter($accounts, fn($a) => $a['is_own']));
        if (!$own) return;
        $ins = db()->prepare("INSERT IGNORE INTO comment_leads (customer_id,run_id,reel_code,text,is_question,answered)
                              VALUES (?,?,?,?,?,0)");
        foreach ($own as $a) {
            foreach ($a['reels'] as $r) {
                foreach (($r['top_comments'] ?? []) as $c) {
                    $t = trim((string)$c);
                    $len = mb_strlen($t, 'UTF-8');
                    if ($len < 8 || $len > 400) continue;
                    if (!Engine::looksLikeQuestion($t)) continue;
                    $ins->execute([$customerId, $runId, (string)($r['code'] ?? ''), mb_substr($t, 0, 600), 1]);
                }
            }
        }
    }

    /* ── 3. transcribe the genuine outliers ─────────────────────────────── */
    private static function step_transcribe_start(array $job) {
        $urls = $job['payload']['urls'] ?? [];
        if (!$urls) { Queue::push($job['customer_id'], 'generate', [], $job['run_id']); return 'nothing to transcribe'; }
        $runId = self::apify()->start(Apify::IG_TRANSCRIPT, Apify::igTranscriptInput($urls));
        Queue::push($job['customer_id'], 'transcribe_poll',
                    ['apifyRun' => $runId, 'n' => count($urls)], $job['run_id'], 45);
        return 'transcribing ' . count($urls) . ' reel(s)';
    }

    private static function step_transcribe_poll(array $job) {
        $ap = self::apify();
        $st = $ap->status($job['payload']['apifyRun']);
        if (!Apify::isTerminal($st['status'])) {
            Queue::push($job['customer_id'], 'transcribe_poll', $job['payload'], $job['run_id'], 45);
            return 'still ' . $st['status'];
        }
        $got = 0;
        if ($st['status'] === 'SUCCEEDED') {
            $upd = db()->prepare("UPDATE reels SET hook=?, transcript=? WHERE run_id=? AND code=?");
            foreach ($ap->items($st['datasetId'], 200) as $t) {
                if (empty($t['shortCode']) || empty($t['transcript'])) continue;
                $upd->execute([$t['hook3s'] ?? '', $t['transcript'], $job['run_id'], $t['shortCode']]);
                $got++;
            }
            q("UPDATE runs SET apify_cost_usd=apify_cost_usd+? WHERE id=?",
              [$got * Apify::COST_TRANSCRIPT, $job['run_id']]);
        }
        Queue::push($job['customer_id'], 'voice_profile', ['own' => $job['payload']['own'] ?? []], $job['run_id']);
        /* A failed transcription is not fatal — the metrics still stand. */
        return $st['status'] === 'SUCCEEDED' ? "transcribed $got" : 'transcription ' . $st['status'] . ', continuing on metrics only';
    }

    /* ── 3b. voice profile, from the creator's own transcripts ──────────── */
    private static function voiceIsStale($c) {
        if (empty($c['voice_profile'])) return true;
        if (empty($c['voice_built_at'])) return true;
        return strtotime($c['voice_built_at']) < strtotime('-90 days');
    }

    private static function step_voice_profile(array $job) {
        $c = self::customer($job['customer_id']);
        if (!self::voiceIsStale($c)) {
            Queue::push($job['customer_id'], 'generate', [], $job['run_id']);
            return 'voice profile still fresh, reused';
        }
        $rows = all("SELECT hook, transcript FROM reels
                     WHERE run_id=? AND is_own=1 AND transcript IS NOT NULL AND transcript<>''
                     ORDER BY velocity DESC LIMIT 6", [$job['run_id']]);
        if (count($rows) < 2) {
            Queue::push($job['customer_id'], 'generate', [], $job['run_id']);
            return 'only ' . count($rows) . ' own transcript(s) — too few for a voice profile, continuing without one';
        }
        $claude = new Claude(self::cfg()['anthropic_key'] ?? '');
        $r = $claude->json(Claude::voiceSystem(),
                           Claude::voicePrompt($c['ig_handle'], $rows), 4000);
        $text = Claude::renderVoice($r['data']);
        if ($text === '') {
            Queue::push($job['customer_id'], 'generate', [], $job['run_id']);
            return 'voice profile came back empty, continuing without one';
        }
        q("UPDATE customers SET voice_profile=?, voice_built_at=UTC_TIMESTAMP() WHERE id=?",
          [$text, $job['customer_id']]);
        Queue::push($job['customer_id'], 'generate', [], $job['run_id']);
        return 'voice profile built from ' . count($rows) . ' of their own transcripts';
    }

    /* ── 4. generate scripts from the run's own data ────────────────────── */
    private static function step_generate(array $job) {
        $cfg = self::cfg();
        $c = self::customer($job['customer_id']);
        $run = one("SELECT * FROM runs WHERE id=?", [$job['run_id']]);
        $f = json_decode((string)$run['findings'], true) ?: [];

        $top = all("SELECT handle,code,url,plays,views,age_days,ratio,outlier_score,velocity,flag,hook
                    FROM reels WHERE run_id=? ORDER BY velocity DESC LIMIT 20", [$job['run_id']]);
        $withHooks = array_values(array_filter($top, fn($r) => !empty($r['hook'])));
        $clusters = $withHooks ? Engine::clusterHooks(array_map(fn($r) => [
            'handle' => $r['handle'], 'hook3s' => $r['hook'], 'velocity' => (float)$r['velocity'],
            'plays' => (int)$r['plays'], 'url' => $r['url']], $withHooks)) : [];

        /* Sub-niches: split on commas AND on the em/en dash that usually separates
           the headline niche from its list, so "UAE real estate — brokers, developers"
           yields real labels instead of one long string. */
        $rawNiche = (string)$c['niche'];
        $head = null; $tail = $rawNiche;
        if (preg_match('/^(.*?)\s*[—–-]\s*(.+)$/u', $rawNiche, $m)) { $head = trim($m[1]); $tail = $m[2]; }
        $subs = array_values(array_filter(array_map('trim', explode(',', $tail))));
        if ($head) $subs = array_map(fn($x) => $head . ' — ' . $x, $subs);
        if (!$subs) $subs = [$rawNiche];

        $ownHooks = array_column(all("SELECT hook FROM reels WHERE run_id=? AND is_own=1
                                      AND hook IS NOT NULL AND hook<>'' ORDER BY velocity DESC LIMIT 6",
                                     [$job['run_id']]), 'hook');

        $ctx = [
            'name' => $c['name'], 'handle' => $c['ig_handle'], 'niche' => $rawNiche, 'subniches' => $subs,
            'language' => $c['language'] ?: 'English',
            'voice' => (string)$c['voice_profile'],
            'own_hooks' => $ownHooks,
            'accounts' => $f['accounts'] ?? [],
            'top' => array_map(fn($r) => [
                'handle' => $r['handle'], 'is_own' => !empty($r['is_own']),
                'plays' => (int)$r['plays'], 'age_days' => (float)$r['age_days'],
                'ratio' => $r['ratio'] === null ? null : (float)$r['ratio'],
                'outlier_score' => (float)$r['outlier_score'], 'velocity' => (float)$r['velocity'],
                'flag' => $r['flag'], 'hook' => $r['hook']], $top),
            'clusters' => $clusters,
            'repeated' => $f['mined']['repeated'] ?? [],
            'objections' => $f['mined']['objections'] ?? [],
            'vocab' => array_slice($f['mined']['vocab'] ?? [], 0, 20),
            'duration' => (string)($c['duration_pref'] ?: '30-40 sec'),
            'count' => (int)($cfg['scripts_per_run'] ?? 7),
        ];
        /* Quota: a plan caps how many scripts a month may hold; the trial batch is
           the plan's whole allowance (10), a paid week is scripts_per_run. */
        $quota = $c['user_id'] ? Billing::allowance($c['user_id']) : ['n' => null];
        if ($quota['n'] === 0) throw new RuntimeException('Plan allowance exhausted: ' . $quota['why']);
        $sub = $quota['sub'] ?? null;
        if ($sub && $sub['status'] === 'trial') $ctx['count'] = (int)$quota['n'];
        elseif ($quota['n'] !== null) $ctx['count'] = min($ctx['count'], (int)$quota['n']);

        $claude = new Claude($cfg['anthropic_key'] ?? '');
        $r = $claude->json(Claude::systemPrompt(), Claude::buildPrompt($ctx), 16000);
        $scripts = $r['data']['scripts'] ?? (isset($r['data'][0]) ? $r['data'] : []);
        if (!$scripts) throw new RuntimeException('Model returned no scripts');

        $narrative = Claude::renderFindings($r['data']['findings'] ?? null);
        if ($narrative !== '') q("UPDATE runs SET narrative=? WHERE id=?", [$narrative, $job['run_id']]);

        /* The model was told to use only the given sub-niches. Verify, do not trust. */
        $offNiche = 0;
        foreach ($scripts as $s2) {
            $nn = trim((string)($s2['niche'] ?? ''));
            $hit = false;
            foreach ($subs as $sn) if ($nn !== '' && (stripos($sn, $nn) !== false || stripos($nn, $sn) !== false)) $hit = true;
            if (!$hit) $offNiche++;
        }

        $ins = db()->prepare("INSERT INTO scripts (run_id,customer_id,idx,niche,concept,hook_pattern,
                    hook_line,body,duration_label,caption,cta_keyword,source_insight)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $i = 0;
        foreach ($scripts as $s) {
            if (empty($s['body'])) continue;
            $i++;
            $ins->execute([$job['run_id'], $job['customer_id'], $i,
                substr((string)($s['niche'] ?? ''), 0, 120), substr((string)($s['concept'] ?? ''), 0, 190),
                substr((string)($s['hook_pattern'] ?? ''), 0, 60), (string)($s['hook_line'] ?? ''),
                (string)$s['body'], substr((string)($s['duration_label'] ?? '30 sec'), 0, 30),
                (string)($s['caption'] ?? ''), substr(strtoupper((string)($s['cta_keyword'] ?? '')), 0, 60),
                (string)($s['source_insight'] ?? '')]);
        }
        q("UPDATE runs SET scripts_delivered=? WHERE id=?", [$i, $job['run_id']]);
        if ($c['user_id'] && $i > 0) Billing::consume($c['user_id'], $i);
        Queue::push($job['customer_id'], 'deliver', [], $job['run_id']);
        return "generated $i script(s)"
             . ($c['voice_profile'] ? ' in their own voice' : ' WITHOUT a voice profile')
             . ($offNiche ? " — WARNING: $offNiche drifted outside the given sub-niches" : '');
    }

    /* ── 5. deliver ─────────────────────────────────────────────────────── */
    private static function step_deliver(array $job) {
        $cfg = self::cfg();
        $c = self::customer($job['customer_id']);
        $run = one("SELECT * FROM runs WHERE id=?", [$job['run_id']]);
        q("UPDATE runs SET status='done', finished_at=UTC_TIMESTAMP() WHERE id=?", [$job['run_id']]);

        $m = new Mailer($cfg['resend_key'] ?? '', $cfg['from_email'] ?? '', $cfg['from_name'] ?? '');
        if (!$m->enabled() || empty($c['email'])) return 'run complete (no email sent)';

        $scripts = all("SELECT * FROM scripts WHERE run_id=? ORDER BY idx", [$job['run_id']]);
        $h = '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:0 auto;color:#111">';
        $h .= '<h2 style="margin:0 0 6px">Your ' . count($scripts) . ' scripts are ready</h2>';
        $h .= '<p style="color:#555;font-size:14px">We scored ' . (int)$run['posts_scored'] . ' reels, found '
            . (int)$run['outliers_found'] . ' genuine outliers and excluded ' . (int)$run['false_outliers']
            . ' false ones. ' . (int)$run['too_fresh'] . ' posts were too recent to score.</p><hr>';
        foreach ($scripts as $s) {
            $h .= '<div style="margin:22px 0"><div style="font-size:12px;color:#888;text-transform:uppercase">'
                . htmlspecialchars($s['niche']) . ' &middot; ' . htmlspecialchars($s['hook_pattern']) . '</div>'
                . '<div style="font-weight:700;font-size:16px;margin:4px 0">' . htmlspecialchars($s['hook_line']) . '</div>'
                . '<div style="white-space:pre-wrap;font-size:14px;line-height:1.6">' . htmlspecialchars($s['body']) . '</div>'
                . '<div style="font-size:12px;color:#888;margin-top:8px">CTA: ' . htmlspecialchars($s['cta_keyword'])
                . ' &middot; ' . htmlspecialchars($s['source_insight']) . '</div></div>';
        }
        $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
        if ($base) $h .= '<p><a href="' . htmlspecialchars($base . '/app.php?run=' . (int)$job['run_id']) . '">Open this batch and download the Excel report</a></p>';
        $h .= '</div>';
        $m->send($c['email'], 'Your ' . count($scripts) . ' new scripts', $h);
        return 'run complete, email sent to ' . $c['email'];
    }
}
