<?php
/* Customer dashboard. Every query is scoped to the logged-in user's own
   customer row — there is no way to address someone else's data from here. */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/lib/scheduler.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/writer.php';
app_boot();
$u = Auth::requireLogin();
$c = one("SELECT * FROM customers WHERE user_id=?", [$u['id']]);
if (!$c || !$c['onboarded'] || !$c['ig_verified']) { header('Location: onboard.php'); exit; }
$msg = null; $err = null;

try {
    $act = $_POST['action'] ?? '';
    if ($act === 'run') {
        $r = Scheduler::start($c, true);
        $msg = $r['kind'] === 'trial_check'
            ? 'We are checking your recent posts now. This takes a few minutes — refresh shortly.'
            : 'Your analysis has started. Scripts usually land within 15–30 minutes; we will email you.';
    }
    if ($act === 'toggle_auto') {
        q("UPDATE customers SET auto_run=1-auto_run WHERE id=?", [$c['id']]);
        $c = one("SELECT * FROM customers WHERE id=?", [$c['id']]);
        $msg = $c['auto_run'] ? 'Weekly runs resumed.' : 'Weekly runs paused. Nothing will be scraped until you resume.';
    }
    if ($act === 'stories') {
        if (!Auth::throttle('stories', 5)) throw new RuntimeException('Just a moment — try that again in a few minutes.');
        $ideas = Writer::storyIdeas($c, true);
        $msg = $ideas ? 'Story ideas ready for today.' : 'I need a completed analysis first — run one, then come back for story ideas.';
    }
} catch (Throwable $e) { $err = $e->getMessage(); }

/* ── run detail ─────────────────────────────────────────────────────────── */
if (isset($_GET['run'])) {
    $run = Report::load((int)$_GET['run']);
    if (!$run || (int)$run['customer_id'] !== (int)$c['id']) { http_response_code(404); head('Not found', customerNav($u)); echo '<div class="empty">No such batch.</div>'; foot(); exit; }
    head('Batch', customerNav($u));
    echo '<div style="margin-bottom:14px"><a href="app.php">&larr; All batches</a></div>';
    Report::render($run, false);
    foot(); exit;
}

/* ── dashboard ──────────────────────────────────────────────────────────── */
$a = Billing::allowance($u['id']);
$sub = $a['sub'] ?? Billing::current($u['id']);
$blocker = Scheduler::blocker($c, true);
$inFlight = one("SELECT id, kind FROM runs WHERE customer_id=? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1", [$c['id']]);
$recent = Billing::recentOwnPosts($c['id']);
$isTrial = $sub && $sub['status'] === 'trial';
$hasScripts = (int)(one("SELECT COUNT(*) n FROM scripts WHERE customer_id=?", [$c['id']])['n'] ?? 0) > 0;
$isAdmin = $u['role'] === 'admin';

head('Dashboard', customerNav($u));
echo '<h1>Hi ' . e($u['name'] ?: '@' . $c['ig_handle']) . '</h1>';
echo '<div class="sub">@' . e($c['ig_handle']) . ' &middot; ' . e($c['niche']) . ' &middot; ' . e($c['language']) . ' &middot; ' . e($c['duration_pref']) . ' scripts</div>';
flash($msg, $err);

/* ── the journey: where the customer is right now ───────────────────────── */
$stage = 'verified';                                  // 1 done: account live
if ($isTrial && $recent >= Billing::TRIAL_MIN_POSTS) $stage = 'ready';
elseif ($isTrial && !$hasScripts)                    $stage = 'posting';
if ($hasScripts)                                     $stage = 'delivered';
if ($inFlight)                                       $stage = 'running';

echo '<div class="tiles">';
$planLabel = $isAdmin ? 'Admin' : ($sub ? $sub['plan_name'] : 'None');
echo '<div class="tile"><div class="k">Plan</div><div class="v" style="font-size:18px">' . e($planLabel) . '</div>'
   . ($sub ? '<div style="margin-top:6px">' . statusChip($sub['status']) . '</div>' : '') . '</div>';
$left = $a['n'] === null ? '&#8734;' : (int)$a['n'];
echo '<div class="tile"><div class="k">Scripts left this month</div><div class="v">' . $left . '</div>'
   . ($sub && $sub['scripts_month'] !== null ? '<div style="font-size:12px;color:var(--dim)">of ' . (int)$sub['scripts_month'] . '</div>' : '') . '</div>';
if ($isTrial) {
    echo '<div class="tile"><div class="k">Trial: videos in last 7 days</div><div class="v">' . $recent . ' / ' . Billing::TRIAL_MIN_POSTS . '</div>'
       . '<div class="prog"><span style="width:' . min(100, round($recent / Billing::TRIAL_MIN_POSTS * 100)) . '%"></span></div></div>';
}
echo '<div class="tile"><div class="k">Weekly runs</div><div class="v" style="font-size:18px">' . ($c['auto_run'] ? 'On' : 'Paused') . '</div>'
   . '<div style="font-size:12px;color:var(--dim)">every ' . (int)$c['run_every_days'] . ' days</div></div>';
echo '</div>';

/* ── the action card — reads differently at each stage ──────────────────── */
echo '<div class="card"><div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">';
if ($inFlight) {
    echo '<span class="bignum" style="font-size:20px">' . ($inFlight['kind'] === 'trial_check' ? 'Checking your posts…' : 'Analysis running…') . '</span>'
       . '<span style="color:var(--mute);font-size:13.5px">This runs in the background. Refresh in a few minutes — we will email you when it is ready.</span>';
} elseif ($isTrial && $recent < Billing::TRIAL_MIN_POSTS) {
    $need = Billing::TRIAL_MIN_POSTS - $recent;
    echo '<div><div class="bignum">' . $recent . ' / ' . Billing::TRIAL_MIN_POSTS . '</div><div style="font-size:12.5px;color:var(--mute);margin-top:4px">Post ' . $need . ' more video' . ($need === 1 ? '' : 's') . ' to unlock your first scripts.</div></div>'
       . '<div class="sp"></div>';
    if (!$blocker) echo '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="run"><button class="btn2">Check my posts now</button></form>';
} elseif ($blocker) {
    echo '<span style="color:var(--mute);font-size:14px">' . e($blocker) . '</span><div class="sp"></div>';
    if ($a['n'] === 0 && !$isAdmin) echo '<a class="btn" href="plan.php">Choose a plan</a>';
} else {
    echo '<div><div style="font-weight:650;font-size:15px;margin-bottom:2px">' . (Scheduler::needsTrialCheck($c) ? 'Ready to check your posts' : 'Ready to run your analysis') . '</div>'
       . '<div style="font-size:12.5px;color:var(--mute)">Runs happen automatically every ' . (int)$c['run_every_days'] . ' days. This just starts one now.</div></div><div class="sp"></div>'
       . '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="run"><button class="btn">'
       . (Scheduler::needsTrialCheck($c) ? 'Check my posts now' : 'Run analysis now') . '</button></form>';
}
echo '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="toggle_auto"><button class="btn2">'
   . ($c['auto_run'] ? 'Pause weekly' : 'Resume weekly') . '</button></form>';
echo '</div></div>';

/* ── how Outlier works — a live journey tracker ─────────────────────────── */
$steps = [
    ['verified',  'Account set up &amp; Instagram verified', '@' . e($c['ig_handle']) . ' is connected and your competitors are locked in.'],
    ['posting',   'Post ' . Billing::TRIAL_MIN_POSTS . ' videos in 7 days', $isTrial ? 'You are at ' . $recent . ' of ' . Billing::TRIAL_MIN_POSTS . '. Keep posting on @' . e($c['ig_handle']) . '.' : 'On a paid plan this step is automatic.'],
    ['running',   'We analyse you vs your competitors', 'Plays-per-day, retention, the false outliers thrown out, the hooks that actually work, and what your comments keep asking.'],
    ['delivered', 'Your scripts land', 'Written to your voice, in ' . e($c['language']) . ', at ' . e($c['duration_pref']) . '. Download the Excel report or read them below.'],
];
$order = ['verified'=>1,'posting'=>2,'running'=>3,'delivered'=>4];
$cur = $order[$stage] ?? 1;
echo '<div class="card"><h2>How it works — where you are now</h2><div class="guide">';
foreach ($steps as $i => $st) {
    $k = $i + 1;
    $cls = $k < $cur ? 'done' : ($k === $cur ? 'on' : '');
    echo '<div class="g ' . $cls . '"><div class="gn">' . ($k < $cur ? '&#10003;' : $k) . '</div>'
       . '<div><div class="gt">' . $st[1] . '</div><div class="gd">' . $st[2] . '</div></div></div>';
}
echo '</div></div>';

/* ── write a script CTA ─────────────────────────────────────────────────── */
echo '<div class="card" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">'
   . '<div style="flex:1;min-width:240px"><h2 style="margin-bottom:4px">Need a script right now?</h2>'
   . '<div style="color:var(--mute);font-size:13.5px">Give the Script Writer a topic. It finds real videos on it — yours and your competitors\' — and writes one in your voice. Counts as one script from your plan.</div></div>'
   . '<a class="btn" href="write.php">Write a script &rarr;</a></div>';

/* ── story ideas for today ──────────────────────────────────────────────── */
$ideas = Writer::storyIdeas($c);
echo '<div class="card"><div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><h2 style="margin:0">Story ideas for today</h2><div class="sp"></div>'
   . '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="stories"><button class="btn2">' . ($ideas ? 'Refresh' : 'Get today\'s ideas') . '</button></form></div>';
if ($ideas) {
    echo '<div class="sub" style="margin:10px 0 0">Quick Stories to post today — built from what your audience keeps asking and the hooks working now. (We suggest ideas; we don\'t scrape anyone\'s stories.)</div><table style="margin-top:10px">';
    foreach ($ideas as $i) {
        echo '<tr><td style="width:130px"><span class="chip c-org">' . e($i['format'] ?? 'story') . '</span></td>'
           . '<td><div style="font-weight:600">' . e($i['prompt'] ?? '') . '</div>'
           . ($i['why'] ?? '' ? '<div style="font-size:12px;color:var(--dim);margin-top:3px">' . e($i['why']) . '</div>' : '') . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<div class="sub" style="margin-top:10px">Run at least one analysis, then press the button and I\'ll suggest quick Stories for the day.</div>';
}
echo '</div>';

/* ── audience questions (best-effort from your own posts' comments) ─────── */
$leads = Writer::leads($c['id'], 12);
if ($leads) {
    echo '<div class="card"><h2>Questions from your audience</h2>'
       . '<div class="sub">Comments on your own posts that look like questions — reply to them, or turn one into your next video. '
       . '<span style="color:var(--dim)">(Best-effort: we can\'t always tell if you already replied.)</span></div><table style="margin-top:8px">';
    foreach ($leads as $l) echo '<tr><td>' . e($l['text']) . '</td><td style="width:150px;text-align:right"><a class="btn2" href="write.php">Write a reply video</a></td></tr>';
    echo '</table></div>';
}

if ($isTrial && !$hasScripts) {
    echo '<div class="msg m-warn" style="display:block">During your free trial you get <b>' . Billing::TRIAL_MIN_POSTS . ' scripts</b> once you have posted '
       . Billing::TRIAL_MIN_POSTS . ' videos in 7 days. After that, a plan gives you a fresh batch every week.</div>';
}

$runs = all("SELECT * FROM runs WHERE customer_id=? ORDER BY id DESC LIMIT 30", [$c['id']]);
echo '<div class="card"><h2>Your batches</h2>';
if (!$runs) echo '<div class="empty">Nothing yet — your first analysis will appear here.</div>'; else {
    echo '<div style="overflow-x:auto"><table><tr><th>Date</th><th>Type</th><th>Status</th><th class="num">Reels</th><th class="num">Scripts</th><th></th></tr>';
    foreach ($runs as $r) {
        echo '<tr><td>' . e(substr($r['started_at'], 0, 16)) . '</td><td>' . ($r['kind'] === 'trial_check' ? 'Posting check' : 'Analysis') . '</td>'
           . '<td>' . statusChip($r['status']) . ($r['status'] === 'skipped' && $r['error'] ? '<div style="font-size:12px;color:var(--mute);max-width:420px">' . e($r['error']) . '</div>' : '') . '</td>'
           . '<td class="num">' . n($r['posts_scored']) . '</td><td class="num">' . n($r['scripts_delivered']) . '</td>'
           . '<td><a class="btn2" href="app.php?run=' . (int)$r['id'] . '">Open</a>'
           . ((int)$r['scripts_delivered'] > 0 ? ' <a class="btn2" href="export.php?run=' . (int)$r['id'] . '">Excel</a>' : '') . '</td></tr>';
    }
    echo '</table></div>';
}
echo '</div>';
foot();
