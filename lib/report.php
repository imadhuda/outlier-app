<?php
/* One run, rendered. Shared by the customer dashboard and the admin panel so
   both see exactly the same analysis. Access is checked by the caller. */
require_once __DIR__ . '/ui.php';

class Report {
    public static function render(array $run, $admin = false) {
        $f = json_decode((string)$run['findings'], true) ?: [];
        $runId = (int)$run['id'];

        if ($run['kind'] === 'trial_check') {
            echo '<h1>Posting check — ' . e(substr((string)$run['started_at'], 0, 10)) . '</h1>';
            echo '<div class="sub">' . statusChip($run['status']) . '</div>';
            if ($run['error']) echo '<div class="msg m-warn">' . e($run['error']) . '</div>';
            echo '<div class="tiles"><div class="tile"><div class="k">Videos in the last 7 days</div><div class="v">'
               . (int)$run['posts_scored'] . ' / ' . Billing::TRIAL_MIN_POSTS . '</div></div></div>';
            return;
        }

        echo '<h1>Batch #' . $runId . ($admin ? ' — ' . e($run['name']) : '') . '</h1>';
        echo '<div class="sub">' . e(substr((string)$run['started_at'], 0, 16)) . ' UTC · ' . statusChip($run['status']) . '</div>';
        if ($run['error']) echo '<div class="msg m-bad">' . e($run['error']) . '</div>';

        $tiles = [['Reels analysed', n($run['posts_scored'])], ['Too fresh', n($run['too_fresh'])],
                  ['Outliers', n($run['outliers_found'])], ['False outliers', n($run['false_outliers'])],
                  ['Scripts', n($run['scripts_delivered'])]];
        if ($admin) $tiles[] = ['Apify cost', '$' . number_format((float)$run['apify_cost_usd'], 3)];
        echo '<div class="tiles">';
        foreach ($tiles as $t) echo '<div class="tile"><div class="k">' . e($t[0]) . '</div><div class="v">' . e($t[1]) . '</div></div>';
        echo '</div>';

        if ((int)$run['scripts_delivered'] > 0) {
            echo '<div style="margin-bottom:18px"><a class="btn" href="export.php?run=' . $runId . '">Download full report (Excel)</a>'
               . '<span style="color:var(--dim);font-size:12.5px;margin-left:12px">Findings, Scripts, Reels and Hook Library</span></div>';
        }

        if (trim((string)$run['narrative']) !== '') {
            echo '<div class="card"><h2>The analysis behind these scripts</h2>';
            foreach (preg_split("/\n\n+/", trim((string)$run['narrative'])) as $blk) {
                $lines = explode("\n", trim($blk), 2);
                echo '<div style="margin-bottom:16px"><div style="font-weight:670;font-size:14px;margin-bottom:4px">'
                   . e(trim($lines[0])) . '</div><div style="color:var(--mute);font-size:13.5px;line-height:1.6">'
                   . e(trim($lines[1] ?? '')) . '</div></div>';
            }
            echo '</div>';
        }

        $scripts = all("SELECT * FROM scripts WHERE run_id=? ORDER BY idx", [$runId]);
        echo '<div class="card"><h2>Your scripts</h2>';
        if (!$scripts) echo '<div class="empty">' . ($run['status'] === 'done' ? 'None generated.' : 'Still working on this batch.') . '</div>';
        foreach ($scripts as $s) {
            echo '<div style="border-left:2px solid var(--line);padding:2px 0 2px 16px;margin-bottom:22px">'
               . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--mute)">'
               . (int)$s['idx'] . ' · ' . e($s['niche']) . ' &middot; ' . e($s['hook_pattern']) . ' &middot; ' . e($s['duration_label']) . '</div>'
               . '<div style="font-weight:700;margin:5px 0 8px">' . e($s['hook_line']) . '</div>'
               . '<div style="white-space:pre-wrap;font-size:14px;color:#D6D6DC">' . e($s['body']) . '</div>'
               . ($s['caption'] ? '<div style="font-size:12.5px;color:var(--mute);margin-top:8px"><b>Caption:</b> ' . e($s['caption']) . '</div>' : '')
               . '<div style="font-size:12px;color:var(--dim);margin-top:8px">CTA <b style="color:var(--orange)">'
               . e($s['cta_keyword']) . '</b> &middot; ' . e($s['source_insight']) . '</div></div>';
        }
        echo '</div>';

        if (trim((string)$run['voice_profile']) !== '') {
            echo '<div class="card"><h2>Your voice profile</h2>'
               . '<div class="sub" style="margin-bottom:14px">Built from transcripts of your own reels. Every script was written to this.</div><table>';
            foreach (explode("\n", trim((string)$run['voice_profile'])) as $line) {
                $p2 = explode(':', $line, 2);
                echo '<tr><td style="width:180px;font-weight:600;font-size:12.5px">' . e(trim($p2[0]))
                   . '</td><td style="font-size:13.5px;color:var(--mute)">' . e(trim($p2[1] ?? '')) . '</td></tr>';
            }
            echo '</table></div>';
        } elseif ($run['status'] === 'done') {
            echo '<div class="msg m-warn"><b>No voice profile yet.</b> Too few of your own reels had clear speech to transcribe, '
               . 'so these scripts use plain writing in your language. Post reels where you talk to camera and the next batch will sound like you.</div>';
        }

        if (!empty($f['mined']['repeated'])) {
            echo '<div class="card"><h2>Questions your audience keeps asking</h2><table>';
            foreach ($f['mined']['repeated'] as $r)
                echo '<tr><td class="num" style="width:50px">' . (int)$r['count'] . 'x</td><td>' . e($r['example']) . '</td></tr>';
            echo '</table></div>';
        }

        $reels = all("SELECT * FROM reels WHERE run_id=? ORDER BY velocity DESC LIMIT 60", [$runId]);
        if ($reels) {
            echo '<div class="card"><h2>Reels — ranked by plays per day</h2><div style="overflow-x:auto"><table>'
               . '<tr><th>Account</th><th class="num">Plays</th><th class="num">/day</th><th class="num">Age</th>'
               . '<th class="num">Completion</th><th class="num">vs median</th><th>Flag</th><th>Hook</th></tr>';
            foreach ($reels as $r) {
                $chip = $r['flag']==='false' ? '<span class="chip c-bad">False</span>'
                      : ($r['flag']==='high-retention' ? '<span class="chip c-org">High ret.</span>'
                      : ($r['is_outlier'] ? '<span class="chip c-ok">Outlier</span>' : '<span class="chip c-dim">Normal</span>'));
                echo '<tr><td>' . ($r['is_own'] ? '<b>' : '') . '@' . e($r['handle']) . ($r['is_own'] ? '</b>' : '')
                   . '</td><td class="num"><a href="' . e($r['url']) . '" target="_blank" rel="noopener noreferrer">' . n($r['plays']) . '</a></td>'
                   . '<td class="num">' . n($r['velocity']) . '</td><td class="num">' . n($r['age_days']) . 'd</td>'
                   . '<td class="num">' . pc($r['ratio']) . '</td><td class="num">' . number_format((float)$r['outlier_score'],2) . 'x</td>'
                   . '<td>' . $chip . '</td><td style="font-size:13px;max-width:380px">' . e($r['hook'] ?: '—') . '</td></tr>';
            }
            echo '</table></div></div>';
        }
        if ($admin && !empty($f['failed'])) echo '<div class="msg m-bad">Accounts that returned nothing: @' . e(implode(', @', $f['failed'])) . '</div>';
    }

    /** Load a run with its customer, or null. */
    public static function load($runId) {
        return one("SELECT r.*, c.name, c.ig_handle, c.voice_profile, c.user_id
                    FROM runs r JOIN customers c ON c.id=r.customer_id WHERE r.id=?", [(int)$runId]);
    }
}
