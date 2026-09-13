<?php
/* Admin panel. Session-authenticated, admin role only. No secrets in URLs. */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/lib/scheduler.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/mail.php';
app_boot();
$admin = Auth::requireAdmin();
$cfg = outlier_config();
$msg = null; $err = null; $shownPassword = null;

function cleanHandle($s) {
    $s = trim((string)$s);
    $s = preg_replace('#^https?://(www\.)?instagram\.com/#i', '', $s);
    return strtolower(trim(preg_replace('/[?#].*$/', '', $s), "@/ \t"));
}
function userById($id) { return one("SELECT * FROM users WHERE id=?", [(int)$id]); }

/* ── actions ────────────────────────────────────────────────────────────── */
try {
    $act = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);
    $cid = (int)($_POST['customer_id'] ?? 0);

    if ($act === 'run' && $cid) {
        $c = one("SELECT * FROM customers WHERE id=?", [$cid]);
        if (!$c) throw new RuntimeException('No such customer.');
        if (!empty($_POST['force'])) { $rid = Pipeline::beginRun($cid); $msg = "Full run #$rid queued (forced, trial gate and quota skipped)."; }
        else { $r = Scheduler::start($c, true); $msg = ucfirst(str_replace('_', ' ', $r['kind'])) . " #{$r['run']} queued."; }
    }
    if ($act === 'worker') {
        define('OUTLIER_ADMIN_TICK', 1);
        ob_start(); require __DIR__ . '/worker.php'; $out = trim(ob_get_clean());
        $msg = 'Worker tick done. See the log below.';
    }
    if ($act === 'delete_customer' && $cid) {
        q("DELETE FROM customers WHERE id=?", [$cid]);
        $msg = 'Customer deleted. Their handle stays in the trial ledger.';
    }
    if ($act === 'trust_ig' && $cid) {
        $c = one("SELECT * FROM customers WHERE id=?", [$cid]);
        if (!$c) throw new RuntimeException('No such customer.');
        q("UPDATE customers SET ig_verified=1, onboarded=1, onboarded_at=COALESCE(onboarded_at, UTC_TIMESTAMP()) WHERE id=?", [$cid]);
        q("INSERT INTO handle_ledger (platform,handle,customer_id) VALUES ('instagram',?,?) ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id)", [$c['ig_handle'], $cid]);
        $msg = '@' . $c['ig_handle'] . ' marked verified by you.';
    }
    if ($act === 'link_customer' && $cid) {
        $u2 = one("SELECT * FROM users WHERE email=?", [strtolower(trim((string)$_POST['email']))]);
        if (!$u2) throw new RuntimeException('No login with that email.');
        if (one("SELECT id FROM customers WHERE user_id=? AND id<>?", [$u2['id'], $cid])) throw new RuntimeException('That login already has a customer profile.');
        q("UPDATE customers SET user_id=? WHERE id=?", [$u2['id'], $cid]);
        Billing::startTrial($u2['id']);
        $msg = 'Customer linked to ' . $u2['email'] . '.';
    }
    if ($act === 'activate' && $uid) {
        $price = Billing::activate($uid, preg_replace('/[^a-z0-9_]/', '', (string)$_POST['plan']), trim((string)($_POST['promo'] ?? '')) ?: null);
        $msg = 'Plan activated at $' . number_format($price, 2) . '/month.';
    }
    if ($act === 'renew' && $uid) { Billing::renew($uid); $msg = 'Month reopened: counters reset, status active.'; }
    if ($act === 'cancel_sub' && $uid) { Billing::cancel($uid); $msg = 'Subscription cancelled.'; }
    if ($act === 'reject_request' && !empty($_POST['request_id'])) {
        q("UPDATE plan_requests SET status='rejected' WHERE id=? AND status='pending'", [(int)$_POST['request_id']]); $msg = 'Request closed.';
    }
    if ($act === 'user_status' && $uid) {
        if ($uid === (int)$admin['id']) throw new RuntimeException('You cannot disable yourself.');
        $st = ($_POST['status'] ?? '') === 'disabled' ? 'disabled' : 'active';
        q("UPDATE users SET status=? WHERE id=?", [$st, $uid]);
        q("UPDATE customers SET status=? WHERE user_id=?", [$st === 'disabled' ? 'blocked' : 'trial', $uid]);
        $msg = 'User ' . $st . '.';
    }
    if ($act === 'user_role' && $uid) {
        if ($uid === (int)$admin['id']) throw new RuntimeException('Change your own role from another admin account.');
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'customer';
        q("UPDATE users SET role=? WHERE id=?", [$role, $uid]); $msg = 'Role set to ' . $role . '.';
    }
    if ($act === 'create_user') {
        $email = strtolower(trim((string)$_POST['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid email is required.');
        if (one("SELECT id FROM users WHERE email=?", [$email])) throw new RuntimeException('That email already has a login.');
        $pw = substr(strtr(base64_encode(random_bytes(12)), '+/', 'ab'), 0, 14) . '7';
        q("INSERT INTO users (email,password_hash,name,email_verified) VALUES (?,?,?,1)", [$email, Auth::hash($pw), mb_substr(trim((string)$_POST['name']), 0, 120)]);
        $newId = lastId();
        Billing::startTrial($newId);
        $ig = cleanHandle($_POST['ig_handle'] ?? '');
        if ($ig) {
            $comp = array_values(array_unique(array_filter(array_map('cleanHandle', preg_split('/[\s,]+/', (string)($_POST['competitors'] ?? ''))))));
            q("INSERT INTO customers (user_id,name,email,ig_handle,competitors,niche,language,duration_pref,status,trial_start,trial_end)
               VALUES (?,?,?,?,?,?,?,?,'trial',CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? DAY))",
              [$newId, trim((string)$_POST['name']) ?: $email, $email, $ig, json_encode($comp), trim((string)$_POST['niche']),
               in_array($_POST['language'] ?? '', ['English','Hinglish','Urdu','Arabic'], true) ? $_POST['language'] : 'English',
               in_array($_POST['duration_pref'] ?? '', ['30-40 sec','45-50 sec','60 sec'], true) ? $_POST['duration_pref'] : '30-40 sec',
               (int)($cfg['trial_days'] ?? 30)]);
        }
        $shownPassword = $pw;
        $msg = "Login created for $email. Give them this one-time password (shown once):";
    }
    if ($act === 'create_code') {
        $aff = trim((string)($_POST['affiliate_email'] ?? ''));
        $affId = null;
        if ($aff !== '') { $au = one("SELECT id FROM users WHERE email=?", [strtolower($aff)]); if (!$au) throw new RuntimeException('Affiliate email has no login.'); $affId = $au['id']; }
        $code = Billing::createCode($_POST['code'] ?? '', $_POST['kind'] ?? 'promo', $_POST['discount_pct'] ?? 0, $_POST['discount_usd'] ?? 0,
                                    $_POST['max_uses'] ?? '', trim((string)($_POST['expires_at'] ?? '')), $affId, $_POST['commission_pct'] ?? 0);
        $msg = "Code $code created.";
    }
    if ($act === 'toggle_code' && !empty($_POST['code_id'])) {
        q("UPDATE promo_codes SET active=1-active WHERE id=?", [(int)$_POST['code_id']]); $msg = 'Code updated.';
    }
} catch (Throwable $e) { $err = $e->getMessage(); }

$view = $_GET['view'] ?? 'customers';

/* ── run detail ─────────────────────────────────────────────────────────── */
if (isset($_GET['run'])) {
    $run = Report::load((int)$_GET['run']);
    head('Run', adminNav());
    if (!$run) { echo '<div class="empty">No such run.</div>'; foot(); exit; }
    echo '<div style="margin-bottom:14px"><a href="admin.php">← Customers</a></div>';
    Report::render($run, true);
    foot(); exit;
}

head('Admin', adminNav());
flash($msg, $err);
if ($shownPassword) echo '<div class="card"><div class="code">' . e($shownPassword) . '</div><div class="sub" style="margin:10px 0 0">They should change it after logging in (Settings → password).</div></div>';

/* ── codes view ─────────────────────────────────────────────────────────── */
if ($view === 'codes') {
    echo '<h1>Promo & affiliate codes</h1><div class="sub">A promo code discounts the customer. An affiliate code also records who referred them and what commission they earn.</div>';
    $codes = all("SELECT p.*, u.email aff_email FROM promo_codes p LEFT JOIN users u ON u.id=p.affiliate_user ORDER BY p.id DESC");
    echo '<div class="card"><h2>Codes</h2>';
    if (!$codes) echo '<div class="empty">None yet.</div>'; else {
        echo '<div style="overflow-x:auto"><table><tr><th>Code</th><th>Kind</th><th>Discount</th><th>Affiliate</th><th class="num">Uses</th><th>Expires</th><th>Active</th><th></th></tr>';
        foreach ($codes as $p) {
            $disc = ($p['discount_pct'] ? (int)$p['discount_pct'] . '%' : '') . ($p['discount_usd'] > 0 ? ' $' . number_format((float)$p['discount_usd'], 2) : '');
            echo '<tr><td><b>' . e($p['code']) . '</b></td><td>' . e($p['kind']) . '</td><td>' . e(trim($disc)) . '</td>'
               . '<td style="font-size:12.5px;color:var(--mute)">' . ($p['aff_email'] ? e($p['aff_email']) . ' · ' . (int)$p['commission_pct'] . '%' : '—') . '</td>'
               . '<td class="num">' . (int)$p['uses'] . ($p['max_uses'] !== null ? ' / ' . (int)$p['max_uses'] : '') . '</td>'
               . '<td>' . e($p['expires_at'] ?: '—') . '</td><td>' . ($p['active'] ? '<span class="chip c-ok">yes</span>' : '<span class="chip c-dim">no</span>') . '</td>'
               . '<td><form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="toggle_code"><input type="hidden" name="code_id" value="' . (int)$p['id'] . '">'
               . '<button class="btn2">' . ($p['active'] ? 'Disable' : 'Enable') . '</button></form></td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
    echo '<div class="card"><h2>Create a code</h2><form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="create_code"><div class="grid">'
       . '<div class="field"><label>Code</label><input name="code" placeholder="LAUNCH20" required maxlength="40"></div>'
       . '<div class="field"><label>Kind</label><select name="kind"><option value="promo">Promo</option><option value="affiliate">Affiliate</option></select></div>'
       . '<div class="field"><label>Discount %</label><input name="discount_pct" type="number" min="0" max="100" value="0"></div>'
       . '<div class="field"><label>Discount $ (flat)</label><input name="discount_usd" type="number" min="0" step="0.01" value="0"></div>'
       . '<div class="field"><label>Max uses (blank = unlimited)</label><input name="max_uses" type="number" min="1"></div>'
       . '<div class="field"><label>Expires (YYYY-MM-DD, optional)</label><input name="expires_at" placeholder="2026-12-31"></div>'
       . '<div class="field"><label>Affiliate login email (affiliate codes)</label><input name="affiliate_email" type="email"></div>'
       . '<div class="field"><label>Affiliate commission %</label><input name="commission_pct" type="number" min="0" max="100" value="0"></div>'
       . '</div><button class="btn">Create code</button></form></div>';
    $earn = all("SELECT p.code, u.email, p.commission_pct, COUNT(s.id) n, COALESCE(SUM(s.price_paid),0) revenue
                 FROM promo_codes p JOIN users u ON u.id=p.affiliate_user LEFT JOIN subscriptions s ON s.promo_code=p.code
                 WHERE p.kind='affiliate' GROUP BY p.id ORDER BY revenue DESC");
    if ($earn) {
        echo '<div class="card"><h2>Affiliate earnings</h2><table><tr><th>Code</th><th>Affiliate</th><th class="num">Activations</th><th class="num">Revenue</th><th class="num">Commission owed</th></tr>';
        foreach ($earn as $r) echo '<tr><td><b>' . e($r['code']) . '</b></td><td>' . e($r['email']) . '</td><td class="num">' . (int)$r['n'] . '</td>'
            . '<td class="num">$' . number_format((float)$r['revenue'], 2) . '</td><td class="num">$' . number_format((float)$r['revenue'] * (int)$r['commission_pct'] / 100, 2) . '</td></tr>';
        echo '</table></div>';
    }
    foot(); exit;
}

/* ── users view ─────────────────────────────────────────────────────────── */
if ($view === 'users') {
    echo '<h1>Logins</h1>';
    $users = all("SELECT u.*, c.id customer_id, c.ig_handle FROM users u LEFT JOIN customers c ON c.user_id=u.id ORDER BY u.id DESC");
    echo '<div class="card"><div style="overflow-x:auto"><table><tr><th>Email</th><th>Name</th><th>Role</th><th>Verified</th><th>Status</th><th>Instagram</th><th>Last login</th><th></th></tr>';
    foreach ($users as $x) {
        $self = (int)$x['id'] === (int)$admin['id'];
        echo '<tr><td>' . e($x['email']) . ($x['google_id'] ? ' <span class="chip c-dim">google</span>' : '') . '</td><td>' . e($x['name']) . '</td>'
           . '<td>' . ($x['role'] === 'admin' ? '<span class="chip c-org">admin</span>' : 'customer') . '</td>'
           . '<td>' . ($x['email_verified'] ? 'yes' : '<span class="chip c-warn">no</span>') . '</td><td>' . statusChip($x['status']) . '</td>'
           . '<td>' . ($x['ig_handle'] ? '@' . e($x['ig_handle']) : '—') . '</td><td style="font-size:12.5px;color:var(--mute)">' . e($x['last_login_at'] ?: '—') . '</td><td>';
        if (!$self) {
            echo '<form method="post" style="display:inline">' . Auth::csrfField() . '<input type="hidden" name="action" value="user_status"><input type="hidden" name="user_id" value="' . (int)$x['id'] . '">'
               . '<input type="hidden" name="status" value="' . ($x['status'] === 'active' ? 'disabled' : 'active') . '"><button class="btn2">' . ($x['status'] === 'active' ? 'Disable' : 'Enable') . '</button></form> '
               . '<form method="post" style="display:inline" data-confirm="Change this user\'s role?">' . Auth::csrfField() . '<input type="hidden" name="action" value="user_role"><input type="hidden" name="user_id" value="' . (int)$x['id'] . '">'
               . '<input type="hidden" name="role" value="' . ($x['role'] === 'admin' ? 'customer' : 'admin') . '"><button class="btn2">' . ($x['role'] === 'admin' ? 'Make customer' : 'Make admin') . '</button></form>';
        } else echo '<span style="color:var(--dim);font-size:12px">you</span>';
        echo '</td></tr>';
    }
    echo '</table></div></div>';
    echo '<div class="card"><h2>Create a login by hand</h2><div class="sub">For clients you onboard yourself. They get a one-time password; Instagram is marked verified by you.</div>'
       . '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="create_user"><div class="grid">'
       . '<div class="field"><label>Name</label><input name="name" required></div><div class="field"><label>Email</label><input name="email" type="email" required></div>'
       . '<div class="field"><label>Instagram handle (optional)</label><input name="ig_handle"></div><div class="field"><label>Competitors</label><input name="competitors" placeholder="a, b, c"></div>'
       . '</div><div class="field"><label>Niche</label><input name="niche" placeholder="UAE real estate — brokers, developers"></div><div class="grid">'
       . '<div class="field"><label>Language</label><select name="language"><option>Hinglish</option><option>English</option><option>Urdu</option><option>Arabic</option></select></div>'
       . '<div class="field"><label>Script length</label><select name="duration_pref"><option>30-40 sec</option><option>45-50 sec</option><option>60 sec</option></select></div>'
       . '</div><button class="btn">Create login</button></form></div>';
    foot(); exit;
}

/* ── customers view (default) ───────────────────────────────────────────── */
$stats = Queue::stats();
$counts = one("SELECT (SELECT COUNT(*) FROM users WHERE role='customer') users,
                      (SELECT COUNT(*) FROM subscriptions WHERE status='active') paying,
                      (SELECT COUNT(*) FROM subscriptions WHERE status='trial') trials,
                      (SELECT COUNT(*) FROM plan_requests WHERE status='pending') requests,
                      (SELECT COALESCE(SUM(apify_cost_usd),0) FROM runs WHERE started_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)) cost30");
echo '<div class="tiles">';
foreach ([['Customers', (int)$counts['users']], ['Paying', (int)$counts['paying']], ['On trial', (int)$counts['trials']],
          ['Plan requests', (int)$counts['requests']], ['Apify, 30 days', '$' . number_format((float)$counts['cost30'], 2)],
          ['Queue pending', (int)($stats['pending'] ?? 0)], ['Queue failed', (int)($stats['failed'] ?? 0)]] as $t)
    echo '<div class="tile"><div class="k">' . e($t[0]) . '</div><div class="v">' . e($t[1]) . '</div></div>';
echo '</div>';
echo '<form method="post" style="margin-bottom:18px">' . Auth::csrfField() . '<input type="hidden" name="action" value="worker"><button class="btn2">Run worker now</button>'
   . ' <span style="color:var(--dim);font-size:12.5px">— cron does this every 5 minutes; this is for testing</span></form>';

$reqs = all("SELECT r.*, u.email, p.name plan_name FROM plan_requests r JOIN users u ON u.id=r.user_id JOIN plans p ON p.id=r.plan_id WHERE r.status='pending' ORDER BY r.id");
if ($reqs) {
    echo '<div class="card"><h2>Plan requests waiting for payment</h2><table><tr><th>When</th><th>Customer</th><th>Plan</th><th class="num">Price</th><th>Code</th><th></th></tr>';
    foreach ($reqs as $r) {
        echo '<tr><td style="font-size:12.5px;color:var(--mute)">' . e(substr($r['created_at'], 0, 16)) . '</td><td>' . e($r['email']) . '</td><td>' . e($r['plan_name']) . '</td>'
           . '<td class="num">$' . number_format((float)$r['price_usd'], 2) . '</td><td>' . e($r['promo_code'] ?: '—') . '</td><td>'
           . '<form method="post" style="display:inline">' . Auth::csrfField() . '<input type="hidden" name="action" value="activate"><input type="hidden" name="user_id" value="' . (int)$r['user_id'] . '">'
           . '<input type="hidden" name="plan" value="' . e($r['plan_id']) . '"><input type="hidden" name="promo" value="' . e($r['promo_code']) . '"><button class="btn">Paid — activate</button></form> '
           . '<form method="post" style="display:inline">' . Auth::csrfField() . '<input type="hidden" name="action" value="reject_request"><input type="hidden" name="request_id" value="' . (int)$r['id'] . '"><button class="btn2">Close</button></form></td></tr>';
    }
    echo '</table></div>';
}

$cust = all("SELECT c.*, u.email login_email, u.status user_status,
             (SELECT COUNT(*) FROM runs r WHERE r.customer_id=c.id AND r.kind='full') runs,
             (SELECT MAX(r.id) FROM runs r WHERE r.customer_id=c.id) last_run,
             (SELECT MAX(r.started_at) FROM runs r WHERE r.customer_id=c.id) last_at
             FROM customers c LEFT JOIN users u ON u.id=c.user_id ORDER BY c.id DESC");
echo '<div class="card"><h2>Customers</h2>';
if (!$cust) echo '<div class="empty">Nobody yet. Customers appear here when they sign up, or create one under Users.</div>'; else {
    echo '<div style="overflow-x:auto"><table><tr><th>Customer</th><th>Instagram</th><th>Plan</th><th class="num">Scripts</th><th>Last run</th><th></th></tr>';
    foreach ($cust as $c) {
        $sub = $c['user_id'] ? Billing::current($c['user_id']) : null;
        $comp = json_decode((string)$c['competitors'], true) ?: [];
        $planCell = $sub ? e($sub['plan_name']) . ' ' . statusChip($sub['status']) : '<span class="chip c-bad">no plan</span>';
        echo '<tr><td><b>' . e($c['name']) . '</b><div style="color:var(--dim);font-size:12px">' . e($c['login_email'] ?: $c['email'])
           . (!$c['user_id'] ? ' · <span class="chip c-warn">not linked</span>' : '') . '</div>'
           . '<div style="color:var(--dim);font-size:11.5px">' . e($c['niche']) . '</div></td>'
           . '<td>@' . e($c['ig_handle']) . ' ' . ($c['ig_verified'] ? '<span class="chip c-ok">verified</span>' : '<span class="chip c-warn">unverified</span>')
           . '<div style="font-size:12px;color:var(--mute)">vs @' . e(implode(', @', $comp)) . '</div></td>'
           . '<td>' . $planCell . '</td>'
           . '<td class="num">' . ($sub ? (int)$sub['scripts_used'] . ($sub['scripts_month'] !== null ? '/' . (int)$sub['scripts_month'] : '') : '—') . '</td>'
           . '<td style="font-size:12.5px;color:var(--mute)">' . ($c['last_at'] ? e(substr($c['last_at'], 0, 10)) : '—') . ' · ' . (int)$c['runs'] . ' run(s)'
           . ($c['last_run'] ? ' <a href="admin.php?run=' . (int)$c['last_run'] . '">view</a>' : '') . '</td><td style="white-space:nowrap">';
        echo '<form method="post" style="display:inline">' . Auth::csrfField() . '<input type="hidden" name="action" value="run"><input type="hidden" name="customer_id" value="' . (int)$c['id'] . '"><button class="btn2">Run</button></form> ';
        echo '<form method="post" style="display:inline" data-confirm="Force a full run, skipping the trial gate and quota?">' . Auth::csrfField() . '<input type="hidden" name="action" value="run"><input type="hidden" name="force" value="1"><input type="hidden" name="customer_id" value="' . (int)$c['id'] . '"><button class="btn2">Force</button></form> ';
        if (!$c['ig_verified']) echo '<form method="post" style="display:inline">' . Auth::csrfField() . '<input type="hidden" name="action" value="trust_ig"><input type="hidden" name="customer_id" value="' . (int)$c['id'] . '"><button class="btn2">Mark verified</button></form> ';
        echo '<details style="display:inline-block;vertical-align:middle"><summary class="btn2" style="list-style:none">More</summary><div style="padding:10px 0;min-width:320px">';
        if ($c['user_id']) {
            echo '<form method="post" style="margin-bottom:8px">' . Auth::csrfField() . '<input type="hidden" name="action" value="activate"><input type="hidden" name="user_id" value="' . (int)$c['user_id'] . '">'
               . '<div style="display:flex;gap:6px"><select name="plan">';
            foreach (Billing::plans() as $p) if ($p['id'] !== 'trial') echo '<option value="' . e($p['id']) . '">' . e($p['name']) . ' $' . (int)$p['price_usd'] . '</option>';
            echo '</select><input name="promo" placeholder="code" style="width:110px"><button class="btn2">Activate</button></div></form>';
            echo '<form method="post" style="display:inline">' . Auth::csrfField() . '<input type="hidden" name="action" value="renew"><input type="hidden" name="user_id" value="' . (int)$c['user_id'] . '"><button class="btn2">Paid this month</button></form> ';
            echo '<form method="post" style="display:inline">' . Auth::csrfField() . '<input type="hidden" name="action" value="cancel_sub"><input type="hidden" name="user_id" value="' . (int)$c['user_id'] . '"><button class="btn2">Cancel plan</button></form> ';
        } else {
            echo '<form method="post" style="margin-bottom:8px">' . Auth::csrfField() . '<input type="hidden" name="action" value="link_customer"><input type="hidden" name="customer_id" value="' . (int)$c['id'] . '">'
               . '<div style="display:flex;gap:6px"><input name="email" type="email" placeholder="login email to attach"><button class="btn2">Link</button></div></form>';
        }
        echo '<form method="post" style="display:inline" data-confirm="Delete this customer and all their runs? The handle stays in the trial ledger.">' . Auth::csrfField() . '<input type="hidden" name="action" value="delete_customer"><input type="hidden" name="customer_id" value="' . (int)$c['id'] . '"><button class="btn2">Delete</button></form>';
        echo '</div></details></td></tr>';
    }
    echo '</table></div>';
}
echo '</div>';

$recent = all("SELECT r.*, c.name FROM runs r JOIN customers c ON c.id=r.customer_id ORDER BY r.id DESC LIMIT 15");
if ($recent) {
    echo '<div class="card"><h2>Recent runs</h2><div style="overflow-x:auto"><table><tr><th>#</th><th>Customer</th><th>Type</th><th>Started</th><th>Status</th><th class="num">Reels</th><th class="num">Scripts</th><th class="num">Cost</th><th></th></tr>';
    foreach ($recent as $r)
        echo '<tr><td><a href="admin.php?run=' . (int)$r['id'] . '">#' . (int)$r['id'] . '</a></td><td>' . e($r['name']) . '</td><td>' . ($r['kind'] === 'trial_check' ? 'check' : 'full') . '</td>'
           . '<td style="font-size:12.5px;color:var(--mute)">' . e(substr($r['started_at'], 0, 16)) . '</td><td>' . statusChip($r['status']) . '</td>'
           . '<td class="num">' . n($r['posts_scored']) . '</td><td class="num">' . n($r['scripts_delivered']) . '</td><td class="num">$' . number_format((float)$r['apify_cost_usd'], 3) . '</td>'
           . '<td>' . ((int)$r['scripts_delivered'] > 0 ? '<a class="btn2" href="export.php?run=' . (int)$r['id'] . '">Excel</a>' : '') . '</td></tr>';
    echo '</table></div></div>';
}
$log = @file_get_contents(__DIR__ . '/worker.log');
if ($log) { $lines = array_slice(array_filter(explode("\n", trim($log))), -25);
  echo '<div class="card"><h2>Worker log</h2><pre>' . e(implode("\n", array_reverse($lines))) . '</pre></div>'; }
foot();
