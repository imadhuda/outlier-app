<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Outlier analysis engine — pure functions, no database, no network.
   Port of the JavaScript engine, with the same test suite behind it.
   ═══════════════════════════════════════════════════════════════════════════ */

class Engine {

    public static function median(array $nums) {
        $a = array_values(array_filter($nums, function ($n) {
            return is_numeric($n) && is_finite((float)$n) && $n >= 0;
        }));
        if (!$a) return 0.0;
        sort($a, SORT_NUMERIC);
        $n = count($a); $m = intdiv($n, 2);
        return $n % 2 ? (float)$a[$m] : ((float)$a[$m - 1] + (float)$a[$m]) / 2;
    }

    /** Days since an ISO timestamp, floored at 1 so a fresh post never divides by ~0. */
    public static function daysSince($iso, $nowTs = null) {
        $t = strtotime((string)$iso);
        if ($t === false) return null;
        $d = (($nowTs ?: time()) - $t) / 86400;
        return $d < 1 ? 1.0 : $d;
    }

    /** Instagram: plays counts scroll-bys, views counts real watches. */
    public static function completionRatio($plays, $views) {
        if (!$plays || !$views || $plays <= 0) return null;
        $r = $views / $plays;
        return ($r > 0 && $r <= 1.5) ? $r : null;   // >1 appears on odd API rows
    }

    /** Robust band: median ± 2·MAD. Falls back when the sample is too small. */
    public static function ratioBand(array $ratios) {
        $r = array_values(array_filter($ratios, function ($x) { return $x !== null && is_finite($x); }));
        if (count($r) < 4) {
            return ['med' => $r ? self::median($r) : null, 'lo' => null, 'hi' => null,
                    'n' => count($r), 'weak' => true];
        }
        $med = self::median($r);
        $dev = array_map(function ($x) use ($med) { return abs($x - $med); }, $r);
        $mad = self::median($dev) * 1.4826;
        if ($mad <= 0.005) $mad = 0.005;
        return ['med' => $med, 'lo' => max(0, $med - 2 * $mad), 'hi' => $med + 2 * $mad,
                'n' => count($r), 'weak' => false];
    }

    /**
     * Score one account's posts.
     * $posts rows: code,url,plays,views,likes,comments,duration,timestamp,is_video,caption,top_comments[]
     */
    public static function scoreAccount($handle, array $posts, $nowTs = null) {
        $reels = array_values(array_filter($posts, function ($p) {
            return !empty($p['is_video']) && ($p['plays'] ?? 0) > 0;
        }));
        $medPlays = self::median(array_column($reels, 'plays'));

        $ratios = [];
        foreach ($reels as $p) $ratios[] = self::completionRatio($p['plays'] ?? 0, $p['views'] ?? 0);
        $band = self::ratioBand($ratios);

        $rows = [];
        foreach ($reels as $p) {
            $age   = self::daysSince($p['timestamp'] ?? '', $nowTs);
            $ratio = self::completionRatio($p['plays'] ?? 0, $p['views'] ?? 0);
            $out   = $medPlays > 0 ? $p['plays'] / $medPlays : 0.0;
            $vel   = $age ? $p['plays'] / $age : 0.0;

            /* Far BELOW the account's own band = false outlier (boosted or scroll trap).
               Far ABOVE = the account's best video, worth tearing down first. */
            $flag = 'normal';
            if ($ratio !== null && !$band['weak']) {
                if ($band['lo'] !== null && $ratio < $band['lo'] * 0.6)      $flag = 'false';
                elseif ($band['hi'] !== null && $ratio > $band['hi'])        $flag = 'high-retention';
            }

            $rows[] = [
                'handle' => $handle, 'code' => $p['code'] ?? '', 'url' => $p['url'] ?? '',
                'caption' => $p['caption'] ?? '',
                'plays' => (int)($p['plays'] ?? 0), 'views' => (int)($p['views'] ?? 0),
                'likes' => (int)($p['likes'] ?? 0), 'comments' => (int)($p['comments'] ?? 0),
                'duration' => (float)($p['duration'] ?? 0), 'timestamp' => $p['timestamp'] ?? '',
                'age_days' => $age, 'ratio' => $ratio, 'outlier_score' => $out, 'velocity' => $vel,
                'engagement' => ($p['plays'] ?? 0) ? (($p['likes'] ?? 0) + ($p['comments'] ?? 0)) / $p['plays'] : 0.0,
                'flag' => $flag,
                'is_outlier' => ($out >= 1.5 && $flag !== 'false'),
                'top_comments' => $p['top_comments'] ?? [],
            ];
        }
        usort($rows, function ($a, $b) { return $b['velocity'] <=> $a['velocity']; });

        return ['handle' => $handle, 'median_plays' => $medPlays, 'band' => $band,
                'reels' => $rows, 'counted' => count($reels),
                'skipped' => count($posts) - count($reels)];
    }

    /** Genuine outliers plus every high-retention reel, velocity-ranked. */
    public static function pickTeardown(array $accounts, $perAccount = 5) {
        $out = [];
        foreach ($accounts as $acc) {
            $picks = array_values(array_filter($acc['reels'], function ($r) {
                return $r['is_outlier'] || $r['flag'] === 'high-retention';
            }));
            if (!$picks) $picks = array_slice($acc['reels'], 0, 2);   // never leave an account unrepresented
            $out = array_merge($out, array_slice($picks, 0, $perAccount));
        }
        usort($out, function ($a, $b) { return $b['velocity'] <=> $a['velocity']; });
        return $out;
    }

    private static $STOP = null;
    private static function stop() {
        if (self::$STOP === null) {
            self::$STOP = array_flip(explode(' ',
              'the a an and or but if then than that this these those is are was were be been being of to in on '
            . 'for with at by from as it its i you he she they we me my your his her their our not no do does did '
            . 'so very just about into over under can could would should will shall may might must have has had what which '
            . 'who whom when where why how all any both each few more most other some such only own same too don now '
            . 'am pm one two three like get got go going know think really thing things want need make made lot bit yes ok '
            . 'okay thanks thank please hi hello hey good great nice love amazing wow best'));
        }
        return self::$STOP;
    }

    /** Public: significant words in a phrase, stopwords and short words removed. */
    public static function keywords($t) { return self::contentWords((string)$t); }
    /** Public: does this text read as a question / request? */
    public static function looksLikeQuestion($t) { return self::isQuestion((string)$t); }

    private static function contentWords($t) {
        $stop = self::stop(); $seen = []; $out = [];
        $clean = preg_replace('/[^a-z0-9\s]/', ' ', mb_strtolower($t, 'UTF-8'));
        foreach (preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (mb_strlen($w) > 3 && !isset($stop[$w]) && !isset($seen[$w])) { $seen[$w] = 1; $out[] = $w; }
        }
        return $out;
    }

    private static function jaccard(array $a, array $b) {
        if (!$a || !$b) return 0.0;
        $inA = array_flip($a); $hit = 0;
        foreach ($b as $w) if (isset($inA[$w])) $hit++;
        return $hit / (count($a) + count($b) - $hit);
    }

    private static function isQuestion($t) {
        return strpos($t, '?') !== false
            || preg_match('/^(how|what|which|when|where|why|who|can|does|do|is|are|any)\b/i', trim($t));
    }

    /**
     * Comment mining. Paraphrases of the same question are clustered by word
     * overlap — exact-key matching misses "best yield community?" vs
     * "which community has the best yield?", which are the same question.
     */
    public static function mineComments(array $rows, $minRepeat = 2) {
        $qs = []; $objections = []; $freq = [];
        $stop = self::stop();
        foreach ($rows as $r) {
            foreach (($r['top_comments'] ?? []) as $c) {
                $t = trim((string)$c);
                $len = mb_strlen($t, 'UTF-8');
                if ($len < 8 || $len > 400) continue;
                if (self::isQuestion($t)) $qs[] = ['text' => $t, 'from' => $r['code'] ?? ''];
                if (preg_match('/\b(but|actually|wrong|disagree|not true|nonsense|misleading|scam|bubble|crash)\b/i', $t))
                    $objections[] = ['text' => $t, 'from' => $r['code'] ?? ''];
                $clean = preg_replace('/[^a-z0-9\s\']/', ' ', mb_strtolower($t, 'UTF-8'));
                foreach (preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY) as $w) {
                    if (mb_strlen($w) > 3 && !isset($stop[$w])) $freq[$w] = ($freq[$w] ?? 0) + 1;
                }
            }
        }

        $buckets = [];
        foreach ($qs as $qq) {
            $w = self::contentWords($qq['text']);
            $bestIdx = -1; $bestScore = 0.0;
            foreach ($buckets as $i => $b) {
                $sc = self::jaccard($w, $b['words']);
                if ($sc > $bestScore) { $bestScore = $sc; $bestIdx = $i; }
            }
            if ($bestIdx >= 0 && $bestScore >= 0.34) {
                $buckets[$bestIdx]['items'][] = $qq;
                $shared = array_values(array_intersect($buckets[$bestIdx]['words'], $w));
                $buckets[$bestIdx]['words'] = $shared ?: $w;   // keep the shared core, never empty
            } else {
                $buckets[] = ['words' => $w, 'items' => [$qq]];
            }
        }

        $repeated = [];
        foreach ($buckets as $b) {
            if (count($b['items']) >= $minRepeat) {
                $repeated[] = ['count' => count($b['items']), 'example' => $b['items'][0]['text'],
                               'theme' => array_slice($b['words'], 0, 4),
                               'all' => array_column($b['items'], 'text')];
            }
        }
        usort($repeated, function ($a, $b) { return $b['count'] <=> $a['count']; });

        arsort($freq);
        $vocab = [];
        foreach ($freq as $w => $n) { if ($n >= 3) $vocab[] = ['word' => $w, 'n' => $n]; }
        $vocab = array_slice($vocab, 0, 25);

        return ['repeated' => $repeated, 'all_questions' => $qs,
                'objections' => $objections, 'vocab' => $vocab];
    }

    private static $PATTERNS = [
      ['Breakdown',  '/^(here(\'s| is| are)|this is|let(\'s| us) (break|look)|a breakdown|breaking down)/i'],
      ['Data Drop',  '/(\d[\d,.]*\s*(%|percent|million|billion|k\b|bn\b|m\b)|\b(down|up|fell|rose|dropped|lowest|highest|cheapest|record|doubled|halved)\b[^?]*\b(\d+|one|two|three|four|five|six|seven|eight|nine|ten|dozen|hundred|thousand|million|billion|years?|months?|weeks?|days?)\b)/i'],
      ['Question',   '/^(how much|how many|how do|is |are |should |what |why |which |does |do you|can you)/i'],
      ['Contrarian', '/\b(most people|nobody|everyone|no one|actually|myth|truth|wrong|don\'t (even )?know|misunderstood)\b/i'],
      ['Villain',    '/\b(stealing|scam|ripping|lying|trap|killing|costing you|burning|wasting)\b/i'],
      ['What-if',    '/^(what if|imagine|suppose|picture this)/i'],
      ['Bold Claim', '/\b(best|worst|only|never|always|guaranteed|biggest|fastest)\b/i'],
    ];

    public static function classifyHook($hook) {
        $h = trim((string)$hook);
        if ($h === '') return 'Unclassified';
        foreach (self::$PATTERNS as [$id, $re]) if (preg_match($re, $h)) return $id;
        return 'Statement';
    }

    /** One instance is noise. Three or more across two or more accounts is signal. */
    public static function clusterHooks(array $teardown) {
        $by = [];
        foreach ($teardown as $r) {
            $p = self::classifyHook($r['hook3s'] ?? ($r['hook'] ?? ''));
            $by[$p][] = $r;
        }
        $out = [];
        foreach ($by as $pattern => $rows) {
            $accts = array_unique(array_column($rows, 'handle'));
            $out[] = [
                'pattern' => $pattern, 'count' => count($rows), 'accounts' => count($accts),
                'med_velocity' => self::median(array_column($rows, 'velocity')),
                'signal' => (count($rows) >= 3 && count($accts) >= 2),
                'examples' => array_map(function ($r) {
                    return ['handle' => $r['handle'], 'hook' => $r['hook3s'] ?? ($r['hook'] ?? ''),
                            'plays' => $r['plays'], 'velocity' => $r['velocity'], 'url' => $r['url'] ?? ''];
                }, array_slice($rows, 0, 4)),
            ];
        }
        usort($out, function ($a, $b) { return $b['med_velocity'] <=> $a['med_velocity']; });
        return $out;
    }
}
