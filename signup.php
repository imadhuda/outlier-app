<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/ui.php';
app_boot();
if (Auth::user()) { header('Location: app.php'); exit; }
$cfg = outlier_config();
$err = null; $done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = Auth::signup($_POST['email'] ?? '', $_POST['password'] ?? '', $_POST['name'] ?? '');
    if (isset($r['user'])) {
        $u = $r['user'];
        $m = new Mailer($cfg['resend_key'] ?? '', $cfg['from_email'] ?? '', $cfg['from_name'] ?? '');
        if ($m->enabled()) {
            $link = rtrim($cfg['base_url'] ?? '', '/') . '/verify.php?t=' . $r['token'];
            $m->send($u['email'], 'Confirm your Outlier account',
                '<p>Hi ' . e($u['name'] ?: 'there') . ',</p><p>Confirm your email to start your trial:</p>'
              . '<p><a href="' . e($link) . '">' . e($link) . '</a></p><p>If you did not sign up, ignore this.</p>');
            $done = true;
        } else {
            /* No email service configured: verify on the spot so signups are not stranded. */
            q("UPDATE users SET email_verified=1, verify_token=NULL WHERE id=?", [$u['id']]);
            Billing::startTrial($u['id']);
            Auth::establish($u);
            header('Location: onboard.php'); exit;
        }
    } else $err = $r['error'];
}
head('Create account', ['Log in' => 'login.php']);
echo '<div class="narrow" style="margin:30px auto 0"><div class="card">';
if ($done) {
    echo '<h1>Check your email</h1><div class="sub">We sent a confirmation link. Click it to activate your account.</div>';
} else {
    echo '<h1>Start your free trial</h1><div class="sub">Post 10 videos in 7 days. We analyse them against your competitors and send you your next 10 — free.</div>';
    flash(null, $err);
    echo '<form method="post">' . Auth::csrfField();
    echo '<div class="field"><label>Your name</label><input name="name" required autocomplete="name"></div>';
    echo '<div class="field"><label>Email</label><input name="email" type="email" required autocomplete="email"></div>';
    echo '<div class="field"><label>Password</label><input name="password" type="password" required minlength="10" autocomplete="new-password">'
       . '<div style="font-size:12px;color:var(--dim);margin-top:5px">At least 10 characters, with letters and numbers.</div></div>';
    echo '<button class="btn" style="width:100%">Create account</button></form>';
    if (Auth::googleEnabled()) {
        echo '<div style="text-align:center;color:var(--dim);font-size:12.5px;margin:16px 0">or</div>'
           . '<a class="btn2" style="width:100%;text-align:center" href="' . e(Auth::googleUrl()) . '">Sign up with Google</a>';
    }
}
echo '</div></div>'; foot();
