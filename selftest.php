<?php
/* Live connectivity check. Uses real keys but the cheapest possible calls:
   an identity lookup on Apify, a two-token message to the model. */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/pipeline.php';
require_once __DIR__ . '/lib/ui.php';
app_boot();
Auth::requireAdmin();
$cfg = outlier_config();
$CHECKS = [];
function chk($name,$status,$detail,$why=''){ global $CHECKS; $CHECKS[]=compact('name','status','detail','why'); }

/* config */
chk('Apify token', !empty($cfg['apify_token'])?'pass':'fail',
    !empty($cfg['apify_token'])?'set ('.strlen($cfg['apify_token']).' chars)':'missing', 'Scraping cannot run without it.');
chk('Anthropic key', !empty($cfg['anthropic_key'])?'pass':'fail',
    !empty($cfg['anthropic_key'])?'set ('.strlen($cfg['anthropic_key']).' chars)':'missing', 'Script generation cannot run without it.');
chk('Resend key', !empty($cfg['resend_key'])?'pass':'warn',
    !empty($cfg['resend_key'])?'set':'not set', empty($cfg['resend_key'])?'Runs still work; no email is sent.':'');

/* database */
try { $v = db()->query('SELECT VERSION()')->fetchColumn(); chk('Database','pass',$v); }
catch (Throwable $e) { chk('Database','fail',$e->getMessage()); }

/* apify identity + credit */
if (!empty($cfg['apify_token'])) {
    try {
        $d = Http::json('GET','https://api.apify.com/v2/users/me?token='.urlencode($cfg['apify_token']),[],null,25);
        $u = $d['data']['username'] ?? '?';
        chk('Apify account','pass','authenticated as '.$u);
        $plan = $d['data']['plan']['id'] ?? null;
        if ($plan) chk('Apify plan','pass',$plan);
    } catch (HttpError $e) {
        chk('Apify account','fail','HTTP '.$e->status.' — '.substr($e->body,0,120),
            $e->status===401?'The token is wrong or was revoked.':'');
    } catch (Throwable $e) { chk('Apify account','fail',$e->getMessage()); }
}

/* anthropic — a real but tiny call */
if (!empty($cfg['anthropic_key'])) {
    try {
        $c = new Claude($cfg['anthropic_key']);
        $t0 = microtime(true);
        $out = $c->complete('Reply with exactly: OK', 'ping', 16);
        $ms = round((microtime(true)-$t0)*1000);
        $good = stripos($out['text'],'OK') !== false;
        chk('Anthropic API', $good?'pass':'warn',
            ($good?'responded':'responded oddly: '.substr(trim($out['text']),0,40)).' · '.$ms.' ms'
            .' · '.($out['usage']['input_tokens']??0).' in / '.($out['usage']['output_tokens']??0).' out');
    } catch (HttpError $e) {
        chk('Anthropic API','fail','HTTP '.$e->status.' — '.substr($e->body,0,160),
            $e->status===401?'The key is wrong.':($e->status===400?'Check the account has credit.':''));
    } catch (Throwable $e) { chk('Anthropic API','fail',$e->getMessage()); }
}

/* resend */
if (!empty($cfg['resend_key'])) {
    try { Http::json('GET','https://api.resend.com/domains',['Authorization'=>'Bearer '.$cfg['resend_key']],null,20);
          chk('Resend API','pass','authenticated'); }
    catch (HttpError $e) { chk('Resend API','fail','HTTP '.$e->status, 'Email will be skipped.'); }
    catch (Throwable $e) { chk('Resend API','fail',$e->getMessage()); }
}

/* worker plumbing */
$w = __DIR__.'/worker.log';
$writable = @file_put_contents($w, '', FILE_APPEND) !== false;
chk('Worker log writable', $writable?'pass':'warn', $writable?$w:'cannot write', $writable?'':'The worker still runs; you just lose the log.');
$st = Queue::stats();
chk('Queue', 'pass', ($st['pending']??0).' pending, '.($st['working']??0).' working, '
    .($st['done']??0).' done, '.($st['failed']??0).' failed');
$phpbin = null;
foreach (['/usr/local/bin/php','/usr/bin/php'] as $p) if (@is_executable($p)) { $phpbin=$p; break; }
chk('PHP binary for cron', $phpbin?'pass':'warn', $phpbin ?: 'not found at the usual paths');
chk('Server time','info', gmdate('Y-m-d H:i:s').' UTC — Dubai is UTC+4, so 9am there is 05:00 here');

/* security posture */
chk('HTTPS', Auth::https()?'pass':'fail', Auth::https()?'on — cookies are Secure':'off — session cookies would travel in clear', Auth::https()?'':'Serve the app over https only.');
chk('Google sign-in', Auth::googleEnabled()?'pass':'warn', Auth::googleEnabled()?'configured':'not configured — email/password only', Auth::googleEnabled()?'':'Set google_client_id, google_client_secret and base_url in config.php.');
chk('base_url', !empty($cfg['base_url'])?'pass':'fail', $cfg['base_url'] ?? 'missing', empty($cfg['base_url'])?'Verification and reset emails need it.':'');
chk('install.php removed', is_file(__DIR__.'/install.php')?'warn':'pass', is_file(__DIR__.'/install.php')?'still present':'gone', is_file(__DIR__.'/install.php')?'Delete it once the admin login works.':'');
$leftover = array_filter(['check2.php','hello.php','outlier-check.php','config.sample.php'], fn($f) => is_file(__DIR__.'/'.$f));
chk('Stray files', $leftover?'warn':'pass', $leftover?implode(', ',$leftover):'none', $leftover?'Delete these from the server.':'');
$adm = one("SELECT COUNT(*) n FROM users WHERE role='admin' AND status='active'");
chk('Admin logins', (int)$adm['n']>0?'pass':'fail', (int)$adm['n'].' active admin(s)');
chk('Password hashing', defined('PASSWORD_ARGON2ID')?'pass':'warn', defined('PASSWORD_ARGON2ID')?'Argon2id':'bcrypt (Argon2 not compiled in)');
$ht = @file_get_contents(__DIR__.'/.htaccess');
$libHt = @file_get_contents(__DIR__.'/lib/.htaccess');
$testHt = @file_get_contents(__DIR__.'/tests/.htaccess');
$rootOk = $ht && stripos($ht,'config')!==false && stripos($ht,'.log')!==false && stripos($ht,'denied')!==false;
$libOk  = $libHt && stripos($libHt,'denied')!==false;
$testOk = $testHt && stripos($testHt,'denied')!==false;
chk('.htaccess guards', ($rootOk && $libOk && $testOk)?'pass':'fail',
    ($rootOk?'root ok':'ROOT MISSING').', '.($libOk?'lib/ ok':'LIB MISSING').', '.($testOk?'tests/ ok':'TESTS MISSING'),
    'Root blocks config.php + logs; lib/ and tests/ deny all. If this fails, upload the .htaccess files.');
/* Prove it for real: fetch config.php and lib/db.php over HTTP and confirm they are not served. */
$self = (Auth::https()?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost');
$probe = function($path) use ($self) {
    $ctx = stream_context_create(['http'=>['timeout'=>8,'ignore_errors'=>true],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $body = @file_get_contents($self.$path, false, $ctx);
    $code = 0; foreach ($http_response_header ?? [] as $h) if (preg_match('#HTTP/\S+ (\d+)#',$h,$m)) $code=(int)$m[1];
    return [$code, (string)$body];
};
[$cc,$cb] = $probe('/config.php');
chk('config.php not served', ($cc===403 || $cc===404 || ($cc===200 && stripos($cb,'apify')===false && stripos($cb,'db')===false && trim($cb)===''))?'pass':'fail',
    'HTTP '.$cc.($cc===200?' but empty body':''), $cc===200 && trim($cb)!=='' ? 'If your keys are visible above, the .htaccess is NOT active — contact the host.' : '');
[$lc,] = $probe('/lib/db.php');
chk('lib/ not served', ($lc===403 || $lc===404)?'pass':'warn', 'HTTP '.$lc, $lc===200?'lib/ is reachable — check lib/.htaccess uploaded.':'');

/* engine — run inside a function so the suite's own variables stay out of this
   file's scope. It sets $GLOBALS['pass'] / ['fail'] itself, which survive. */
function run_engine_suite() {
    ob_start();
    require __DIR__ . '/tests/engine_test.php';
    ob_end_clean();
    return [$GLOBALS['pass'] ?? 0, $GLOBALS['fail'] ?? 0];
}
[$ePass, $eFail] = run_engine_suite();
chk('Analysis engine', $eFail===0?'pass':'fail', $ePass.' passed, '.$eFail.' failed');

$fails=0;$warns=0; foreach($CHECKS as $x){ if($x['status']==='fail')$fails++; if($x['status']==='warn')$warns++; }
head('Self-test', adminNav());
echo '<h1>Self-test</h1><div class="sub">Live checks against the real services. The model call costs a fraction of a cent.</div>';
echo $fails ? '<div class="msg m-bad"><b>'.$fails.' check'.($fails>1?'s':'').' failed.</b> Fix these before running a customer.</div>'
            : '<div class="msg m-ok"><b>Everything critical passed.</b>'.($warns?' '.$warns.' note'.($warns>1?'s':'').' worth reading.':'').'</div>';
echo '<div class="card"><table>';
foreach ($CHECKS as $x) {
    $cls = ['pass'=>'c-ok','fail'=>'c-bad','warn'=>'c-warn','info'=>'c-dim'][$x['status']];
    echo '<tr><td style="width:70px"><span class="chip '.$cls.'">'.strtoupper($x['status']).'</span></td>'
       . '<td style="width:190px;font-weight:600">'.e($x['name']).'</td>'
       . '<td style="font-family:\'SF Mono\',Menlo,monospace;font-size:12.5px;color:var(--mute)">'.e($x['detail'])
       . ($x['why'] ? '<div style="color:var(--amber);margin-top:5px;font-family:inherit">'.e($x['why']).'</div>' : '')
       . '</td></tr>';
}
echo '</table></div>';
$phpbinShow = $phpbin ?: '/usr/local/bin/php';
echo '<div class="card"><h2>Cron command</h2><div class="sub">cPanel → Cron Jobs → every 5 minutes.</div>'
   . '<pre>'.e($phpbinShow.' -q '.__DIR__.'/worker.php >/dev/null 2>&1').'</pre>'
   . '<div class="sub" style="margin:12px 0 0">Common Settings: “Every 5 Minutes” (<code>*/5 * * * *</code>). '
   . 'The worker is a no-op when the queue is empty, so a frequent tick costs nothing.</div></div>';
foot();
