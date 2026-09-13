<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/ui.php';
app_boot();
$cfg = outlier_config(); $msg = null; $err = null;
$t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ($_POST['t'] ?? '')));

if ($t) {
    if (!Auth::throttle('reset', 20)) { http_response_code(429); exit('Too many attempts. Try again later.'); }
    $u = one("SELECT * FROM users WHERE reset_token=? AND reset_expires > UTC_TIMESTAMP()", [Auth::tokenHash($t)]);
    if (!$u) { http_response_code(400); exit('This reset link is invalid or has expired.'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Auth::passwordOk($_POST['password'] ?? '')) $err = 'Password needs at least 10 characters with letters and numbers.';
        else {
            q("UPDATE users SET password_hash=?, reset_token=NULL, reset_expires=NULL, email_verified=1 WHERE id=?", [Auth::hash($_POST['password']), $u['id']]);
            header('Location: login.php?reset=1'); exit;
        }
    }
    head('New password');
    echo '<div class="narrow" style="margin:30px auto 0"><div class="card"><h1>Choose a new password</h1>';
    flash(null, $err);
    echo '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="t" value="' . e($t) . '">'
       . '<div class="field"><label>New password</label><input name="password" type="password" required minlength="10"></div>'
       . '<button class="btn" style="width:100%">Save</button></form></div></div>';
    foot(); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!Auth::locked($email)) {
        Auth::record($email, 0);                       // reset requests count toward the lock too
        $u = one("SELECT * FROM users WHERE email=? AND status='active'", [$email]);
        if ($u) {
            $tok = Auth::token();
            q("UPDATE users SET reset_token=?, reset_expires=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id=?", [Auth::tokenHash($tok), $u['id']]);
            $m = new Mailer($cfg['resend_key'] ?? '', $cfg['from_email'] ?? '', $cfg['from_name'] ?? '');
            if ($m->enabled()) {
                $link = rtrim($cfg['base_url'] ?? '', '/') . '/forgot.php?t=' . $tok;
                $m->send($u['email'], 'Reset your Outlier password',
                    '<p>Reset your password with this link (valid one hour):</p><p><a href="' . e($link) . '">' . e($link) . '</a></p>');
            }
        }
    }
    /* Same message whether or not the address exists — never confirm accounts to strangers. */
    $msg = 'If that email has an account, a reset link is on its way.';
}
head('Reset password', ['Log in' => 'login.php']);
echo '<div class="narrow" style="margin:30px auto 0"><div class="card"><h1>Reset password</h1>';
flash($msg);
echo '<form method="post">' . Auth::csrfField()
   . '<div class="field"><label>Email</label><input name="email" type="email" required></div>'
   . '<button class="btn" style="width:100%">Send reset link</button></form></div></div>';
foot();
