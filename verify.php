<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';
app_boot();
$t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
if (!Auth::throttle('verify', 20)) { http_response_code(429); exit('Too many attempts. Try again later.'); }
$u = $t ? one("SELECT * FROM users WHERE verify_token=? AND email_verified=0", [Auth::tokenHash($t)]) : null;
if (!$u) { http_response_code(400); exit('This link is invalid or already used.'); }
q("UPDATE users SET email_verified=1, verify_token=NULL WHERE id=?", [$u['id']]);
Billing::startTrial($u['id']);
Auth::establish($u);
header('Location: onboard.php'); exit;
