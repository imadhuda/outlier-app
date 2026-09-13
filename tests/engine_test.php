<?php
require_once __DIR__ . '/../lib/engine.php';

$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0; $GLOBALS['lines'] = [];
function ok($name, $cond, $extra = null) {
    if ($cond) { $GLOBALS['pass']++; $GLOBALS['lines'][] = ['pass', $name, '']; }
    else { $GLOBALS['fail']++; $GLOBALS['lines'][] = ['fail', $name, $extra === null ? '' : json_encode($extra)]; }
}
function section($t) { $GLOBALS['lines'][] = ['sec', $t, '']; }

$NOW = strtotime('2026-09-08T12:00:00Z');
function P($code,$plays,$views,$likes,$comments,$dur,$ts,$cmts=[]) {
    return ['code'=>$code,'url'=>"https://instagram.com/reel/$code",'plays'=>$plays,'views'=>$views,
            'likes'=>$likes,'comments'=>$comments,'duration'=>$dur,'timestamp'=>$ts,
            'is_video'=>true,'caption'=>'','top_comments'=>$cmts];
}

section('Unit');
ok('median odd', Engine::median([3,1,2]) == 2);
ok('median even', Engine::median([1,2,3,4]) == 2.5);
ok('median empty', Engine::median([]) == 0);
ok('median ignores junk', Engine::median([1,2,3,null,NAN,-5]) == 2);
ok('daysSince floors at 1', Engine::daysSince('2026-09-08T11:00:00Z',$NOW) == 1.0);
ok('daysSince future floors at 1', Engine::daysSince('2027-01-01T00:00:00Z',$NOW) == 1.0);
ok('daysSince bad date', Engine::daysSince('not-a-date',$NOW) === null);
ok('completionRatio', abs(Engine::completionRatio(100,40)-0.4) < 1e-9);
ok('completionRatio zero plays', Engine::completionRatio(0,5) === null);
ok('completionRatio absurd', Engine::completionRatio(10,900) === null);
ok('ratioBand weak on tiny sample', Engine::ratioBand([0.4,0.42])['weak'] === true);

section('Real jake_nazer data');
$jake = [
 P('Dcv_q5Ejmd4',45996,20264,439,37,158,'2026-09-01T15:35:24Z'),
 P('Dc6SRUwldvk',33850,14745,216,15,150,'2026-09-05T15:30:28Z'),
 P('Dc1JL4pCcYQ',26244,10614,276,14,130,'2026-09-03T15:34:32Z'),
 P('DctaqCOEUGs',23705,9840,196,11,65,'2026-08-31T15:33:01Z'),
 P('DcykbVmASH5',22663,8824,195,21,141,'2026-09-02T15:35:17Z'),
 P('Dc5Yg3ZkjVi',15437,4955,257,14,83,'2026-09-05T07:05:25Z'),
 P('Dcq10QKlaad',14003,4361,125,7,115,'2026-08-30T15:33:15Z'),
 P('DcvFXi-joj7',13192,4121,114,6,87,'2026-09-01T07:05:48Z'),
 P('Dc-eZPNj20z',12946,4585,71,18,51,'2026-09-07T06:32:50Z'),
 P('Dcp7r0vFAhJ',12026,5086,145,2,96,'2026-08-30T07:05:01Z'),
 P('Dc83ey2ArDW',10398,2777,108,5,112,'2026-09-06T15:33:46Z'),
 P('Dc_cPwqCV1Q',7871,2715,23,0,51,'2026-09-07T15:33:32Z'),
];
$aJ = Engine::scoreAccount('jake_nazer',$jake,$NOW);
ok('jake median = 14720', $aJ['median_plays'] == 14720, $aJ['median_plays']);
ok('jake counted 12', $aJ['counted'] === 12, $aJ['counted']);
ok('jake band 0.33-0.44', $aJ['band']['med'] > 0.33 && $aJ['band']['med'] < 0.44, $aJ['band']['med']);
$sorted = true; for ($i=1;$i<count($aJ['reels']);$i++) if ($aJ['reels'][$i-1]['velocity'] < $aJ['reels'][$i]['velocity']) $sorted=false;
ok('jake velocity-sorted desc', $sorted);
ok('jake velocity top is NOT the plays top', $aJ['reels'][0]['code'] !== 'Dcv_q5Ejmd4', $aJ['reels'][0]['code']);
ok('jake no false outliers', count(array_filter($aJ['reels'], fn($r)=>$r['flag']==='false')) === 0);

section('Real thenathanmistry data');
$nathan = [
 P('DXr3WVKjDqJ',144348,41020,2438,43,66,'2026-04-28T18:28:37Z'),
 P('DXJJGvqDFU8',70018,32050,1101,22,86,'2026-04-15T06:50:31Z'),
 P('DXb3js1Ee7o',33331,13662,480,12,66,'2026-04-22T13:24:43Z'),
 P('DYDCCDLMlxj',18588,6135,273,16,92,'2026-05-07T18:25:36Z'),
 P('DYe5hmes7x7',12537,3811,158,5,100,'2026-05-18T14:09:27Z'),
 P('DZXsrqSBnYc',9687,3195,148,3,99,'2026-06-09T15:34:26Z'),
 P('DYpRaDahR8L',9368,2547,123,6,99,'2026-05-22T15:06:58Z'),
 P('Dc1E6_4MGyG',7249,2423,140,3,92,'2026-09-03T14:58:37Z'),
 P('DYUauWMMaba',6570,1962,137,2,153,'2026-05-14T12:28:56Z'),
 P('DalKvkJsZxB',6292,377,86,5,89,'2026-07-09T17:39:06Z'),
 P('Da0HXkNshBT',5850,567,90,4,65,'2026-07-15T12:57:32Z'),
 P('DZFjanGhGSY',5405,1724,88,1,151,'2026-06-02T14:27:42Z'),
 P('DYM2TxBt2gG',5286,1824,73,0,69,'2026-05-11T14:04:21Z'),
 P('DZOtS1aBW4m',4888,1787,78,2,117,'2026-06-06T03:46:02Z'),
 P('DXJdummy01',3844,1145,56,2,121,'2026-08-31T15:00:05Z'),
 P('Db8bpRKs44V',3507,1163,61,4,75,'2026-08-12T15:03:13Z'),
 P('Dc_U0iAM5Rq',2173,763,41,2,90,'2026-09-07T14:29:41Z'),
];
$aN = Engine::scoreAccount('thenathanmistry',$nathan,$NOW);
ok('nathan median = 6570', $aN['median_plays'] == 6570, $aN['median_plays']);
$n144 = null; foreach ($aN['reels'] as $r) if ($r['code']==='DXr3WVKjDqJ') $n144 = $r;
ok('nathan 144k is an outlier by score', $n144['outlier_score'] > 20);
ok('nathan fresh reel outranks the 144k on velocity', $aN['reels'][0]['code'] !== 'DXr3WVKjDqJ', $aN['reels'][0]['code']);
ok('nathan 144k velocity ~1085/day', abs($n144['velocity']-1085) < 60, round($n144['velocity']));
$lowR = array_map(fn($r)=>$r['code'], array_filter($aN['reels'], fn($r)=>$r['flag']==='false'));
ok('nathan low-ratio reels flagged false', in_array('DalKvkJsZxB',$lowR) && in_array('Da0HXkNshBT',$lowR), array_values($lowR));

section('False outlier detection (adel)');
$adel = [
 P('DZDQdffsA6S',35756,792,464,28,115,'2026-06-01T17:02:38Z'),   // 2% ratio — boosted
 P('DcwA_Vkqm-8',4467,1317,54,0,48,'2026-09-01T15:45:11Z'),
 P('Db6GhJaMiFk',4499,1209,55,3,161,'2026-08-11T17:20:03Z'),
 P('Dc06XFbMB6R',3993,1695,26,3,70,'2026-09-03T13:26:11Z'),
 P('DcyUr6VMrF9',3086,1211,59,6,108,'2026-09-02T13:18:23Z'),
 P('Dc3kPXNMnAD',2783,801,40,4,125,'2026-09-04T14:10:15Z'),
 P('Dc1SiCUN2rm',2301,639,38,4,117,'2026-09-03T16:55:05Z'),
 P('Dc6CTIDw72C',1571,479,21,2,60,'2026-09-05T13:11:18Z'),
 P('Dc_gTInKnEm',740,145,14,0,85,'2026-09-07T16:10:06Z'),
];
$aA = Engine::scoreAccount('adel_chynystanov_',$adel,$NOW);
$fake = null; foreach ($aA['reels'] as $r) if ($r['code']==='DZDQdffsA6S') $fake = $r;
ok('adel 35,756 reel scores as outlier on plays', $fake['outlier_score'] >= 1.5, $fake['outlier_score']);
ok('adel 35,756 reel flagged FALSE on 2% ratio', $fake['flag'] === 'false', ['ratio'=>$fake['ratio'],'flag'=>$fake['flag']]);
ok('adel false outlier excluded from is_outlier', $fake['is_outlier'] === false);

section('Teardown selection');
$td = Engine::pickTeardown([$aJ,$aN,$aA], 5);
ok('teardown excludes the false outlier', !in_array('DZDQdffsA6S', array_column($td,'code')));
$s2 = true; for ($i=1;$i<count($td);$i++) if ($td[$i-1]['velocity'] < $td[$i]['velocity']) $s2=false;
ok('teardown velocity-sorted', $s2);
ok('teardown covers all 3 accounts', count(array_unique(array_column($td,'handle'))) === 3);
ok('teardown is jake-led (hottest)', $td[0]['handle'] === 'jake_nazer', $td[0]['handle']);

section('Hook classification');
foreach ([
 ["Here is a breakdown of Hudayriyat Island, Abu Dhabi's answer to Beverly Hills.",'Breakdown'],
 ["Dubai off-plan apartment sales are the lowest they've been in three years.",'Data Drop'],
 ['How much is rent dropping for apartments in Dubai? The September update.','Question'],
 ["Most people don't even know where foreigners can actually own property in Abu Dhabi.",'Contrarian'],
 ['Imagine you are a store owner on the busiest street in the world.','What-if'],
 ['Your agency is stealing your ad budget every single month.','Villain'],
 ['','Unclassified'],
] as [$h,$want]) {
    ok('hook "'.(mb_substr($h,0,38) ?: '(empty)').'" -> '.$want, Engine::classifyHook($h) === $want, Engine::classifyHook($h));
}

section('Hook clustering');
$cl = Engine::clusterHooks([
 ['handle'=>'a','hook3s'=>'How much are prices dropping in Dubai?','velocity'=>5000,'plays'=>1,'url'=>''],
 ['handle'=>'b','hook3s'=>'How many units are left in this tower?','velocity'=>4000,'plays'=>1,'url'=>''],
 ['handle'=>'c','hook3s'=>'Why is nobody buying in JVC?','velocity'=>3000,'plays'=>1,'url'=>''],
 ['handle'=>'a','hook3s'=>'Here is a breakdown of the Palm.','velocity'=>900,'plays'=>1,'url'=>''],
]);
$qq = null; $bd = null;
foreach ($cl as $c) { if ($c['pattern']==='Question') $qq=$c; if ($c['pattern']==='Breakdown') $bd=$c; }
ok('Question clustered 3 across 3 accounts', $qq && $qq['count']===3 && $qq['accounts']===3, $qq);
ok('3-across-2 marked signal', $qq && $qq['signal'] === true);
ok('single instance NOT signal', $bd && $bd['signal'] === false);
ok('clusters sorted by median velocity', $cl[0]['pattern']==='Question', array_column($cl,'pattern'));

section('Comment mining');
$mined = Engine::mineComments([
 ['code'=>'x','top_comments'=>[
   'Which community gives the best rental yield right now?',
   'which community has the best yield for investors?',
   'What community would you pick for yield?',
   'This is misleading, prices are actually rising in JVC',
   'Great video bro','hi']],
 ['code'=>'y','top_comments'=>[
   'How do I buy off plan as a non resident?',
   'Can a non resident buy off plan property here?',
   'But actually the service charge makes this wrong']],
], 2);
ok('repeated questions clustered', count($mined['repeated']) >= 1, array_column($mined['repeated'],'count'));
ok('top cluster has >= 2', isset($mined['repeated'][0]) && $mined['repeated'][0]['count'] >= 2);
ok('objections caught', count($mined['objections']) === 2, count($mined['objections']));
ok('short junk ignored', !in_array('hi', array_column($mined['all_questions'],'text')));
ok('vocab excludes stopwords', !array_intersect(['the','this','that','what'], array_column($mined['vocab'],'word')));

section('Unicode / Hinglish safety');
$hin = Engine::mineComments([['code'=>'u','top_comments'=>[
  'Bhai ye kaunsa community hai jahan yield best hai?',
  'bhai konsa community best yield deta hai?',
  'Boss ye sab ghalat hai — actually prices badh rahe hain']]], 2);
ok('Hinglish questions cluster', count($hin['repeated']) >= 1, array_column($hin['repeated'],'count'));
ok('em-dash comment survives', count($hin['objections']) >= 1);
ok('mb_strlen used, not strlen', Engine::classifyHook('क्या ये सही है?') !== '');

section('Edge cases');
ok('empty account survives', Engine::scoreAccount('e',[],$NOW)['median_plays'] == 0);
ok('all-images account survives', Engine::scoreAccount('i',[['is_video'=>false,'plays'=>0]],$NOW)['counted'] === 0);
ok('single reel survives', count(Engine::scoreAccount('o',[P('a',100,40,1,1,30,'2026-09-01T00:00:00Z')],$NOW)['reels']) === 1);
ok('missing views does not crash', Engine::scoreAccount('n',[P('a',100,0,1,1,30,'2026-09-01T00:00:00Z')],$NOW)['reels'][0]['ratio'] === null);
ok('teardown on empty', count(Engine::pickTeardown([Engine::scoreAccount('e',[],$NOW)],5)) === 0);
ok('mineComments no comments', count(Engine::mineComments([['code'=>'a','top_comments'=>[]]],2)['repeated']) === 0);
ok('mineComments missing field', count(Engine::mineComments([['code'=>'a']],2)['all_questions']) === 0);
