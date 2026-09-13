<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Sessions, passwords, CSRF, rate limiting, roles. Nothing secret ever
   travels in a URL: identity lives in a hardened session cookie only.
   ═══════════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/db.php';

class Auth {
    const LOCK_AFTER  = 5;      // failed logins per email or IP…
    const LOCK_WINDOW = 900;    // …inside this many seconds → locked
    const SESSION_TTL = 60 * 60 * 24 * 14;
    private static $nonce = null;

    /* ── session ─────────────────────────────────────────────────────── */
    public static function https() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
    public static function boot() {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('outlier_s');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'domain' => '',
            'secure' => self::https(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string)self::SESSION_TTL);
        session_start();
        /* Absolute lifetime, then periodic id rotation so a captured cookie goes stale. */
        if (!empty($_SESSION['uid']) && !empty($_SESSION['_born']) && $_SESSION['_born'] < time() - self::SESSION_TTL) {
            self::logout(); session_start();
        }
        if (empty($_SESSION['_rot']) || $_SESSION['_rot'] < time() - 1800) {
            session_regenerate_id(true); $_SESSION['_rot'] = time();
        }
    }

    public static function nonce() {
        if (self::$nonce === null) self::$nonce = base64_encode(random_bytes(16));
        return self::$nonce;
    }
    public static function headers() {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data:; script-src 'nonce-" . self::nonce() . "'; object-src 'none'; "
             . "form-action 'self' https://accounts.google.com; frame-ancestors 'none'; base-uri 'self'");
        if (self::https()) header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
        header_remove('X-Powered-By');
    }

    public static function ip() { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

    /* ── CSRF ────────────────────────────────────────────────────────── */
    public static function csrf() {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }
    public static function csrfField() {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::csrf(), ENT_QUOTES) . '">';
    }
    public static function requireCsrf() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        $t = (string)($_POST['_csrf'] ?? '');
        if ($t === '' || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $t)) {
            http_response_code(403); exit('Form expired. Go back and try again.');
        }
        /* Same-site check on top of the token: a cross-origin POST is refused outright. */
        $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
        if ($origin !== '') {
            $host = parse_url($origin, PHP_URL_HOST);
            if ($host && strcasecmp($host, (string)($_SERVER['HTTP_HOST'] ?? '')) !== 0
                && strcasecmp($host, explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]) !== 0) {
                http_response_code(403); exit('Cross-site request refused.');
            }
        }
    }

    /* ── passwords & tokens ──────────────────────────────────────────── */
    public static function algo() { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT; }
    public static function hash($pw) { return password_hash($pw, self::algo()); }
    public static function passwordOk($pw) {
        return strlen($pw) >= 10 && strlen($pw) <= 200 && preg_match('/[A-Za-z]/', $pw) && preg_match('/[0-9]/', $pw);
    }
    /** One-time links carry a random token; only its hash is stored, so a copied database cannot mint links. */
    public static function token() { return bin2hex(random_bytes(24)); }
    public static function tokenHash($t) { return hash('sha256', (string)$t); }

    /* ── rate limiting ───────────────────────────────────────────────── */
    public static function locked($email, $limit = self::LOCK_AFTER) {
        $since = gmdate('Y-m-d H:i:s', time() - self::LOCK_WINDOW);
        $r = one("SELECT SUM(ip=? AND success=0) ipfails, SUM(email=? AND success=0) emailfails
                  FROM login_attempts WHERE attempted > ?", [self::ip(), $email, $since]);
        return ((int)($r['ipfails'] ?? 0) >= $limit) || ((int)($r['emailfails'] ?? 0) >= $limit);
    }
    public static function record($email, $ok) {
        q("INSERT INTO login_attempts (ip,email,success,attempted) VALUES (?,?,?,UTC_TIMESTAMP())",
          [self::ip(), substr((string)$email, 0, 190), $ok ? 1 : 0]);
        if (mt_rand(1, 50) === 1) q("DELETE FROM login_attempts WHERE attempted < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)");
    }
    /** Generic per-IP throttle for signup / reset / verification calls. */
    public static function throttle($bucket, $limit = 10) {
        $since = gmdate('Y-m-d H:i:s', time() - self::LOCK_WINDOW);
        $r = one("SELECT COUNT(*) n FROM login_attempts WHERE ip=? AND email=? AND attempted > ?",
                 [self::ip(), '#' . $bucket, $since]);
        if ((int)($r['n'] ?? 0) >= $limit) return false;
        q("INSERT INTO login_attempts (ip,email,success,attempted) VALUES (?,?,1,UTC_TIMESTAMP())", [self::ip(), '#' . $bucket]);
        return true;
    }

    /* ── login / signup ──────────────────────────────────────────────── */
    public static function login($email, $pw) {
        $email = strtolower(trim((string)$email));
        if ($email === '' || strlen($email) > 190) return ['error' => 'Email or password is wrong.'];
        if (self::locked($email)) return ['error' => 'Too many attempts. Wait 15 minutes and try again.'];
        $u = one("SELECT * FROM users WHERE email=?", [$email]);
        $ok = $u && $u['password_hash'] && password_verify((string)$pw, $u['password_hash']);
        if ($ok && $u['status'] !== 'active') { self::record($email, 0); return ['error' => 'This account is disabled.']; }
        if ($ok && !$u['email_verified']) { self::record($email, 0); return ['error' => 'Confirm your email first — check your inbox for the link.']; }
        self::record($email, $ok);
        if (!$ok) { usleep(300000); return ['error' => 'Email or password is wrong.']; }
        if (password_needs_rehash($u['password_hash'], self::algo()))
            q("UPDATE users SET password_hash=? WHERE id=?", [self::hash($pw), $u['id']]);
        self::establish($u);
        return ['user' => $u];
    }

    public static function establish(array $u) {
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int)$u['id'], 'role' => $u['role'], '_rot' => time(), '_born' => time()];
        q("UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?", [$u['id']]);
    }

    public static function signup($email, $pw, $name) {
        $email = strtolower(trim((string)$email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return ['error' => 'That email address is not valid.'];
        if (!self::passwordOk($pw)) return ['error' => 'Password needs at least 10 characters with letters and numbers.'];
        if (!self::throttle('signup', 5)) return ['error' => 'Too many sign-ups from this connection. Try again later.'];
        if (one("SELECT id FROM users WHERE email=?", [$email])) return ['error' => 'An account with that email already exists. Log in instead.'];
        $token = self::token();
        q("INSERT INTO users (email,password_hash,name,verify_token,email_verified) VALUES (?,?,?,?,0)",
          [$email, self::hash($pw), mb_substr(trim((string)$name), 0, 120), self::tokenHash($token)]);
        $u = one("SELECT * FROM users WHERE id=?", [lastId()]);
        return ['user' => $u, 'token' => $token];
    }

    public static function logout() {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => 'Lax']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }

    /* ── current user / guards ───────────────────────────────────────── */
    public static function user() {
        static $u = false;
        if ($u === false) {
            $u = null;
            if (!empty($_SESSION['uid'])) {
                $u = one("SELECT * FROM users WHERE id=? AND status='active'", [$_SESSION['uid']]);
                if (!$u) { self::logout(); }
            }
        }
        return $u;
    }
    public static function requireLogin($to = 'login.php') {
        $u = self::user();
        if (!$u) { header('Location: ' . $to); exit; }
        return $u;
    }
    public static function requireAdmin() {
        $u = self::requireLogin();
        if ($u['role'] !== 'admin') { http_response_code(403); exit('Admins only.'); }
        return $u;
    }
    public static function isAdmin() { $u = self::user(); return $u && $u['role'] === 'admin'; }

    /* ── google sign-in (server-side code flow, no library) ──────────── */
    public static function googleEnabled() {
        $c = outlier_config();
        return !empty($c['google_client_id']) && !empty($c['google_client_secret']) && !empty($c['base_url']);
    }
    public static function googleUrl($scopes = 'openid email profile', $state = null) {
        $c = outlier_config();
        $_SESSION['g_state'] = $state ?: bin2hex(random_bytes(16));
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $c['google_client_id'], 'redirect_uri' => self::googleRedirect(),
            'response_type' => 'code', 'scope' => $scopes, 'state' => $_SESSION['g_state'],
            'access_type' => 'online', 'prompt' => 'select_account',
        ]);
    }
    public static function googleRedirect() {
        $c = outlier_config();
        return rtrim($c['base_url'] ?? '', '/') . '/google-auth.php';
    }
}

/* Every page calls this first. Errors never reach the browser as stack traces. */
function app_boot() {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(function ($e) {
        error_log('outlier: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        echo 'Something went wrong on our side. Please try again in a minute.';
        exit;
    });
    Auth::headers();
    Auth::boot();
    Auth::requireCsrf();
}
