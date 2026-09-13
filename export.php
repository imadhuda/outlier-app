<?php
/* Full report for one run: the reasoning first, then the scripts, then the data
   behind them — the same shape as the sheet this system was designed from. */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/engine.php';
require_once __DIR__ . '/lib/xlsx.php';
app_boot();
$u = Auth::requireLogin();

$runId = (int)($_GET['run'] ?? 0);
if (!$runId) { http_response_code(400); exit('Missing run id.'); }
$run = one("SELECT r.*, c.name, c.ig_handle, c.niche, c.language, c.voice_profile, c.competitors, c.user_id
            FROM runs r JOIN customers c ON c.id=r.customer_id WHERE r.id=?", [$runId]);
/* Owner or admin only. A wrong id and a foreign id look identical from outside. */
if (!$run || ($u['role'] !== 'admin' && (int)$run['user_id'] !== (int)$u['id'])) { http_response_code(404); exit('No such run.'); }

$f       = json_decode((string)$run['findings'], true) ?: [];
$reels   = all("SELECT * FROM reels WHERE run_id=? ORDER BY velocity DESC", [$runId]);
$scripts = all("SELECT * FROM scripts WHERE run_id=? ORDER BY idx", [$runId]);
$pct     = fn($x) => $x === null || $x === '' ? '' : round((float)$x * 100) . '%';

/* ── Findings ─────────────────────────────────────────────────────────── */
$F = [['Section', 'Detail']];
$F[] = ['REPORT', $run['name'] . '  (@' . $run['ig_handle'] . ')  ·  run #' . $run['id']
        . '  ·  ' . substr((string)$run['started_at'], 0, 16) . ' UTC'];
$F[] = ['Niche', $run['niche']];
$F[] = ['Script language', $run['language']];
$F[] = ['', ''];
$F[] = ['WHAT WAS ANALYSED', ''];
$F[] = ['Reels scored', (int)$run['posts_scored'] . ' across ' . count($f['accounts'] ?? []) . ' account(s)'];
$F[] = ['Too fresh to score', (int)$run['too_fresh'] . ' posted under 7 days ago — short-form keeps accumulating for one to two weeks'];
$F[] = ['Genuine outliers', (int)$run['outliers_found'] . ' at 1.5x their own account median or better'];
$F[] = ['False outliers excluded', (int)$run['false_outliers'] . ' beat the median on plays but sat far below that account\'s completion band'];
$F[] = ['Reels transcribed', count(array_filter($reels, fn($r) => !empty($r['hook'])))];
$F[] = ['Apify cost', '$' . number_format((float)$run['apify_cost_usd'], 3)];
if (!empty($f['failed'])) $F[] = ['Accounts that returned nothing', '@' . implode(', @', $f['failed'])];
$F[] = ['', ''];
$F[] = ['ACCOUNT BASELINES', ''];
foreach (($f['accounts'] ?? []) as $a) {
    $F[] = ['@' . $a['handle'] . (!empty($a['is_own']) ? '  (YOU)' : ''),
            $a['counted'] . ' reels · median ' . number_format((float)$a['median_plays']) . ' plays · '
            . (!empty($a['band']['weak']) ? 'completion band not established (too few reels)'
               : 'completion band ' . $pct($a['band']['lo']) . '–' . $pct($a['band']['hi']))];
}

if (trim((string)$run['narrative']) !== '') {
    $F[] = ['', ''];
    $F[] = ['THE ANALYSIS BEHIND THESE SCRIPTS', ''];
    foreach (preg_split("/\n\n+/", trim((string)$run['narrative'])) as $blk) {
        $lines = explode("\n", trim($blk), 2);
        $F[] = [trim($lines[0]), trim($lines[1] ?? '')];
    }
}
if (trim((string)$run['voice_profile']) !== '') {
    $F[] = ['', ''];
    $F[] = ['YOUR VOICE PROFILE', 'Built from transcripts of your own reels. Every script was written to this.'];
    foreach (explode("\n", trim((string)$run['voice_profile'])) as $line) {
        $p = explode(':', $line, 2);
        $F[] = [trim($p[0]), trim($p[1] ?? '')];
    }
} else {
    $F[] = ['', ''];
    $F[] = ['YOUR VOICE PROFILE', 'NOT BUILT this run — too few of your own reels had usable transcripts. '
            . 'The scripts fall back to plain writing in the stated language. Post more, or add reels with clear speech.'];
}
if (!empty($f['mined']['repeated'])) {
    $F[] = ['', ''];
    $F[] = ['QUESTIONS THE AUDIENCE ASKS REPEATEDLY', 'Each one is a video topic with demand already proven in the comments.'];
    foreach ($f['mined']['repeated'] as $r) $F[] = ['asked ' . $r['count'] . 'x', $r['example']];
}
if (!empty($f['mined']['objections'])) {
    $F[] = ['', ''];
    $F[] = ['OBJECTIONS IN COMMENTS', 'A rebuttal to a real objection is one of the strongest hooks available.'];
    foreach (array_slice($f['mined']['objections'], 0, 10) as $o) $F[] = ['', $o['text']];
}
if (!empty($f['mined']['vocab'])) {
    $F[] = ['', ''];
    $F[] = ['AUDIENCE VOCABULARY', implode(', ', array_map(fn($v) => $v['word'] . ' (' . $v['n'] . ')', $f['mined']['vocab']))];
}
$F[] = ['', ''];
$F[] = ['HOW TO READ THE REELS TAB', 'Sorted by plays per day, not lifetime plays — a four-month-old viral reel is not what is working now.'];
$F[] = ['Flag: Outlier', 'Genuinely beat its own account median.'];
$F[] = ['Flag: False', 'High plays but low completion. Boosted spend or a scroll trap. Do not copy these.'];
$F[] = ['Flag: High ret.', 'Completion well above that account\'s band. Often the best content on the account even when plays look ordinary.'];

/* ── Scripts ──────────────────────────────────────────────────────────── */
$S = [['#','Sub-niche','Concept','Hook Pattern','Hook Line','Full Script','Duration','Caption (English)','CTA Keyword','Where This Came From','Status']];
foreach ($scripts as $s) {
    $S[] = [(int)$s['idx'], $s['niche'], $s['concept'], $s['hook_pattern'], $s['hook_line'],
            $s['body'], $s['duration_label'], $s['caption'], $s['cta_keyword'], $s['source_insight'], $s['status']];
}
if (count($S) === 1) $S[] = ['', 'No scripts were generated for this run.', '', '', '', '', '', '', '', '', ''];

/* ── Reels ────────────────────────────────────────────────────────────── */
$R = [['Account','Yours','Plays','Plays/day','Age (days)','Completion','vs own median','Likes','Comments','Duration (s)','Flag','Outlier','Verbatim Hook','URL']];
foreach ($reels as $r) {
    $R[] = [$r['handle'], $r['is_own'] ? 'YES' : '', (int)$r['plays'], (int)round((float)$r['velocity']),
            (int)round((float)$r['age_days']), $pct($r['ratio']),
            number_format((float)$r['outlier_score'], 2) . 'x', (int)$r['likes'], (int)$r['comment_count'],
            (int)round((float)$r['duration_s']), $r['flag'], $r['is_outlier'] ? 'yes' : '',
            (string)$r['hook'], $r['url']];
}

/* ── Hook library ─────────────────────────────────────────────────────── */
$withHooks = array_values(array_filter($reels, fn($r) => !empty($r['hook'])));
$H = [['Pattern','Reels','Accounts','Median plays/day','Signal?','Example Hook','From','Plays/day']];
if ($withHooks) {
    $cl = Engine::clusterHooks(array_map(fn($r) => [
        'handle' => $r['handle'], 'hook3s' => $r['hook'], 'velocity' => (float)$r['velocity'],
        'plays' => (int)$r['plays'], 'url' => $r['url']], $withHooks));
    foreach ($cl as $c) {
        $first = true;
        foreach ($c['examples'] as $x) {
            $H[] = [$first ? $c['pattern'] : '', $first ? $c['count'] : '', $first ? $c['accounts'] : '',
                    $first ? (int)round($c['med_velocity']) : '',
                    $first ? ($c['signal'] ? 'SIGNAL' : 'not enough yet') : '',
                    $x['hook'], '@' . $x['handle'], (int)round($x['velocity'])];
            $first = false;
        }
    }
} else {
    $H[] = ['No hooks were captured this run.', '', '', '', '', '', '', ''];
}

$x = new Xlsx();
$x->sheet('Findings',   $F, [34, 118], [0, 1]);
$x->sheet('Scripts',    $S, [5, 30, 26, 18, 46, 90, 10, 48, 13, 46, 10], [1,2,4,5,7,9]);
$x->sheet('Reels',      $R, [22, 7, 11, 11, 11, 12, 14, 9, 10, 12, 13, 9, 70, 46], [12]);
$x->sheet('Hook Library', $H, [18, 8, 10, 17, 15, 78, 20, 11], [5]);

$slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($run['ig_handle'] ?: $run['name']));
$name = "outlier-$slug-run$runId-" . date('Y-m-d') . '.xlsx';
$tmp  = sys_get_temp_dir() . '/' . uniqid('outlier', true) . '.xlsx';
$x->save($tmp);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
readfile($tmp);
@unlink($tmp);
