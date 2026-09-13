<?php
/* Google sign-in callback. Server-side code exchange; nothing sensitive reaches the browser. */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/lib/http.php';
app_boot();
if (!Auth::googleEnabled()) { http_response_code(404); exit('Google sign-in is not enabled.'); }
$cfg = outlier_config();
$state = (string)($_GET['state'] ?? ''); $code = (string)($_GET['code'] ?? '');
if (!$code || !$state || empty($_SESSION['g_state']) || !hash_equals($_SESSION['g_state'], $state)) {
    http_response_code(400); exit('Sign-in was interrupted. Go back and try again.');
}
unset($_SESSION['g_state']);
if (!Auth::throttle('google', 20)) { http_response_code(429); exit('Too many attempts. Try again later.'); }
try {
    $tok = Http::json('POST', 'https://oauth2.googleapis.com/token',
        ['Content-Type' => 'application/x-www-form-urlencoded'],
        http_build_query(['code' => $code, 'client_id' => $cfg['google_client_id'],
            'client_secret' => $cfg['google_client_secret'], 'redirect_uri' => Auth::googleRedirect(),
            'grant_type' => 'authorization_code']), 30);
    $info = Http::json('GET', 'https://openidconnect.googleapis.com/v1/userinfo',
        ['Authorization' => 'Bearer ' . ($tok['access_token'] ?? '')], null, 20);
} catch (Throwable $e) { http_response_code(502); exit('Google did not complete the sign-in. Try again.'); }

$gid = (string)($info['sub'] ?? ''); $email = strtolower((string)($info['email'] ?? ''));
if (!$gid || !$email || empty($info['email_verified'])) { http_response_code(400); exit('Google did not return a verified email.'); }

/* Already logged in (e.g. connecting YouTube from Settings)? Attach to that account,
   never to whichever Google account happened to be picked. */
$u = Auth::user() ?: (one("SELECT * FROM users WHERE google_id=?", [$gid]) ?: one("SELECT * FROM users WHERE email=?", [$email]));
if (!$u) {
    q("INSERT INTO users (email,name,google_id,email_verified) VALUES (?,?,?,1)", [$email, (string)($info['name'] ?? ''), $gid]);
    $u = one("SELECT * FROM users WHERE id=?", [lastId()]);
    Billing::startTrial($u['id']);
} elseif (!$u['google_id'] && !one("SELECT id FROM users WHERE google_id=?", [$gid])) {
    q("UPDATE users SET google_id=?, email_verified=1 WHERE id=?", [$gid, $u['id']]);
}
if ($u['status'] !== 'active') { http_response_code(403); exit('This account is disabled.'); }

/* YouTube connect uses the same flow with an extra scope; the token proves channel ownership. */
if (!empty($tok['scope']) && strpos($tok['scope'], 'youtube') !== false) {
    try {
        $ch = Http::json('GET', 'https://www.googleapis.com/youtube/v3/channels?part=id,snippet&mine=true',
            ['Authorization' => 'Bearer ' . $tok['access_token']], null, 20);
        $item = $ch['items'][0] ?? null;
        if ($item) {
            $cid = (int)(one("SELECT id FROM customers WHERE user_id=?", [$u['id']])['id'] ?? 0);
            if ($cid) q("UPDATE customers SET yt_verified=1, yt_channel_id=?, yt_channel=? WHERE id=?",
                        [$item['id'], 'https://www.youtube.com/channel/' . $item['id'], $cid]);
            q("INSERT IGNORE INTO handle_ledger (platform,handle,customer_id) VALUES ('youtube',?,?)", [$item['id'], $cid ?: null]);
        }
    } catch (Throwable $e) { /* connection failed; the user can retry from settings */ }
}
Auth::establish($u);
header('Location: ' . ($u['role'] === 'admin' ? 'admin.php' : 'onboard.php')); exit;
