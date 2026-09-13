<?php
/* Plans and promo codes. No card is taken here: the customer asks for a plan,
   we confirm the price (after any code), and the admin activates it once paid. */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/ui.php';
app_boot();
$u = Auth::requireLogin();
$cfg = outlier_config();
$msg = null; $err = null; $quote = null;
$sub = Billing::current($u['id']);
$pending = one("SELECT * FROM plan_requests WHERE user_id=? AND status='pending' ORDER BY id DESC LIMIT 1", [$u['id']]);

try {
    $act = $_POST['action'] ?? '';
    if ($act === 'quote' || $act === 'request') {
        $planId = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['plan'] ?? ''));
        $p = Billing::plan($planId);
        if (!$p || $planId === 'trial' || !$p['active']) throw new RuntimeException('Pick a plan.');
        $price = (float)$p['price_usd']; $code = null;
        $promo = trim((string)($_POST['promo'] ?? ''));
        if ($promo !== '') {
            if (!Auth::throttle('promo', 20)) throw new RuntimeException('Too many code attempts. Try again later.');
            $d = Billing::applyPromo($promo, $price);
            if (isset($d['error'])) throw new RuntimeException($d['error']);
            $price = $d['price']; $code = $d['code'];
        }
        $quote = ['plan' => $p, 'price' => $price, 'code' => $code];
        if ($act === 'request') {
            if ($pending) throw new RuntimeException('You already have a pending request. We will be in touch shortly.');
            q("INSERT INTO plan_requests (user_id,plan_id,promo_code,price_usd) VALUES (?,?,?,?)", [$u['id'], $planId, $code, $price]);
            $pending = one("SELECT * FROM plan_requests WHERE id=?", [lastId()]);
            $m = new Mailer($cfg['resend_key'] ?? '', $cfg['from_email'] ?? '', $cfg['from_name'] ?? '');
            if ($m->enabled() && !empty($cfg['admin_email']))
                $m->send($cfg['admin_email'], 'Plan request: ' . $u['email'] . ' → ' . $p['name'],
                    '<p>' . e($u['email']) . ' asked for <b>' . e($p['name']) . '</b> at $' . number_format($price, 2)
                    . ($code ? ' with code ' . e($code) : '') . '.</p><p>Activate it in the admin panel once paid.</p>');
            $msg = 'Request received. We will send you a payment link by email and activate the plan as soon as it is paid.';
        }
    }
} catch (Throwable $e) { $err = $e->getMessage(); }

head('Plan', customerNav($u));
echo '<h1>Plans</h1><div class="sub">Every plan analyses your competitors weekly and writes scripts in your own voice. Cancel any time.</div>';
flash($msg, $err);
if ($sub) echo '<div class="msg m-ok" style="display:flex;gap:10px;align-items:center">Current plan: <b>' . e($sub['plan_name']) . '</b> ' . statusChip($sub['status'])
    . ($sub['status'] !== 'trial' && $sub['renews_at'] ? '<span style="color:var(--mute)">· renews ' . e($sub['renews_at']) . '</span>' : '') . '</div>';
if ($pending) echo '<div class="msg m-warn">Pending: ' . e(Billing::plan($pending['plan_id'])['name'] ?? $pending['plan_id']) . ' at $' . number_format((float)$pending['price_usd'], 2)
    . ' — waiting for payment confirmation.</div>';

echo '<div class="tiles" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">';
foreach (Billing::plans() as $p) {
    if ($p['id'] === 'trial') continue;
    $cur = $sub && $sub['plan_id'] === $p['id'] && $sub['status'] !== 'cancelled';
    echo '<div class="tile" style="' . ($cur ? 'border-color:var(--orange)' : '') . '"><div class="k">' . e($p['name']) . '</div>'
       . '<div class="v">$' . (int)$p['price_usd'] . '<span style="font-size:13px;color:var(--dim);font-weight:400">/month</span></div>'
       . '<div style="font-size:13px;color:var(--mute);margin:8px 0 14px">' . ($p['scripts_month'] === null ? 'Unlimited scripts' : (int)$p['scripts_month'] . ' scripts a month')
       . '<br>' . (int)$p['runs_month'] . ' analyses a month</div>'
       . '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="plan" value="' . e($p['id']) . '">'
       . '<div class="field"><input name="promo" placeholder="Promo code (optional)" maxlength="40"></div>'
       . '<button class="btn2" name="action" value="quote">Check price</button> '
       . '<button class="btn" name="action" value="request"' . ($cur ? ' disabled' : '') . '>' . ($cur ? 'Current plan' : 'Choose') . '</button></form></div>';
}
echo '</div>';
if ($quote) echo '<div class="msg m-ok">' . e($quote['plan']['name']) . ': <b>$' . number_format($quote['price'], 2) . '/month</b>'
    . ($quote['code'] ? ' with code ' . e($quote['code']) : '') . '</div>';
echo '<div class="card"><h2>What you get</h2><div style="color:var(--mute);font-size:14px;line-height:1.7">'
   . 'Once a week we score your competitors\' reels by plays per day and completion, throw out the boosted and the fake outliers, '
   . 'transcribe the hooks that are actually working, read what the comments keep asking, and write your next scripts to your voice profile — '
   . 'in your language, at the length you chose. Each batch comes with the analysis behind it and an Excel report.</div></div>';
foot();
