<?php
/* The Script Writer — the creator types a topic, we look for real videos on it
   in their scraped corpus, and write one script in their voice. Never blind:
   if nothing exists on the topic, we ask for their research first. */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/lib/writer.php';
require_once __DIR__ . '/lib/ui.php';
app_boot();
$u = Auth::requireLogin();
$c = one("SELECT * FROM customers WHERE user_id=?", [$u['id']]);
if (!$c || !$c['onboarded'] || !$c['ig_verified']) { header('Location: onboard.php'); exit; }

$msg = null; $err = null;
$topic = trim((string)($_POST['topic'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$stage = 'topic';          // topic -> review -> result
$found = null; $script = null;

try {
    $act = $_POST['action'] ?? '';

    if ($act === 'search') {
        if (mb_strlen($topic) < 4) throw new RuntimeException('Give me a topic — a line or two about what the video should cover.');
        $found = Writer::search($c['id'], $topic);
        $stage = 'review';
    }

    if ($act === 'generate') {
        if (mb_strlen($topic) < 4) throw new RuntimeException('The topic went missing. Start again.');
        $a = Billing::allowance($u['id']);
        if ($a['n'] === 0) throw new RuntimeException($a['why'] . ' Choose a plan to keep writing.');
        if (!Auth::throttle('writer', Writer::PER_DAY)) throw new RuntimeException('You have hit today\'s limit of ' . Writer::PER_DAY . ' scripts. Try again tomorrow.');
        $found = Writer::search($c['id'], $topic);
        if (!$found['refs'] && mb_strlen($notes) < 15)
            throw new RuntimeException('No existing video was found on this topic, so I need something to work from. Add a few lines of your own research or the key points, then generate.');
        $out = Writer::generate($c, $topic, $found['refs'], $notes, $found['style']);
        if ($u['role'] !== 'admin') Billing::consume($u['id'], 1);
        $script = $out['script'];
        $stage = 'result';
        $msg = 'Here is your script. It has been saved below and counts as one script from your plan.';
    }
} catch (Throwable $e) { $err = $e->getMessage(); if ($stage === 'topic' && $topic !== '') $stage = 'review'; }

head('Write a script', customerNav($u));
echo '<h1>Write a script</h1>';
echo '<div class="sub">Tell me the topic. I look for real videos on it — yours and your competitors\' — and write one script in your voice, in ' . e($c['language']) . ', at ' . e($c['duration_pref']) . '. It counts as one script from your plan.</div>';
flash($msg, $err);

$a = Billing::allowance($u['id']);
if ($a['n'] === 0 && $u['role'] !== 'admin') {
    echo '<div class="msg m-warn">' . e($a['why']) . ' <a href="plan.php">Choose a plan</a> to use the Script Writer.</div>';
}

/* ── the box ───────────────────────────────────────────────────────────── */
echo '<div class="card"><form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="search">'
   . '<label>I want to write a script on…</label>'
   . '<textarea name="topic" rows="3" placeholder="e.g. Why off-plan property in Dubai is riskier than people think" required>' . e($topic) . '</textarea>'
   . '<div style="margin-top:12px"><button class="btn">Find references &amp; continue &rarr;</button></div></form></div>';

/* ── review: what we found (or didn't) ─────────────────────────────────── */
if ($stage === 'review' && $found !== null) {
    if ($found['refs']) {
        echo '<div class="card"><h2>Found ' . count($found['refs']) . ' video' . (count($found['refs']) === 1 ? '' : 's') . ' on this topic</h2>'
           . '<div class="sub">I\'ll use these for the angle and the hooks that already work — not copy them.</div><table>'
           . '<tr><th>Account</th><th class="num">Plays/day</th><th>Hook</th></tr>';
        foreach ($found['refs'] as $r) {
            echo '<tr><td>' . ($r['is_own'] ? '<b>' : '') . '@' . e($r['handle']) . ($r['is_own'] ? '</b> (you)' : '') . '</td>'
               . '<td class="num">' . n($r['velocity']) . '</td>'
               . '<td style="font-size:13px;max-width:420px">' . e($r['hook'] ?: '—')
               . ($r['url'] ? ' <a href="' . e($r['url']) . '" target="_blank" rel="noopener noreferrer">↗</a>' : '') . '</td></tr>';
        }
        echo '</table>'
           . '<form method="post" style="margin-top:16px">' . Auth::csrfField() . '<input type="hidden" name="action" value="generate"><input type="hidden" name="topic" value="' . e($topic) . '">'
           . '<label>Anything to add? (optional — your own angle, a number, a story)</label>'
           . '<textarea name="notes" rows="2" placeholder="Optional">' . e($notes) . '</textarea>'
           . '<div style="margin-top:12px"><button class="btn">Write my script &rarr;</button></div></form></div>';
    } else {
        echo '<div class="card"><h2>No existing video found on this exact topic</h2>'
           . '<div class="sub">Nobody in your corpus has covered this yet — so I won\'t make up facts. Give me the key points or your research, and I\'ll write it in your voice using the style of your best-performing reels.</div>'
           . '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="generate"><input type="hidden" name="topic" value="' . e($topic) . '">'
           . '<label>Your research / key points <span style="color:var(--dim);text-transform:none">(required for a new topic)</span></label>'
           . '<textarea name="notes" rows="5" placeholder="e.g. New DLD rule from Sept 2026: ... ; the 3 numbers that matter are ... ; my take is ..." required>' . e($notes) . '</textarea>'
           . '<div style="margin-top:12px"><button class="btn">Write my script &rarr;</button></div></form>';
        if ($found['style']) {
            echo '<div class="sub" style="margin-top:16px">Style will match your top reels: '
               . e(implode(', ', array_map(fn($r) => '@' . $r['handle'], $found['style']))) . '.</div>';
        }
        echo '</div>';
    }
}

/* ── result ────────────────────────────────────────────────────────────── */
if ($stage === 'result' && $script) {
    echo '<div class="card"><h2>Your script</h2>'
       . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--mute)">'
       . e($script['hook_pattern'] ?? '') . ' &middot; ' . e($script['duration_label'] ?? '') . '</div>'
       . '<div style="font-weight:700;margin:6px 0 10px;font-size:16px">' . e($script['hook_line'] ?? '') . '</div>'
       . '<div style="white-space:pre-wrap;font-size:14.5px;line-height:1.6;color:#D6D6DC">' . e($script['body'] ?? '') . '</div>'
       . ($script['caption'] ?? '' ? '<div style="font-size:12.5px;color:var(--mute);margin-top:12px"><b>Caption:</b> ' . e($script['caption']) . '</div>' : '')
       . '<div style="font-size:12px;color:var(--dim);margin-top:8px">CTA <b style="color:var(--orange)">' . e($script['cta_keyword'] ?? '') . '</b> &middot; ' . e($script['source_insight'] ?? '') . '</div>'
       . '</div>';
}

/* ── history ───────────────────────────────────────────────────────────── */
$past = all("SELECT * FROM writer_requests WHERE customer_id=? ORDER BY id DESC LIMIT 15", [$c['id']]);
if ($past) {
    echo '<div class="card"><h2>Scripts you have written here</h2>';
    foreach ($past as $w) {
        echo '<details style="border-bottom:1px solid #202024;padding:10px 0">'
           . '<summary style="cursor:pointer"><b>' . e($w['topic']) . '</b> <span style="color:var(--dim);font-size:12px">· ' . e(substr($w['created_at'], 0, 10)) . ($w['had_reference'] ? ' · referenced' : ' · from your notes') . '</span></summary>'
           . '<div style="padding:10px 0 4px"><div style="font-weight:700;margin-bottom:6px">' . e($w['hook_line']) . '</div>'
           . '<div style="white-space:pre-wrap;font-size:13.5px;color:#C6C6CC">' . e($w['body']) . '</div>'
           . ($w['caption'] ? '<div style="font-size:12px;color:var(--mute);margin-top:8px"><b>Caption:</b> ' . e($w['caption']) . '</div>' : '')
           . '<div style="font-size:12px;color:var(--dim);margin-top:6px">CTA ' . e($w['cta_keyword']) . '</div></div></details>';
    }
    echo '</div>';
}
foot();
