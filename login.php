<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/ui.php';
app_boot();
if (Auth::user()) { header('Location: app.php'); exit; }
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = Auth::login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if (isset($r['user'])) { header('Location: ' . ($r['user']['role'] === 'admin' ? 'admin.php' : 'app.php')); exit; }
    $err = $r['error'];
}
head('Log in', ['Sign up' => 'signup.php']);
echo '<div class="narrow" style="margin:30px auto 0">';
echo '<div class="card"><h1>Log in</h1><div class="sub">Welcome back.</div>';
flash(null, $err, isset($_GET['verified']) ? null : null);
if (isset($_GET['verified'])) flash('Email verified. You can log in now.');
if (isset($_GET['reset'])) flash('Password updated. Log in with the new one.');
?>
<form method="post"><?=Auth::csrfField()?>
  <div class="field"><label>Email</label><input name="email" type="email" required autocomplete="email" autofocus></div>
  <div class="field"><label>Password</label><input name="password" type="password" required autocomplete="current-password"></div>
  <button class="btn" style="width:100%">Log in</button>
</form>
<?php if (Auth::googleEnabled()): ?>
<div style="text-align:center;color:var(--dim);font-size:12.5px;margin:16px 0">or</div>
<a class="btn2" style="width:100%;text-align:center" href="<?=e(Auth::googleUrl())?>">Continue with Google</a>
<?php endif; ?>
<div style="margin-top:18px;font-size:13px;color:var(--mute)">
  <a href="forgot.php">Forgot password?</a> &nbsp;·&nbsp; New here? <a href="signup.php">Create an account</a></div>
</div></div>
<?php foot();
