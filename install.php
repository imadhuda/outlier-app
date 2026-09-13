<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Outlier — installer. Run once from the browser, then delete this file.
   Safe to re-run: it only creates what is missing.
   ═══════════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/engine.php';

$cli = (php_sapi_name() === 'cli');
$steps = []; $fatal = null;
function step($name, $status, $detail = '') { global $steps; $steps[] = compact('name','status','detail'); }

/* PHP keeps compiled files in memory on most cPanel hosts, so an edit to
   config.php can appear to do nothing. Drop it from the cache before loading. */
$cfgPath = __DIR__ . '/config.php';
$busted = false;
if (function_exists('opcache_invalidate') && is_file($cfgPath)) {
    $busted = @opcache_invalidate($cfgPath, true);
}
clearstatcache(true, $cfgPath);

/* Gate. Before the first admin exists: the admin_password from config.php,
   submitted by POST form — never in the URL. After that: an admin session. */
$diag = null; $needForm = false;
if (!$cli) {
    require_once __DIR__ . '/lib/auth.php';
    app_boot();
    try { $cfg = outlier_config(); } catch (Throwable $e) { $cfg = null; $fatal = $e->getMessage(); }
    $pw = (string)($cfg['admin_password'] ?? '');
    $adminEmail = strtolower(trim((string)($cfg['admin_email'] ?? '')));
    if (!$fatal && ($pw === '' || !$adminEmail)) $fatal = 'Set admin_email and admin_password in config.php first.';
    if (!$fatal && !Auth::passwordOk($pw)) $fatal = 'admin_password must be at least 10 characters with letters and numbers.';
    $haveAdmin = false;
    if (!$fatal) {
        try { $haveAdmin = (bool)one("SELECT id FROM users WHERE role='admin' LIMIT 1"); } catch (Throwable $e) { $haveAdmin = false; }
    }
    if (!$fatal) {
        if ($haveAdmin) {
            if (!Auth::isAdmin()) { header('Location: login.php'); exit; }
        } else {
            $given = (string)($_POST['admin_password'] ?? '');
            try { $throttled = !Auth::throttle('install', 5); } catch (Throwable $e) { $throttled = false; }   // table may not exist yet
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $throttled || !hash_equals($pw, $given)) $needForm = true;
        }
    }
    if ($needForm) {
        Auth::headers();
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Outlier — Installer</title><style>body{background:#0B0B0C;color:#F5F5F3;font:15px system-ui;display:grid;place-items:center;height:100vh;margin:0}'
           . 'form{background:#141416;border:1px solid #2A2A2F;border-radius:10px;padding:26px;width:360px}input{width:100%;padding:10px;margin:8px 0 14px;background:#1B1B1E;border:1px solid #2A2A2F;color:#fff;border-radius:7px;box-sizing:border-box}'
           . 'button{background:#FF6B1A;border:0;padding:10px 18px;border-radius:7px;font-weight:650;cursor:pointer}p{color:#8E8E98;font-size:13px}</style></head><body>'
           . '<form method="post">' . Auth::csrfField() . '<h2 style="margin:0 0 6px">Install Outlier</h2><p>Enter the admin_password from config.php. This creates the first admin login ('
           . htmlspecialchars($adminEmail) . ') and the database.</p>'
           . ($_SERVER['REQUEST_METHOD'] === 'POST' ? '<p style="color:#FF7E82">That did not match.</p>' : '')
           . '<input type="password" name="admin_password" autofocus><button>Install</button></form></body></html>';
        exit;
    }
}

if (!$fatal) {
    try {
        $pdo = db();
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        step('Database connection', 'pass', $ver);

        /* Prove utf8mb4 survives the round trip before creating anything. */
        $probe = "Boss — ye 'jalebi vs burger' test hai · 90% ✓";
        $pdo->exec("CREATE TABLE IF NOT EXISTS _outlier_charset_probe (id INT PRIMARY KEY, t TEXT)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $s = $pdo->prepare("REPLACE INTO _outlier_charset_probe (id,t) VALUES (1,?)");
        $s->execute([$probe]);
        $back = $pdo->query("SELECT t FROM _outlier_charset_probe WHERE id=1")->fetchColumn();
        $pdo->exec("DROP TABLE _outlier_charset_probe");
        step('utf8mb4 round trip', $back === $probe ? 'pass' : 'fail',
             $back === $probe ? 'Hinglish, em-dash and ✓ all survived' : 'text came back changed: ' . $back);

        $existing = [];
        foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) $existing[$t] = true;

        foreach (outlier_schema() as $table => $sql) {
            $had = isset($existing[$table]);
            $pdo->exec($sql);
            step("Table: $table", 'pass', $had ? 'already existed' : 'created');
        }

        /* Columns added after the first release. */
        $applied = 0;
        foreach (outlier_migrations() as [$table, $col, $sql]) {
            $has = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $has->execute([$table, $col]);
            if (!(int)$has->fetchColumn()) { $pdo->exec($sql); $applied++; }
        }
        step('Schema migrations', 'pass', $applied ? "$applied column(s) added" : 'already up to date');

        /* Verify every table actually landed as utf8mb4, whatever the DB default is. */
        $bad = [];
        $rows = $pdo->query("SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = DATABASE()")->fetchAll();
        foreach ($rows as $r) {
            if (strpos((string)$r['TABLE_COLLATION'], 'utf8mb4') !== 0) $bad[] = $r['TABLE_NAME'];
        }
        step('All tables are utf8mb4', $bad ? 'fail' : 'pass',
             $bad ? 'not utf8mb4: ' . implode(', ', $bad) : count($rows) . ' tables checked');

        /* Foreign keys must actually be enforced — InnoDB only. */
        $fk = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                           WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY'")->fetchColumn();
        step('Foreign keys active', $fk >= 3 ? 'pass' : 'warn', $fk . ' constraints');

        $pdo->prepare("INSERT INTO settings (k,v) VALUES ('schema_version','1')
                       ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute();
        $pdo->prepare("INSERT INTO settings (k,v) VALUES ('installed_at',?)
                       ON DUPLICATE KEY UPDATE v=v")->execute([gmdate('c')]);
        step('Settings seeded', 'pass', 'schema_version 1');

        foreach (outlier_seed() as $sql) $pdo->exec($sql);
        step('Plans seeded', 'pass', 'trial, starter, growth, unlimited');

        /* First admin login, from config. Existing admins are never touched. */
        $cfgA = outlier_config();
        $adminEmail = strtolower(trim((string)($cfgA['admin_email'] ?? '')));
        if ($adminEmail !== '') {
            require_once __DIR__ . '/lib/auth.php';
            $ex = one("SELECT * FROM users WHERE email=?", [$adminEmail]);
            if (!$ex) {
                q("INSERT INTO users (email,password_hash,name,role,email_verified) VALUES (?,?,?,'admin',1)",
                  [$adminEmail, Auth::hash((string)$cfgA['admin_password']), 'Admin']);
                $aid = lastId();
                step('Admin login', 'pass', "created $adminEmail");
            } else {
                $aid = (int)$ex['id'];
                if ($ex['role'] !== 'admin') q("UPDATE users SET role='admin', email_verified=1 WHERE id=?", [$aid]);
                step('Admin login', 'pass', "$adminEmail already exists" . ($ex['role'] !== 'admin' ? ' — promoted to admin' : ''));
            }
            /* Attach any pre-existing customer profile with that email to the admin. */
            $n = q("UPDATE customers SET user_id=?, onboarded=1, ig_verified=1, onboarded_at=COALESCE(onboarded_at, UTC_TIMESTAMP())
                    WHERE email=? AND user_id IS NULL", [$aid, $adminEmail])->rowCount();
            if ($n) step('Admin customer profile', 'pass', 'linked existing profile to the admin login');
        } else step('Admin login', 'fail', 'admin_email missing in config.php');

        /* Round-trip a real customer through every table, then clean up. */
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO customers (name,email,ig_handle,yt_channel,niche,competitors,trial_start,trial_end)
                       VALUES (?,?,?,?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
            ->execute(['Install Probe','probe@example.invalid','probehandle','@probetv','UAE real estate — Hinglish ✓',
                       json_encode(['jake_nazer','thenathanmistry'])]);
        $cid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO handle_ledger (platform,handle,customer_id) VALUES ('instagram',?,?)")
            ->execute(['probehandle', $cid]);
        $pdo->prepare("INSERT INTO runs (customer_id,status) VALUES (?,'done')")->execute([$cid]);
        $rid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO reels (run_id,customer_id,handle,code,plays,views,velocity,flag,is_outlier,hook)
                       VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$rid,$cid,'jake_nazer','PROBE1',45996,20264,6571.0,'normal',1,
                       'How much is rent dropping in Dubai? — the September update']);
        $pdo->prepare("INSERT INTO scripts (run_id,customer_id,idx,hook_line,body,cta_keyword)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$rid,$cid,1,'Boss, 35,200 brokers hain','Comment karo BROKER — ✓','BROKER']);
        $pdo->prepare("INSERT INTO jobs (customer_id,run_id,step,payload) VALUES (?,?,'scrape',?)")
            ->execute([$cid,$rid,json_encode(['handles'=>['jake_nazer']])]);

        $chk = $pdo->prepare("SELECT niche FROM customers WHERE id=?"); $chk->execute([$cid]);
        $niche = $chk->fetchColumn();
        step('Insert across all tables', 'pass', 'customer, ledger, run, reel, script, job');
        step('Unicode stored in a real row', $niche === 'UAE real estate — Hinglish ✓' ? 'pass' : 'fail', $niche);

        /* Deleting the customer must cascade to runs, reels, scripts. */
        $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$cid]);
        $left = 0;
        foreach (['runs','reels','scripts'] as $t) {
            $s2 = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE customer_id=?"); $s2->execute([$cid]);
            $left += (int)$s2->fetchColumn();
        }
        step('Cascade delete works', $left === 0 ? 'pass' : 'fail', $left === 0 ? 'child rows removed' : "$left rows orphaned");

        /* The ledger must SURVIVE the customer being deleted — that is the trial check. */
        $s3 = $pdo->prepare("SELECT COUNT(*) FROM handle_ledger WHERE handle=?"); $s3->execute(['probehandle']);
        $ledgerKept = (int)$s3->fetchColumn() === 1;
        step('Handle ledger survives deletion', $ledgerKept ? 'pass' : 'fail',
             $ledgerKept ? 'a deleted customer cannot re-trial' : 'ledger row vanished — repeat trials would be possible');

        /* A duplicate handle must be rejected outright. */
        $dup = false;
        try { $pdo->prepare("INSERT INTO handle_ledger (platform,handle) VALUES ('instagram',?)")->execute(['probehandle']); }
        catch (Throwable $e) { $dup = true; }
        step('Duplicate handle rejected', $dup ? 'pass' : 'fail', $dup ? 'unique index enforced' : 'duplicate was allowed');

        $pdo->rollBack();
        step('Probe data rolled back', 'pass', 'nothing left behind');

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $fatal = $e->getMessage();
    }
}

/* ── engine self-test ───────────────────────────────────────────────────── */
$engPass = 0; $engFail = 0; $engLines = [];
if (!$fatal && is_file(__DIR__ . '/tests/engine_test.php')) {
    require __DIR__ . '/tests/engine_test.php';
    $engPass = $GLOBALS['pass']; $engFail = $GLOBALS['fail']; $engLines = $GLOBALS['lines'];
    step('Analysis engine self-test', $engFail === 0 ? 'pass' : 'fail', "$engPass passed, $engFail failed");
}

$fails = 0; $warns = 0;
foreach ($steps as $s) { if ($s['status']==='fail') $fails++; if ($s['status']==='warn') $warns++; }

if ($cli) {
    if ($fatal) { echo "FATAL: $fatal\n"; exit(1); }
    foreach ($steps as $s) printf("  %-5s %-34s %s\n", strtoupper($s['status']), $s['name'], $s['detail']);
    echo "\n" . ($fails ? "FAILURES: $fails" : 'ALL PASS') . "\n";
    exit($fails ? 1 : 0);
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Outlier — Installer</title><style>
:root{--bg:#0B0B0C;--panel:#141416;--line:#2A2A2F;--ink:#F5F5F3;--mute:#8E8E98;--dim:#5E5E68;--orange:#FF6B1A;--green:#3BB273;--red:#E5484D;--amber:#F5A524}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 "Inter","Segoe UI",system-ui,sans-serif}
.wrap{max-width:860px;margin:0 auto;padding:32px 20px 60px}h1{font-size:22px;margin:0 0 4px;letter-spacing:-.02em}
.sub{color:var(--mute);font-size:13.5px;margin-bottom:24px}
.v{border-radius:12px;padding:22px 24px;margin-bottom:26px;border:1px solid}
.ok{background:rgba(59,178,115,.10);border-color:rgba(59,178,115,.4)}
.bad{background:rgba(229,72,77,.10);border-color:rgba(229,72,77,.4)}
.v h2{margin:0 0 8px;font-size:18px}.v p{margin:0;font-size:14px;color:var(--mute)}
h3{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--mute);margin:26px 0 10px}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow:hidden}
td{padding:11px 14px;border-bottom:1px solid #202024;font-size:13.5px;vertical-align:top}tr:last-child td{border-bottom:0}
td.s{width:64px}td.n{width:250px;font-weight:600}td.d{color:var(--mute);font-family:"SF Mono",Menlo,Consolas,monospace;font-size:12.5px;word-break:break-word}
.b{display:inline-block;font-size:10.5px;font-weight:750;padding:3px 8px;border-radius:20px}
.b-pass{background:rgba(59,178,115,.16);color:#5FD39A}.b-fail{background:rgba(229,72,77,.16);color:#FF7E82}
.b-warn{background:rgba(245,165,36,.16);color:#F5C87A}
details{margin-top:14px}summary{cursor:pointer;color:var(--mute);font-size:13px}
.eng{font-family:"SF Mono",Menlo,Consolas,monospace;font-size:12px;line-height:1.75;color:var(--dim);margin-top:10px}
.eng .p{color:#5FD39A}.eng .f{color:#FF7E82}.eng .sec{color:var(--orange);margin-top:8px;display:block}
footer{margin-top:30px;padding-top:18px;border-top:1px solid var(--line);color:var(--amber);font-size:13px}
</style></head><body><div class="wrap">
<h1>Outlier — Installer</h1><div class="sub">Database structure and engine self-check.</div>
<?php if ($fatal): ?>
<div class="v bad"><h2>Install failed</h2><p><?=htmlspecialchars($fatal)?></p></div>
<?php if ($diag): ?>
<h3>What PHP actually sees</h3><table>
<?php foreach ($diag as $k => $v): ?>
<tr><td class="n"><?=htmlspecialchars($k)?></td><td class="d"><?=htmlspecialchars($v)?></td></tr>
<?php endforeach; ?>
</table>
<p style="color:var(--amber);font-size:13.5px;margin-top:14px">If opcache says ENABLED above, just reload this page once — the cached copy has been dropped.</p>
<?php endif; ?>
<?php elseif ($fails): ?>
<div class="v bad"><h2><?=$fails?> check<?=$fails>1?'s':''?> failed</h2><p>Fix these before going further — the details are below.</p></div>
<?php else: ?>
<div class="v ok"><h2>Installed and verified</h2><p>All tables created, unicode round-trips cleanly, cascades and the trial ledger behave correctly, and the analysis engine passed <?=$engPass?> self-tests.</p></div>
<?php endif; ?>
<?php if ($steps): ?><h3>Checks</h3><table>
<?php foreach ($steps as $s): ?><tr><td class="s"><span class="b b-<?=$s['status']?>"><?=strtoupper($s['status'])?></span></td>
<td class="n"><?=htmlspecialchars($s['name'])?></td><td class="d"><?=htmlspecialchars($s['detail'])?></td></tr><?php endforeach; ?>
</table><?php endif; ?>
<?php if ($engLines): ?><details><summary>Show all <?=$engPass+$engFail?> engine tests</summary><div class="eng">
<?php foreach ($engLines as [$k,$n,$x]): ?>
<?php if ($k==='sec'): ?><span class="sec"><?=htmlspecialchars($n)?></span><?php else: ?>
<span class="<?=$k==='pass'?'p':'f'?>"><?=strtoupper($k)?></span> <?=htmlspecialchars($n)?><?=$x?' — '.htmlspecialchars($x):''?><br>
<?php endif; ?><?php endforeach; ?>
</div></details><?php endif; ?>
<footer>Now <a href="login.php" style="color:#FF6B1A">log in</a> with admin_email / admin_password from config.php — then delete install.php from the server.</footer>
</div></body></html>
