<?php
require_once __DIR__ . '/../lib/pipeline.php';

$pass=0; $fail=0;
function ok($n,$c,$x=null){ global $pass,$fail;
  if($c){$pass++; echo "  PASS  $n\n";} else {$fail++; echo "  FAIL  $n".($x===null?'':'  -> '.json_encode($x))."\n";} }
function sec($t){ echo "\n— $t —\n"; }

/* ── mock Apify + Anthropic ─────────────────────────────────────────────── */
$MOCK = ['ig_status'=>'SUCCEEDED','tr_status'=>'SUCCEEDED','ig_polls'=>0,'calls'=>[],
         'fail_anthropic'=>false,'http_fail_once'=>false];

function IGROW($owner,$code,$plays,$views,$likes,$cm,$dur,$ts,$comments=[]){
  return ['ownerUsername'=>$owner,'shortCode'=>$code,'url'=>"https://www.instagram.com/reel/$code/",
    'type'=>'Video','productType'=>'clips','videoPlayCount'=>$plays,'videoViewCount'=>$views,
    'likesCount'=>$likes,'commentsCount'=>$cm,'videoDuration'=>$dur,'timestamp'=>$ts,'caption'=>'x',
    'latestComments'=>array_map(fn($t)=>['text'=>$t],$comments)];
}
$POSTS = [
  IGROW('imad_huda','OWN1',900,320,20,2,60,'2026-08-20T10:00:00.000Z'),
  IGROW('imad_huda','OWN2',1400,500,31,4,55,'2026-08-24T10:00:00.000Z'),
  IGROW('imad_huda','OWN3',600,210,12,1,70,'2026-08-28T10:00:00.000Z'),
  IGROW('imad_huda','OWN4',2100,760,40,6,50,'2026-09-06T10:00:00.000Z',['Bhai which area gives best ROI for brokers?','great video keep it up']),
  IGROW('jake_nazer','J1',45996,20264,439,37,158,'2026-08-30T15:35:24.000Z',
     ['Which community gives the best rental yield?','which community has the best yield for investors?','This is misleading, prices are rising']),
  IGROW('jake_nazer','J2',33850,14745,216,15,150,'2026-09-02T15:30:28.000Z',
     ['What community would you pick for yield?','How do I buy off plan as a non resident?']),
  IGROW('jake_nazer','J3',26244,10614,276,14,130,'2026-09-01T15:34:32.000Z'),
  IGROW('jake_nazer','J4',14003,4361,125,7,115,'2026-08-25T15:33:15.000Z'),
  IGROW('jake_nazer','J5',12026,5086,145,2,96,'2026-08-22T07:05:01.000Z'),
  IGROW('nathan','N1',144348,41020,2438,43,66,'2026-04-28T18:28:37.000Z'),
  IGROW('nathan','N2',6570,1962,137,2,153,'2026-05-14T12:28:56.000Z'),
  IGROW('nathan','N3',6292,377,86,5,89,'2026-07-09T17:39:06.000Z'),   // 6% ratio -> false
  IGROW('nathan','N4',5405,1724,88,1,151,'2026-06-02T14:27:42.000Z'),
  IGROW('nathan','N5',4888,1787,78,2,117,'2026-06-06T03:46:02.000Z'),
  // contamination: a tagged post owned by nobody we asked for
  IGROW('springfielduae','SPR1',6667,1949,102,5,24,'2026-09-03T16:08:14.000Z'),
];
$HOOKS = ['OWN1'=>'Boss, aaj baat karte hain broker economics ki.',
  'OWN2'=>'Bhai imagine karo aap store owner ho aur 2 baje shutter gira dete ho.',
  'OWN3'=>'Misaal ke taur pe, portal lead aur apni lead mein farq kya hai?',
  'OWN4'=>'Boss, 35,200 brokers hain Dubai mein. Sun lo ye number.',
  'J1'=>"Dubai's August property transaction volumes, where is demand weakening?",
  'J2'=>'How much are villa sales prices dropping in Dubai? The September update.',
  'J3'=>'How much is rent dropping for apartments in Dubai? The September update.',
  'N1'=>"Here is a breakdown of Hudayriyat Island, Abu Dhabi's answer to Beverly Hills.",
  'N2'=>'Most people do not even know where foreigners can own property in Abu Dhabi.'];

Http::$transport = function($method,$url,$headers,$body) use (&$MOCK,$POSTS,$HOOKS) {
  $MOCK['calls'][] = "$method $url";
  if ($MOCK['http_fail_once']) { $MOCK['http_fail_once']=false; return [503,'{"error":"busy"}']; }

  if (strpos($url,'api.anthropic.com')!==false) {
    if ($MOCK['fail_anthropic']) return [400,'{"error":{"message":"bad request"}}'];
    $sent = (string)($body['messages'][0]['content'] ?? '');
    if (strpos($sent,'Transcripts from @')!==false) {          // voice-profile call
      $MOCK['voice_calls'] = ($MOCK['voice_calls'] ?? 0) + 1;
      $MOCK['voice_prompt'] = $sent;
      return [200, json_encode(['content'=>[['type'=>'text','text'=>json_encode([
        'language'=>'Hinglish — Hindi sentence structure with English marketing terms',
        'opening_move'=>'Opens with "Boss" then a physical analogy',
        'markers'=>['Boss','Bhai','So','Misaal ke taur pe'],
        'analogy_style'=>'Everyday physical scenes — shops, food, mechanics',
        'rhythm'=>'Short declaratives, one idea per line',
        'code_switching'=>'Technical terms stay English inside Hindi sentences',
        'cta_style'=>'Comment karo [WORD] aur main aapko bhej dunga',
        'audience'=>'UAE real estate brokers and agency owners',
        'avoid'=>'Never uses celebrity name-drops',
        'summary'=>'Talks like a practitioner to another practitioner.'])]],
        'usage'=>['input_tokens'=>800,'output_tokens'=>400]])];
    }
    if (strpos($sent,'keys for ONE script')!==false || strpos($sent,'THE TOPIC THE CREATOR ASKED FOR')!==false) {   // Script Writer
      $MOCK['writer_prompt']=$sent;
      return [200, json_encode(['content'=>[['type'=>'text','text'=>json_encode([
        'concept'=>'Rent trend explainer','hook_pattern'=>'Data Drop','hook_line'=>'Boss, rent gir raha hai — sun lo.',
        'body'=>'Boss, is topic pe baat karte hain. Comment karo RENT.','duration_label'=>'40 sec',
        'caption'=>'Rent update','cta_keyword'=>'RENT','source_insight'=>'From @jake_nazer reference'])]],
        'usage'=>['input_tokens'=>500,'output_tokens'=>300]])];
    }
    if (strpos($sent,'"ideas"')!==false || strpos($sent,'STORY')!==false) {                                       // Story ideas
      $MOCK['story_prompt']=$sent;
      return [200, json_encode(['content'=>[['type'=>'text','text'=>json_encode([
        'ideas'=>[['format'=>'poll','prompt'=>'Off-plan ya ready? Vote karo','why'=>'repeated question'],
                  ['format'=>'hot take','prompt'=>'Rent abhi girega','why'=>'trending hook']]])]],
        'usage'=>['input_tokens'=>200,'output_tokens'=>150]])];
    }
    $MOCK['gen_prompt'] = $sent;
    $n=4; $items=[];
    $pats=['Data Drop','Analogy','Contrarian','Villain'];
    $subs=['UAE real estate — brokers','UAE real estate — developers'];
    for($i=1;$i<=$n;$i++) $items[]=['niche'=>$subs[($i-1)%2],'concept'=>"Concept $i",
      'hook_pattern'=>$pats[($i-1)%4],'hook_line'=>"Hook line $i",
      'body'=>"Boss, ye script $i hai — 90% log ye galti karte hain. Comment karo WORD$i.",
      'duration_label'=>'30 sec','caption'=>"Caption $i",'cta_keyword'=>"WORD$i",
      'source_insight'=>'From @jake_nazer J1'];
    return [200, json_encode(['content'=>[['type'=>'text','text'=>json_encode([
        'findings'=>['position'=>'You sit at 9% of the field.','what_works'=>'Flat data hooks.',
                     'what_to_drop'=>'The boosted reel N3.','voice_notes'=>'Applied their Boss opener.',
                     'angle'=>'Broker economics.','caveats'=>'Verify the broker count.'],
        'scripts'=>$items])]],
        'usage'=>['input_tokens'=>1000,'output_tokens'=>900]])];
  }
  if (preg_match('#/acts/([^/]+)/runs#',$url,$m)) {
    $isTr = strpos($m[1],'transcript')!==false;
    return [201, json_encode(['data'=>['id'=>$isTr?'TRRUN':'IGRUN']])];
  }
  if (preg_match('#/actor-runs/(\w+)#',$url,$m)) {
    if ($m[1]==='IGRUN') {
      $MOCK['ig_polls']++;
      $s = ($MOCK['ig_polls'] < 2) ? 'RUNNING' : $MOCK['ig_status'];
      return [200, json_encode(['data'=>['status'=>$s,'defaultDatasetId'=>'DSIG']])];
    }
    return [200, json_encode(['data'=>['status'=>$MOCK['tr_status'],'defaultDatasetId'=>'DSTR']])];
  }
  if (strpos($url,'datasets/DSIG/items')!==false) {
    parse_str(parse_url($url,PHP_URL_QUERY),$q);
    return [200, json_encode(((int)($q['offset']??0))===0 ? $POSTS : [])];
  }
  if (strpos($url,'datasets/DSTR/items')!==false) {
    $out=[]; foreach($HOOKS as $c=>$h) $out[]=['shortCode'=>$c,'hook3s'=>$h,'transcript'=>"$h Full body.",'status'=>'transcribed'];
    return [200, json_encode($out)];
  }
  return [200,'[]'];
};

function reset_db(){
  q("DELETE FROM jobs"); q("DELETE FROM customers"); q("DELETE FROM handle_ledger");
  q("INSERT INTO customers (name,email,ig_handle,niche,competitors,language,trial_start,trial_end)
     VALUES ('Imad Huda','imad@example.test','imad_huda','UAE real estate — brokers, developers',
             ?, 'Hinglish', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))",
    [json_encode(['jake_nazer','nathan'])]);
  return lastId();
}
function drain($max=40){
  $steps=[];
  for($i=0;$i<$max;$i++){
    $job=Queue::claim(); if(!$job) break;
    try { $msg=Pipeline::handle($job); Queue::done($job['id']); $steps[]="{$job['step']}: $msg"; }
    catch (HttpError $e){ $steps[]="{$job['step']} HTTP{$e->status}: ".Queue::fail($job,$e->getMessage(),$e->retryable); }
    catch (Throwable $e){ $steps[]="{$job['step']} ERR: ".Queue::fail($job,$e->getMessage(),false); }
    q("UPDATE jobs SET run_after=UTC_TIMESTAMP() WHERE state='pending'");   // skip the sleep
  }
  return $steps;
}

sec('Happy path — full 15-day cycle');
$cid = reset_db();
$runId = Pipeline::beginRun($cid);
$steps = drain();
ok('pipeline completed without failing', !array_filter($steps, fn($s)=>strpos($s,'ERR:')!==false||strpos($s,'HTTP')!==false), $steps);
$run = one("SELECT * FROM runs WHERE id=?",[$runId]);
ok('run marked done', $run['status']==='done', $run['status']);
ok('polled Apify more than once', $MOCK['ig_polls']>=2, $MOCK['ig_polls']);

sec('Scoring and storage');
$reels = all("SELECT * FROM reels WHERE run_id=?",[$runId]);
ok('reels stored', count($reels)===14, count($reels));
ok('contaminating account excluded', !in_array('springfielduae', array_column($reels,'handle')));
ok('own reels flagged is_own', count(array_filter($reels, fn($r)=>$r['is_own']=='1'))===4);
$n3 = null; foreach($reels as $r) if($r['code']==='N3') $n3=$r;
ok('low-completion reel flagged false', $n3 && $n3['flag']==='false', $n3['flag'] ?? null);
ok('false outlier not counted as outlier', $n3 && $n3['is_outlier']=='0');
ok('run counted posts_scored', (int)$run['posts_scored']===14, $run['posts_scored']);
ok('run counted false outliers', (int)$run['false_outliers']>=1, $run['false_outliers']);
ok('apify cost tracked', (float)$run['apify_cost_usd']>0, $run['apify_cost_usd']);

sec('Velocity ranking');
$top = all("SELECT handle,code,velocity FROM reels WHERE run_id=? ORDER BY velocity DESC LIMIT 3",[$runId]);
ok('top by velocity is a fresh jake reel, not the 144k nathan one', $top[0]['code']!=='N1', $top[0]);

sec('Transcripts merged');
$hooked = all("SELECT code,hook FROM reels WHERE run_id=? AND hook IS NOT NULL AND hook<>''",[$runId]);
ok('hooks written back onto reels', count($hooked)>=3, count($hooked));

sec('Comment mining reached the findings');
$f = json_decode($run['findings'],true);
ok('findings stored', is_array($f) && isset($f['accounts']));
ok('failed accounts list present', array_key_exists('failed',$f));
ok('repeated question mined', !empty($f['mined']['repeated']), $f['mined']['repeated'] ?? null);
ok('objection mined', !empty($f['mined']['objections']));

sec('Scripts');
$scripts = all("SELECT * FROM scripts WHERE run_id=? ORDER BY idx",[$runId]);
ok('4 scripts stored', count($scripts)===4, count($scripts));
ok('scripts_delivered recorded', (int)$run['scripts_delivered']===4, $run['scripts_delivered']);
ok('hinglish body survived the round trip', strpos($scripts[0]['body'],'galti')!==false, $scripts[0]['body']);
ok('em-dash survived', strpos($scripts[0]['body'],'—')!==false);
ok('cta keywords unique', count(array_unique(array_column($scripts,'cta_keyword')))===4);
ok('no consecutive repeat of hook pattern', (function($s){
    for($i=1;$i<count($s);$i++) if($s[$i]['hook_pattern']===$s[$i-1]['hook_pattern']) return false; return true;
  })($scripts));

sec('Voice profile');
ok('own reels were transcribed too', (int)one("SELECT COUNT(*) n FROM reels WHERE run_id=? AND is_own=1 AND hook<>''",[$runId])['n'] > 0,
   one("SELECT COUNT(*) n FROM reels WHERE run_id=? AND is_own=1 AND hook<>''",[$runId])['n']);
ok('voice-profile call was made', ($MOCK['voice_calls'] ?? 0) === 1, $MOCK['voice_calls'] ?? 0);
$cust = one("SELECT * FROM customers WHERE id=?",[$cid]);
ok('voice profile stored on the customer', !empty($cust['voice_profile']), substr((string)$cust['voice_profile'],0,80));
ok('voice profile has their real markers', strpos((string)$cust['voice_profile'],'Boss')!==false);
ok('voice_built_at stamped', !empty($cust['voice_built_at']));
ok('voice prompt carried their own transcripts only',
   strpos((string)($MOCK['voice_prompt'] ?? ''),'imad_huda')!==false
   && strpos((string)($MOCK['voice_prompt'] ?? ''),'jake_nazer')===false);

sec('Generation prompt is properly constrained');
$gp = (string)($MOCK['gen_prompt'] ?? '');
ok('voice profile reached the generator', strpos($gp,'VOICE PROFILE')!==false && strpos($gp,'Boss')!==false);
ok('sub-niches are listed as a hard constraint', strpos($gp,'USE ONLY THESE')!==false);
ok('sub-niches carry the headline niche', strpos($gp,'UAE real estate — brokers')!==false, substr($gp,0,0));
ok('false outliers marked for the model', strpos($gp,'FALSE OUTLIER')!==false);
ok('their own hooks were included', strpos($gp,'OWN OPENING LINES')!==false);

sec('Findings narrative');
$run2 = one("SELECT * FROM runs WHERE id=?",[$runId]);
ok('narrative stored', !empty($run2['narrative']), substr((string)$run2['narrative'],0,60));
ok('narrative explains position', strpos((string)$run2['narrative'],'Where you sit')!==false);
ok('narrative explains what to drop', strpos((string)$run2['narrative'],'What to ignore')!==false);

sec('Second run reuses a fresh voice profile');
$before = $MOCK['voice_calls'] ?? 0;
$MOCK['ig_polls']=0;
$runB = Pipeline::beginRun($cid); drain();
ok('no second voice-profile call', ($MOCK['voice_calls'] ?? 0) === $before, $MOCK['voice_calls'] ?? 0);
ok('second run still completed', one("SELECT status FROM runs WHERE id=?",[$runB])['status']==='done');

sec('Brief construction is safe');
$body = Claude::buildPrompt(['name'=>'x','handle'=>'h','niche'=>'y','subniches'=>['a'],'language'=>'Hinglish','voice'=>'','own_hooks'=>[],
  'accounts'=>[['handle'=>'h','is_own'=>true,'counted'=>3,'median_plays'=>10,'band'=>['weak'=>true,'lo'=>null,'hi'=>null]]],
  'top'=>[['handle'=>'h','plays'=>1,'age_days'=>1,'ratio'=>null,'outlier_score'=>1,'velocity'=>1,'flag'=>'false','hook'=>'H']],
  'clusters'=>[],'repeated'=>[],'objections'=>[],'vocab'=>[],'count'=>3]);
ok('brief marks false outliers for the model', strpos($body,'FALSE OUTLIER — IGNORE')!==false);
ok('brief handles a weak band without crashing', strpos($body,'completion band not established')!==false);
ok('brief handles null ratio', strpos($body,'completion n/a')!==false);
ok('brief says plainly when no voice profile exists', strpos($body,'VOICE PROFILE: NOT AVAILABLE')!==false);

sec('Failure paths');
$MOCK['ig_polls']=0; $MOCK['ig_status']='FAILED';
$cid2 = reset_db(); $run2 = Pipeline::beginRun($cid2); drain();
$r2 = one("SELECT * FROM runs WHERE id=?",[$run2]);
ok('a failed scrape fails the run', $r2['status']==='failed', $r2['status']);
ok('the error is recorded', strpos((string)$r2['error'],'FAILED')!==false, $r2['error']);

$MOCK['ig_polls']=0; $MOCK['ig_status']='SUCCEEDED'; $MOCK['tr_status']='FAILED';
$cid3 = reset_db(); $run3 = Pipeline::beginRun($cid3); drain();
$r3 = one("SELECT * FROM runs WHERE id=?",[$run3]);
ok('a failed transcription does NOT fail the run', $r3['status']==='done', $r3['status']);
ok('scripts still generated from metrics alone', (int)$r3['scripts_delivered']===4, $r3['scripts_delivered']);

$MOCK['tr_status']='SUCCEEDED'; $MOCK['ig_polls']=0; $MOCK['fail_anthropic']=true;
$cid4 = reset_db(); $run4 = Pipeline::beginRun($cid4); drain();
$r4 = one("SELECT * FROM runs WHERE id=?",[$run4]);
ok('a model failure fails the run cleanly', $r4['status']==='failed', $r4['status']);
$MOCK['fail_anthropic']=false;

sec('Retry and backoff');
$cid5 = reset_db();
$jid = Queue::push($cid5,'scrape_ig_start',[],null);
$job = Queue::claim();
$msg = Queue::fail($job,'HTTP 503',true);
$after = one("SELECT * FROM jobs WHERE id=?",[$jid]);
ok('retryable error goes back to pending', $after['state']==='pending', $after['state']);
ok('backoff scheduled', strpos($msg,'retry in')===0, $msg);
ok('attempt counted', (int)$after['attempts']===1, $after['attempts']);
for($i=0;$i<5;$i++){ q("UPDATE jobs SET run_after=UTC_TIMESTAMP() WHERE id=?",[$jid]);
  $j=Queue::claim(); if($j) Queue::fail($j,'still down',true); }
$after2 = one("SELECT * FROM jobs WHERE id=?",[$jid]);
ok('gives up after MAX_ATTEMPTS', $after2['state']==='failed', $after2);

sec('Queue safety');
$cid6 = reset_db();
Queue::push($cid6,'scrape_ig_start',[],null);
$a = Queue::claim(); $b = Queue::claim();
ok('a claimed job cannot be claimed twice', $a && $b===null);
q("UPDATE jobs SET locked_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) WHERE id=?",[$a['id']]);
$c2 = Queue::claim();
ok('a job orphaned by a crashed tick is revived', $c2 && $c2['id']==$a['id']);


/* ── multi-tenant: trial gate and quota ─────────────────────────────────── */
require_once __DIR__ . '/../lib/billing.php';
require_once __DIR__ . '/../lib/scheduler.php';
sec('Trial gate');
q("DELETE FROM jobs"); q("DELETE FROM customers"); q("DELETE FROM handle_ledger"); q("DELETE FROM subscriptions"); q("DELETE FROM users WHERE role='customer'");
q("INSERT INTO users (email,password_hash,name,email_verified) VALUES ('trial@example.test','x','Trial',1)"); $uidT = lastId();
Billing::startTrial($uidT);
q("INSERT INTO customers (user_id,name,email,ig_handle,niche,competitors,language,onboarded,ig_verified,onboarded_at,trial_start,trial_end)
   VALUES (?,'Trial','trial@example.test','imad_huda','UAE real estate — brokers',?,'Hinglish',1,1,UTC_TIMESTAMP(),CURDATE(),DATE_ADD(CURDATE(), INTERVAL 30 DAY))",
  [$uidT, json_encode(['jake_nazer','nathan'])]);
$cidT = lastId();
$cT = one("SELECT * FROM customers WHERE id=?", [$cidT]);
ok('trial customer needs a posting check first', Scheduler::needsTrialCheck($cT));
ok('scheduler has no blocker for a fresh verified trial', Scheduler::blocker($cT) === null, Scheduler::blocker($cT));
$MOCK['ig_polls'] = 0; $MOCK['calls'] = [];
$r = Scheduler::start($cT);
ok('scheduler starts a trial check, not a full run', $r['kind'] === 'trial_check', $r);
$steps = drain();
$chk = one("SELECT * FROM runs WHERE id=?", [$r['run']]);
/* The mock returns only 4 own videos, all older than 7 days relative to now → not enough. */
ok('trial check finished as skipped', $chk['status'] === 'skipped', $chk['status']);
ok('trial check counted own posts only', (int)one("SELECT COUNT(*) n FROM reels WHERE run_id=?", [$r['run']])['n'] === 4);
ok('customer told how many are missing', strpos((string)$chk['error'], 'of 10 videos') !== false, $chk['error']);
ok('no full run was started', !one("SELECT id FROM runs WHERE customer_id=? AND kind='full'", [$cidT]));
ok('only the own account was scraped', count(array_filter($MOCK['calls'], fn($c) => strpos($c, '/acts/apify~instagram-scraper/runs') !== false)) === 1);
$cT = one("SELECT * FROM customers WHERE id=?", [$cidT]);
ok('recheck is throttled to two days', strpos((string)Scheduler::blocker($cT), 'Next run in') === 0, Scheduler::blocker($cT));
ok('scheduler tick skips the throttled customer', Scheduler::tick() === []);

/* Pretend they posted: back-date the check and stamp recent own posts. */
q("UPDATE reels SET posted_at=UTC_TIMESTAMP() WHERE run_id=?", [$r['run']]);
for ($i = 5; $i <= 10; $i++) q("INSERT INTO reels (run_id,customer_id,is_own,handle,code,posted_at) VALUES (?,?,1,'imad_huda',?,UTC_TIMESTAMP())", [$r['run'], $cidT, "OWNX$i"]);
q("UPDATE runs SET started_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY) WHERE id=?", [$r['run']]);
$cT = one("SELECT * FROM customers WHERE id=?", [$cidT]);
ok('with 10 recent posts the gate opens', !Scheduler::needsTrialCheck($cT));
$MOCK['ig_polls'] = 0;
$r2 = Scheduler::start($cT);
ok('now a full run starts', $r2['kind'] === 'full', $r2);
drain();
$run2 = one("SELECT * FROM runs WHERE id=?", [$r2['run']]);
ok('full trial run completes', $run2['status'] === 'done', $run2);
$sub = Billing::current($uidT);
ok('trial scripts were metered against the plan', (int)$sub['scripts_used'] === (int)$run2['scripts_delivered'] && (int)$sub['scripts_used'] > 0, $sub);
ok('trial asked the model for the whole trial batch (10)', preg_match('/\b10\b[^\n]*scripts|scripts[^\n]*\b10\b/i', $MOCK['gen_prompt']) === 1, preg_match('/=== YOUR TASK ===.*/s', $MOCK['gen_prompt'], $mm) ? substr($mm[0], 0, 300) : '');
$cT = one("SELECT * FROM customers WHERE id=?", [$cidT]);
q("UPDATE subscriptions SET scripts_used=10 WHERE user_id=?", [$uidT]);
ok('after the trial batch the customer is blocked with an upgrade message', strpos((string)Scheduler::blocker($cT), 'Choose a plan') !== false, Scheduler::blocker($cT));

sec('Paid quota');
Billing::activate($uidT, 'starter');
$sub = Billing::current($uidT);
ok('starter plan active at list price', $sub['status'] === 'active' && (float)$sub['price_paid'] == 19.0, $sub);
q("UPDATE runs SET started_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 DAY) WHERE customer_id=?", [$cidT]);
$cT = one("SELECT * FROM customers WHERE id=?", [$cidT]);
ok('paid customer is due after run_every_days', Scheduler::blocker($cT) === null, Scheduler::blocker($cT));
ok('paid customer never gets the trial check', !Scheduler::needsTrialCheck($cT));
q("UPDATE subscriptions SET scripts_used=13 WHERE user_id=? AND status='active'", [$uidT]);
$a = Billing::allowance($uidT);
ok('fewer than MIN_BATCH scripts left blocks the run', $a['n'] === 0, $a);
q("UPDATE subscriptions SET scripts_used=10 WHERE user_id=? AND status='active'", [$uidT]);
$a = Billing::allowance($uidT);
ok('allowance reports scripts left', $a['n'] === 5, $a);
q("UPDATE subscriptions SET renews_at=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE user_id=? AND status='active'", [$uidT]);
$a = Billing::allowance($uidT);
ok('past renewal date the month is owed', $a['n'] === 0 && strpos($a['why'], 'due') !== false, $a);
Billing::renew($uidT);
$a = Billing::allowance($uidT);
ok('marking the month paid reopens it', $a['n'] === 15, $a);
q("UPDATE plans SET runs_month=1 WHERE id='starter'");
q("INSERT INTO runs (customer_id,kind,status,started_at) VALUES (?,'full','done',UTC_TIMESTAMP())", [$cidT]);
$a = Billing::allowance($uidT);
ok('run limit counts full runs this period', $a['n'] === 0 && strpos($a['why'], 'run limit') !== false, $a);
q("UPDATE plans SET runs_month=4 WHERE id='starter'");

sec('Promo codes');
q("DELETE FROM promo_codes"); q("DELETE FROM users WHERE email='aff@example.test'");
Billing::createCode('LAUNCH20', 'promo', 20, 0, 2, '');
$d = Billing::applyPromo('launch20', 29);
ok('percent code lowercases and discounts', !isset($d['error']) && $d['price'] == 23.2, $d);
q("INSERT INTO users (email,password_hash,name,email_verified) VALUES ('aff@example.test','x','Aff',1)"); $aff = lastId();
Billing::createCode('REF-AFF', 'affiliate', 0, 5, '', '2099-01-01', $aff, 30);
$d = Billing::applyPromo('REF-AFF', 19);
ok('flat code works', $d['price'] == 14.0, $d);
ok('bad code rejected', isset(Billing::applyPromo('NOPE', 19)['error']));
Billing::createCode('OLD', 'promo', 50, 0, '', '2020-01-01');
ok('expired code rejected', isset(Billing::applyPromo('OLD', 19)['error']));
Billing::activate($uidT, 'growth', 'LAUNCH20'); Billing::activate($uidT, 'growth', 'LAUNCH20');
ok('used-up code rejected', isset(Billing::applyPromo('LAUNCH20', 29)['error']));
ok('code uses counted', (int)one("SELECT uses FROM promo_codes WHERE code='LAUNCH20'")['uses'] === 2);
$bad = false; try { Billing::createCode('X', 'promo', 10, 0, '', ''); } catch (Throwable $e) { $bad = true; }
ok('too-short code refused', $bad);
$bad = false; try { Billing::createCode('ZERO', 'promo', 0, 0, '', ''); } catch (Throwable $e) { $bad = true; }
ok('code without a discount refused', $bad);

sec('Admin is never metered');
$adm = one("SELECT id FROM users WHERE role='admin' LIMIT 1");
$a = Billing::allowance($adm['id']);
ok('admin allowance unlimited', $a['n'] === null, $a);

/* ── Comment leads captured from OWN posts ──────────────────────────────── */
sec('Comment leads (own-post questions)');
$lead = one("SELECT * FROM comment_leads WHERE customer_id=? AND text LIKE '%best ROI for brokers%'", [$cid]);
ok('question comment on an own post is captured as a lead', $lead && (int)$lead['is_question']===1, $lead);
$nonq = one("SELECT * FROM comment_leads WHERE customer_id=? AND text LIKE '%keep it up%'", [$cid]);
ok('a non-question comment is not stored as a lead', $nonq===null);
require_once __DIR__.'/../lib/writer.php';
ok('Writer::leads returns the question', count(Writer::leads($cid))>=1);

/* ── Script Writer: corpus search + generate ────────────────────────────── */
sec('Script Writer');
q("DELETE FROM customers WHERE email='writer@example.test'"); q("DELETE FROM users WHERE email='writer@example.test'");
q("INSERT INTO users (email,password_hash,name,email_verified) VALUES ('writer@example.test','x','W',1)"); $wuid=lastId();
require_once __DIR__.'/../lib/billing.php'; Billing::startTrial($wuid);
q("INSERT INTO customers (user_id,name,email,ig_handle,competitors,niche,language,duration_pref,onboarded,ig_verified,voice_profile,trial_start,trial_end)
   VALUES (?,'W','writer@example.test','imad_huda',?, 'UAE real estate — brokers','Hinglish','40 sec',1,1,'LANGUAGE: Hinglish',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY))",
  [$wuid, json_encode(['jake_nazer'])]);
$wcid=lastId();
q("INSERT INTO runs (customer_id,kind,status) VALUES (?,'full','done')",[$wcid]); $wrun=lastId();
$rIns=db()->prepare("INSERT INTO reels (run_id,customer_id,is_own,handle,code,url,velocity,hook,transcript) VALUES (?,?,?,?,?,?,?,?,?)");
$rIns->execute([$wrun,$wcid,0,'jake_nazer','WR1','https://x/WR1',5000,'How much is rent dropping in Dubai?','Full transcript about rent dropping in Dubai apartments.']);
$rIns->execute([$wrun,$wcid,0,'jake_nazer','WR2','https://x/WR2',3000,'Best community for rental yield','Yield transcript.']);
$rIns->execute([$wrun,$wcid,1,'imad_huda','WR3','https://x/WR3',1200,'Broker economics 101','Broker transcript.']);
$wc = one("SELECT * FROM customers WHERE id=?",[$wcid]);

$hit = Writer::search($wcid,'how much is rent dropping in dubai');
ok('search finds the on-topic reference reel', count(array_filter($hit['refs'], fn($r)=>$r['code']==='WR1'))===1, array_column($hit['refs'],'code'));
ok('search returns style refs for the niche', count($hit['style'])>=1);
$miss = Writer::search($wcid,'zzznonexistenttopic quantum blockchain');
ok('a brand-new topic finds no references', count($miss['refs'])===0);

$g = Writer::generate($wc, 'How much is rent dropping in Dubai', $hit['refs'], '', $hit['style']);
ok('writer returns a script with a body', !empty($g['script']['body']), $g['script'] ?? null);
ok('writer stored the request', (int)one("SELECT COUNT(*) n FROM writer_requests WHERE customer_id=?",[$wcid])['n']===1);
ok('writer recorded that it used a reference', (int)one("SELECT had_reference FROM writer_requests WHERE customer_id=?",[$wcid])['had_reference']===1);
ok('writer prompt carried the voice profile', strpos($MOCK['writer_prompt'],'VOICE PROFILE')!==false && strpos($MOCK['writer_prompt'],'Hinglish')!==false);
ok('writer prompt carried the reference hook', strpos($MOCK['writer_prompt'],'rent dropping')!==false);

$g2 = Writer::generate($wc, 'A totally new angle nobody covered', [], 'My research: new DLD rule, three key numbers.', $hit['style']);
ok('writer works from notes when no reference exists', !empty($g2['script']['body']));
ok('no-reference request is flagged', (int)one("SELECT had_reference FROM writer_requests WHERE customer_id=? ORDER BY id DESC LIMIT 1",[$wcid])['had_reference']===0);
ok('the notes were passed into the prompt', strpos($MOCK['writer_prompt'],'new DLD rule')!==false);

/* ── Story ideas: generated once, cached per day ────────────────────────── */
sec('Story ideas');
q("UPDATE runs SET findings=? WHERE id=?", [json_encode(['mined'=>['repeated'=>[['example'=>'Which area has best ROI?','count'=>3]]]]), $wrun]);
$ideas = Writer::storyIdeas($wc, true);
ok('story ideas generated from the latest run', count($ideas)===2, $ideas);
$MOCK['story_prompt']='';
ok('a plain load does NOT call the model (read-only)', (function() use($wc,&$MOCK){ Writer::storyIdeas($wc); return $MOCK['story_prompt']===''; })());
$MOCK['story_prompt']='';
$ideas2 = Writer::storyIdeas($wc);
ok('story ideas are cached for the day (no second model call)', $MOCK['story_prompt']==='' && count($ideas2)===2);
$MOCK['story_prompt']=''; Writer::storyIdeas($wc,true);
ok('a forced refresh does call the model again', $MOCK['story_prompt']!=='');

echo "\n" . ($fail===0 ? 'ALL PASS' : 'FAILURES') . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
