<?php
/* Every page over real HTTP with real sessions: signup → onboarding → dashboard,
   admin panel, and — most importantly — that nobody can see anyone else's data,
   that every POST needs a CSRF token, and that nothing is reachable by URL key. */
$BASE='http://127.0.0.1:8951';
$pass=0;$fail=0;
function ok($n,$c,$x=null){ global $pass,$fail;
  if($c){$pass++; echo "  PASS  $n\n";} else {$fail++; echo "  FAIL  $n".($x===null?'':'  -> '.substr(is_string($x)?$x:json_encode($x),0,300))."\n";} }
function sec($t){ echo "\n— $t —\n"; }

/* A browser: cookie jar + CSRF extraction. */
class B {
  public $jar=[]; public $code=0; public $headers=[]; public $body=''; public $tok=null;
  function req($m,$p,$data=null,$extra=''){
    $h="Cookie: ".implode('; ',array_map(fn($k,$v)=>"$k=$v",array_keys($this->jar),$this->jar))."\r\n".$extra;
    $o=['http'=>['method'=>$m,'header'=>$h,'ignore_errors'=>true,'timeout'=>90,'follow_location'=>0]];
    if($m==='POST'){ $o['http']['header'].="Content-Type: application/x-www-form-urlencoded\r\n"; $o['http']['content']=http_build_query($data?:[]); }
    $this->body=(string)@file_get_contents($GLOBALS['BASE'].$p,false,stream_context_create($o));
    $this->headers=$http_response_header??[]; $this->code=0;
    foreach($this->headers as $x){ if(preg_match('#^HTTP/\S+ (\d+)#',$x,$mm)) $this->code=(int)$mm[1];
      if(preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i',$x,$mm)) { if($mm[2]==='' || $mm[2]==='deleted') unset($this->jar[$mm[1]]); else $this->jar[$mm[1]]=$mm[2]; } }
    if(preg_match('/name="_csrf" value="([^"]+)"/',$this->body,$mm)) $this->tok=$mm[1];   // remember the live token
    return $this;
  }
  function get($p){ return $this->req('GET',$p); }
  function csrf(){ return $this->tok; }
  function freshToken(){ $this->tok=null; $this->get('/onboard.php?step=accounts'); if(!$this->tok) $this->get('/login.php'); return $this->tok; }
  function post($p,$d,$withCsrf=true){ if($withCsrf){
      /* login/logout/signup cross a session boundary — the old token is dead, so always refetch. */
      if(preg_match('#/(login|logout|signup)\.php#',$p)){ $this->tok=null; $this->get($p); }
      elseif(!$this->tok){ $this->get($p); }
      $d['_csrf']=$this->tok; }
    return $this->req('POST',$p,$d,"Origin: http://127.0.0.1:8951\r\n"); }
  function loc(){ foreach($this->headers as $x) if(preg_match('/^Location:\s*(.+)$/i',$x,$m)) return trim($m[1]); return null; }
  function h($n){ foreach($this->headers as $x) if(stripos($x,$n.':')===0) return trim(substr($x,strlen($n)+1)); return null; }
}

require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/billing.php';
q("DELETE FROM jobs"); q("DELETE FROM customers"); q("DELETE FROM handle_ledger"); q("DELETE FROM subscriptions");
q("DELETE FROM plan_requests"); q("DELETE FROM promo_codes"); q("DELETE FROM login_attempts"); q("DELETE FROM users WHERE role<>'admin'");
$ADMIN_EMAIL='admin@example.test'; $ADMIN_PW='AdminPass12345';

sec('Nothing is reachable without a session');
$b=new B();
foreach(['/admin.php','/app.php','/plan.php','/onboard.php','/export.php?run=1','/selftest.php','/install.php'] as $p){
  $b->get($p); ok("$p redirects to login", $b->code===302 && strpos((string)$b->loc(),'login.php')!==false, [$p,$b->code,$b->loc()]);
}
$b->get('/admin.php?key='.$ADMIN_PW); ok('a URL key no longer opens admin', $b->code===302, $b->code);
$b->get('/worker.php'); ok('worker.php is not served over HTTP', $b->code===404, $b->code);
$b->get('/config.php'); ok('config.php returns nothing useful', $b->body==='' , substr($b->body,0,60));
$b->get('/index.php'); ok('index redirects to login', $b->code===302 && strpos((string)$b->loc(),'login.php')!==false);

sec('Security headers');
$b->get('/login.php');
ok('login renders', $b->code===200 && strpos($b->body,'Log in')!==false, $b->code);
ok('X-Frame-Options DENY', $b->h('X-Frame-Options')==='DENY', $b->h('X-Frame-Options'));
ok('nosniff', $b->h('X-Content-Type-Options')==='nosniff');
ok('CSP present with nonce, no unsafe-inline scripts', ($csp=$b->h('Content-Security-Policy')) && strpos($csp,"script-src 'nonce-")!==false && strpos($csp,"frame-ancestors 'none'")!==false && strpos($csp,"script-src 'self' 'unsafe-inline'")===false, $csp);
ok('Cache-Control no-store', stripos((string)$b->h('Cache-Control'),'no-store')!==false);
$fresh=new B(); $fresh->get('/login.php');
ok('session cookie is HttpOnly + SameSite', (bool)array_filter($fresh->headers, fn($x)=>stripos($x,'Set-Cookie: outlier_s')===0 && stripos($x,'httponly')!==false && stripos($x,'samesite=lax')!==false), $fresh->headers);
ok('no X-Powered-By', $b->h('X-Powered-By')===null);

sec('CSRF');
$b->req('POST','/login.php',['email'=>$ADMIN_EMAIL,'password'=>$ADMIN_PW]);
ok('POST without token is refused (403)', $b->code===403, $b->code);
$b->get('/login.php'); $tok=$b->csrf();
$b->req('POST','/login.php',['email'=>$ADMIN_EMAIL,'password'=>$ADMIN_PW,'_csrf'=>'bogus']);
ok('wrong token refused', $b->code===403, $b->code);
$b->req('POST','/login.php',['email'=>$ADMIN_EMAIL,'password'=>$ADMIN_PW,'_csrf'=>$tok],"Origin: https://evil.example\r\n");
ok('cross-origin POST refused even with a valid token', $b->code===403, $b->code);

sec('Login rate limiting');
$b=new B();
for($i=0;$i<5;$i++) $b->post('/login.php',['email'=>'nobody@example.test','password'=>'wrongwrong1']);
ok('wrong password shows a uniform message', strpos($b->body,'Email or password is wrong')!==false);
$b->post('/login.php',['email'=>'nobody@example.test','password'=>'wrongwrong1']);
ok('6th attempt is locked', strpos($b->body,'Too many attempts')!==false, substr(strip_tags($b->body),0,200));
$b->post('/login.php',['email'=>$ADMIN_EMAIL,'password'=>$ADMIN_PW]);
ok('lock is per IP too — even the right password is refused', strpos($b->body,'Too many attempts')!==false);
q("DELETE FROM login_attempts");

sec('Admin login');
$A=new B();
$A->post('/login.php',['email'=>$ADMIN_EMAIL,'password'=>$ADMIN_PW]);
ok('admin login redirects to admin.php', $A->code===302 && $A->loc()==='admin.php', [$A->code,$A->loc()]);
$A->get('/admin.php'); ok('admin panel renders', $A->code===200 && strpos($A->body,'Customers')!==false, $A->code);
ok('no PHP fatal', stripos($A->body,'Fatal error')===false && stripos($A->body,'Warning:')===false);
ok('admin nav has no key in links', strpos($A->body,'?key=')===false);
$A->get('/selftest.php'); ok('selftest renders for admin', $A->code===200 && strpos($A->body,'Self-test')!==false, $A->code);
ok('selftest includes the engine suite', strpos($A->body,'55 passed, 0 failed')!==false);
ok('selftest reports security posture', strpos($A->body,'.htaccess guards')!==false && strpos($A->body,'Admin logins')!==false);
$A->get('/install.php'); ok('install renders for admin (no key)', $A->code===200 && strpos($A->body,'Installed and verified')!==false, $A->code);
$A->get('/app.php'); ok('admin without a profile is sent to onboarding', $A->code===302 && $A->loc()==='onboard.php', [$A->code,$A->loc()]);

sec('Customer signup → onboarding');
$C=new B();
$C->post('/signup.php',['name'=>'Test Broker','email'=>'broker@example.test','password'=>'short1']);
ok('weak password rejected', strpos($C->body,'at least 10 characters')!==false);
$C->post('/signup.php',['name'=>'Test Broker','email'=>'not-an-email','password'=>'GoodPass12345']);
ok('bad email rejected', strpos($C->body,'not valid')!==false);
$C->post('/signup.php',['name'=>'Test Broker','email'=>'Broker@Example.test','password'=>'GoodPass12345']);
ok('signup without email service logs in and goes to onboarding', $C->code===302 && $C->loc()==='onboard.php', [$C->code,$C->loc(),substr(strip_tags($C->body),0,200)]);
$u=one("SELECT * FROM users WHERE email='broker@example.test'");
ok('email stored lowercase, verified, customer role', $u && $u['email_verified']==1 && $u['role']==='customer');
ok('password is hashed with argon2/bcrypt', $u && preg_match('/^\$(argon2id|2y)\$/',$u['password_hash']));
ok('trial subscription created', Billing::current($u['id'])['status']==='trial');
$C2=new B(); $C2->post('/signup.php',['name'=>'Dup','email'=>'broker@example.test','password'=>'GoodPass12345']);
ok('duplicate email refused', strpos($C2->body,'already exists')!==false);
$C->get('/app.php'); ok('dashboard refuses until onboarded', $C->code===302 && $C->loc()==='onboard.php');
$C->get('/onboard.php'); ok('wizard starts on the welcome step', $C->code===200 && strpos($C->body,'Step 1 of')!==false && strpos($C->body,'Welcome to Outlier')!==false, [$C->code,substr(strip_tags($C->body),0,120)]);
$C->get('/onboard.php?step=accounts'); ok('accounts step shows the form', $C->code===200 && strpos($C->body,'Your accounts and niche')!==false && $C->csrf()!==null);
$C->tok=null; $C->freshToken();   // stable session token for the save posts below
$C->get('/admin.php'); ok('customer gets 403 on admin', $C->code===403, $C->code);
$C->get('/selftest.php'); ok('customer gets 403 on selftest', $C->code===403, $C->code);
$C->get('/install.php'); ok('customer gets bounced from install', $C->code===302, $C->code);

$C->post('/onboard.php',['action'=>'save','ig_handle'=>'https://www.instagram.com/TestBroker?x=1','competitors'=>'jake_nazer, @nathan','niche'=>'UAE real estate — brokers','language'=>'Hinglish','duration_pref'=>'45-50 sec']);
ok('onboarding saved', strpos($C->body,'Saved.')!==false, substr(strip_tags($C->body),0,300));
$c=one("SELECT * FROM customers WHERE user_id=?",[$u['id']]);
ok('handle cleaned and stored', $c && $c['ig_handle']==='testbroker' && $c['duration_pref']==='45-50 sec' && json_decode($c['competitors'],true)===['jake_nazer','nathan'], $c);
ok('instagram is stored as a platform by default', strpos((string)$c['platforms'],'instagram')!==false, $c['platforms']);
$C->post('/onboard.php',['action'=>'save','ig_handle'=>'testbroker','competitors'=>'testbroker','niche'=>'UAE real estate','language'=>'Hinglish','duration_pref'=>'60 sec']);
ok('own handle as competitor refused', strpos($C->body,'listed as a competitor')!==false);
$C->post('/onboard.php',['action'=>'save','ig_handle'=>'testbroker','competitors'=>'a,b,c,d,e,f,g','niche'=>'UAE real estate','language'=>'Hinglish','duration_pref'=>'60 sec']);
ok('more than six competitors refused', strpos($C->body,'Six competitors')!==false);
$C->post('/onboard.php',['action'=>'verify_ig']);
ok('verification code issued', preg_match('/OUT-[A-Z0-9]{5}/',$C->body), substr(strip_tags($C->body),0,200));
$C->get('/app.php'); ok('still not onboarded before verification', $C->code===302);

sec('Repeat-trial protection');
q("INSERT INTO handle_ledger (platform,handle,customer_id) VALUES ('instagram','takenhandle',NULL)");
$C->post('/onboard.php',['action'=>'save','ig_handle'=>'takenhandle','competitors'=>'jake_nazer','niche'=>'UAE real estate','language'=>'Hinglish','duration_pref'=>'60 sec']);
ok('a handle that already had a trial is refused', strpos($C->body,'already registered')!==false, substr(strip_tags($C->body),0,200));

sec('Admin verifies the customer by hand');
$A->post('/admin.php',['action'=>'trust_ig','customer_id'=>$c['id']]);
ok('admin marked verified', strpos($A->body,'marked verified')!==false, substr(strip_tags($A->body),0,200));
$c=one("SELECT * FROM customers WHERE id=?",[$c['id']]);
ok('customer onboarded + ledgered', $c['ig_verified']==1 && $c['onboarded']==1 && one("SELECT id FROM handle_ledger WHERE handle='testbroker'"));
$C->get('/app.php'); ok('dashboard renders now', $C->code===200 && strpos($C->body,'Trial: videos in last 7 days')!==false, [$C->code,substr(strip_tags($C->body),0,150)]);
ok('dashboard shows the how-it-works journey', strpos($C->body,'How it works — where you are now')!==false);
ok('dashboard offers the posting check', strpos($C->body,'Check my posts now')!==false);
$C->get('/onboard.php'); ok('once live, onboard.php becomes Settings', strpos($C->body,'>Settings<')!==false || strpos($C->body,'Accounts &amp; niche')!==false || strpos($C->body,'Accounts & niche')!==false, substr(strip_tags($C->body),0,150));

sec('Data isolation');
/* Two customers, one run each. Neither may open the other's batch or export. */
$D=new B(); $D->post('/signup.php',['name'=>'Other','email'=>'other@example.test','password'=>'GoodPass12345']);
$u2=one("SELECT * FROM users WHERE email='other@example.test'");
q("INSERT INTO customers (user_id,name,email,ig_handle,niche,competitors,onboarded,ig_verified,onboarded_at) VALUES (?,'Other','other@example.test','otherhandle','x',?,1,1,UTC_TIMESTAMP())",[$u2['id'],json_encode(['a'])]);
$c2=lastId();
q("INSERT INTO runs (customer_id,status,scripts_delivered,narrative) VALUES (?,'done',1,'SECRET-NARRATIVE-A')",[$c['id']]); $runA=lastId();
q("INSERT INTO scripts (run_id,customer_id,idx,hook_line,body) VALUES (?,?,1,'SECRET-HOOK-A','body a')",[$runA,$c['id']]);
q("INSERT INTO runs (customer_id,status,scripts_delivered,narrative) VALUES (?,'done',1,'SECRET-NARRATIVE-B')",[$c2]); $runB=lastId();
q("INSERT INTO scripts (run_id,customer_id,idx,hook_line,body) VALUES (?,?,1,'SECRET-HOOK-B','body b')",[$runB,$c2]);
$C->get("/app.php?run=$runA"); ok('owner sees own batch', $C->code===200 && strpos($C->body,'SECRET-HOOK-A')!==false, $C->code);
$C->get("/app.php?run=$runB"); ok('cannot open another customer\'s batch', $C->code===404 && strpos($C->body,'SECRET')===false, [$C->code]);
$C->get("/export.php?run=$runB"); ok('cannot export another customer\'s batch', $C->code===404, $C->code);
$C->get("/export.php?run=$runA"); ok('can export own batch', $C->code===200 && strpos((string)$C->h('Content-Type'),'spreadsheetml')!==false, [$C->code,$C->h('Content-Type')]);
$C->get('/app.php'); ok('dashboard lists only own batches', strpos($C->body,"app.php?run=$runA")!==false && strpos($C->body,"app.php?run=$runB")===false);
$D->get("/app.php?run=$runA"); ok('the other customer cannot see the first one\'s batch', $D->code===404, $D->code);
$A->get("/admin.php?run=$runB"); ok('admin sees any batch', $A->code===200 && strpos($A->body,'SECRET-HOOK-B')!==false, $A->code);
$A->get("/export.php?run=$runB"); ok('admin can export any batch', $A->code===200);
$X=new B(); $X->get("/export.php?run=$runA"); ok('logged-out export refused', $X->code===302);

sec('Plans and promo codes');
$A->post('/admin.php?view=codes',['action'=>'create_code','code'=>'launch20','kind'=>'promo','discount_pct'=>'20','discount_usd'=>'0','max_uses'=>'','expires_at'=>'','affiliate_email'=>'','commission_pct'=>'0']);
ok('admin creates a code', strpos($A->body,'Code LAUNCH20 created')!==false, substr(strip_tags($A->body),0,200));
$A->post('/admin.php?view=codes',['action'=>'create_code','code'=>'REF1','kind'=>'affiliate','discount_pct'=>'10','discount_usd'=>'0','max_uses'=>'','expires_at'=>'','affiliate_email'=>'other@example.test','commission_pct'=>'30']);
ok('affiliate code with commission', strpos($A->body,'Code REF1 created')!==false && one("SELECT * FROM promo_codes WHERE code='REF1'")['commission_pct']==30);
$C->get('/plan.php'); ok('plan page renders tiers', $C->code===200 && strpos($C->body,'Starter')!==false && strpos($C->body,'$99')!==false, $C->code);
ok('trial plan is not offered for purchase', substr_count($C->body,'name="plan"')===3);
$C->post('/plan.php',['action'=>'quote','plan'=>'growth','promo'=>'launch20']);
ok('quote applies the code', strpos($C->body,'$23.20/month')!==false, substr(strip_tags($C->body),0,300));
$C->post('/plan.php',['action'=>'quote','plan'=>'growth','promo'=>'FAKE']);
ok('invalid code rejected', strpos($C->body,'not valid')!==false);
$C->post('/plan.php',['action'=>'request','plan'=>'growth','promo'=>'REF1']);
ok('plan request recorded', strpos($C->body,'Request received')!==false && one("SELECT * FROM plan_requests WHERE user_id=? AND status='pending'",[$u['id']])['price_usd']==26.1);
$C->post('/plan.php',['action'=>'request','plan'=>'starter','promo'=>'']);
ok('second pending request refused', strpos($C->body,'already have a pending request')!==false);
$A->get('/admin.php'); ok('admin sees the request', strpos($A->body,'Plan requests waiting')!==false && strpos($A->body,'broker@example.test')!==false);
$A->post('/admin.php',['action'=>'activate','user_id'=>$u['id'],'plan'=>'growth','promo'=>'REF1']);
ok('admin activates after payment', strpos($A->body,'Plan activated at $26.10')!==false, substr(strip_tags($A->body),0,200));
$s=Billing::current($u['id']);
ok('subscription active on growth with the code', $s['status']==='active' && $s['plan_id']==='growth' && $s['promo_code']==='REF1');
ok('request closed, customer marked paying', one("SELECT status FROM plan_requests WHERE user_id=?",[$u['id']])['status']==='done' && one("SELECT status FROM customers WHERE id=?",[$c['id']])['status']==='paying');
$A->get('/admin.php?view=codes'); ok('affiliate earnings computed', strpos($A->body,'Affiliate earnings')!==false && strpos($A->body,'$7.83')!==false, preg_match('/Commission owed.*?<\/table>/s',$A->body,$m)?strip_tags($m[0]):'');
$C->get('/app.php'); ok('dashboard shows the paid plan and the next run date', strpos($C->body,'Growth')!==false && strpos($C->body,'Next run in')!==false, substr(strip_tags($C->body),0,400));
q("UPDATE runs SET started_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 DAY) WHERE customer_id=?",[$c['id']]);
$C->get('/app.php'); ok('once due, the paid customer can start a run', strpos($C->body,'Run analysis now')!==false);

sec('Script Writer page');
/* seed a couple of reels in the customer's corpus so search has something to find */
$rIns=db()->prepare("INSERT INTO reels (run_id,customer_id,is_own,handle,code,url,velocity,hook,transcript) VALUES (?,?,?,?,?,?,?,?,?)");
$wr=null; q("INSERT INTO runs (customer_id,kind,status) VALUES (?,'full','done')",[$c['id']]); $wr=lastId();
$rIns->execute([$wr,$c['id'],0,'jake_nazer','PGW1','https://x/PGW1',4200,'How much is rent dropping in Dubai?','rent transcript']);
$C->get('/write.php'); ok('writer page renders for the owner', $C->code===200 && strpos($C->body,'Write a script')!==false, $C->code);
ok('writer page has the topic box', strpos($C->body,'I want to write a script')!==false);
$C->post('/write.php',['action'=>'search','topic'=>'how much is rent dropping in dubai']);
ok('search surfaces the matching reference reel', strpos($C->body,'Found 1 video')!==false && strpos($C->body,'jake_nazer')!==false, substr(strip_tags($C->body),0,200));
$C->post('/write.php',['action'=>'search','topic'=>'zzz quantum teleportation for realtors']);
ok('a brand-new topic asks for research first', strpos($C->body,'No existing video found')!==false && strpos($C->body,'required for a new topic')!==false);
$C->post('/write.php',['action'=>'search','topic'=>'x']);
ok('too-short topic rejected', strpos($C->body,'Give me a topic')!==false);
$X2=new B(); $X2->get('/write.php'); ok('writer page refuses logged-out visitors', $X2->code===302 && strpos((string)$X2->loc(),'login')!==false);
ok('customer nav links to the writer', strpos($C->body,'write.php')!==false);

sec('Password change, logout, disabled accounts');
$C->post('/onboard.php',['action'=>'password','current'=>'wrong','password'=>'NewPass123456']);
ok('wrong current password refused', strpos($C->body,'Current password is wrong')!==false);
$C->post('/onboard.php',['action'=>'password','current'=>'GoodPass12345','password'=>'NewPass123456']);
ok('password changed', strpos($C->body,'Password changed')!==false);
$C->get('/logout.php'); ok('GET logout only shows a confirm page', $C->code===200 && !empty($C->jar['outlier_s']));
$C->post('/logout.php',[]); ok('POST logout ends the session', $C->code===302);
$C->get('/app.php'); ok('session really gone', $C->code===302 && strpos((string)$C->loc(),'login')!==false);
$C->post('/login.php',['email'=>'broker@example.test','password'=>'GoodPass12345']);
ok('old password no longer works', strpos($C->body,'wrong')!==false);
$C->post('/login.php',['email'=>'broker@example.test','password'=>'NewPass123456']);
ok('new password works', $C->code===302 && $C->loc()==='app.php', [$C->code,$C->loc()]);
$A->post('/admin.php?view=users',['action'=>'user_status','user_id'=>$u['id'],'status'=>'disabled']);
ok('admin disables the user', strpos($A->body,'User disabled')!==false);
$C->get('/app.php'); ok('disabled user is logged out on next request', $C->code===302, $C->code);
$C->post('/login.php',['email'=>'broker@example.test','password'=>'NewPass123456']);
ok('disabled user cannot log in', strpos($C->body,'disabled')!==false);
$A->post('/admin.php?view=users',['action'=>'user_status','user_id'=>$A_self=one("SELECT id FROM users WHERE email=?",[$ADMIN_EMAIL])['id'],'status'=>'disabled']);
ok('admin cannot disable themself', strpos($A->body,'cannot disable yourself')!==false);

sec('Password reset flow');
q("DELETE FROM login_attempts");
$R=new B(); $R->post('/forgot.php',['email'=>'nobody@example.test']);
ok('unknown email gets the same message', strpos($R->body,'If that email has an account')!==false);
$R->post('/forgot.php',['email'=>'other@example.test']);
$row=one("SELECT reset_token FROM users WHERE email='other@example.test'");
ok('reset token stored hashed (64 hex)', $row && preg_match('/^[a-f0-9]{64}$/',$row['reset_token']));
$R->get('/forgot.php?t=deadbeef'); ok('bad reset link refused', $R->code===400);

sec('Worker via admin button');
$A->post('/admin.php',['action'=>'worker']);
ok('worker tick runs from the panel', strpos($A->body,'Worker tick done')!==false && stripos($A->body,'Fatal')===false, substr(strip_tags($A->body),0,200));

echo "\n".($fail===0?'ALL PASS':'FAILURES').": $pass passed, $fail failed\n\n";
exit($fail?1:0);
