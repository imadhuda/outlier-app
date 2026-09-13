<?php
require_once __DIR__ . '/lib/auth.php';
app_boot();
/* Logging out via GET is a CSRF vector; require the POST from the nav or a confirm page. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') { Auth::logout(); header('Location: login.php'); exit; }
require_once __DIR__ . '/lib/ui.php';
head('Log out');
echo '<div class="narrow" style="margin:40px auto 0"><div class="card"><h1>Log out?</h1>'
   . '<form method="post" style="margin-top:14px">' . Auth::csrfField()
   . '<button class="btn">Log out</button> <a class="btn2" href="app.php">Cancel</a></form></div></div>';
foot();
