<?php
require_once __DIR__ . '/auth.php';
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($x){ return $x===null||$x==='' ? '—' : number_format((float)$x); }
function pc($x){ return $x===null||$x==='' ? '—' : round((float)$x*100).'%'; }

function head($title, $nav = []) { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> — Outlier</title><style>
:root{--bg:#0B0B0C;--panel:#141416;--panel2:#1B1B1E;--line:#2A2A2F;--ink:#F5F5F3;--mute:#8E8E98;--dim:#5E5E68;
--orange:#FF6B1A;--green:#3BB273;--red:#E5484D;--amber:#F5A524}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 "Inter","Segoe UI",system-ui,sans-serif}
a{color:var(--orange);text-decoration:none}a:hover{text-decoration:underline}
.wrap{max-width:1140px;margin:0 auto;padding:0 20px 60px}.narrow{max-width:460px}
header{border-bottom:1px solid var(--line);margin-bottom:26px}
.hd{display:flex;align-items:center;gap:14px;padding:15px 0}
.logo{width:28px;height:28px;border-radius:7px;background:var(--orange);display:grid;place-items:center;font-weight:800;color:#0B0B0C;font-size:15px}
.brand{font-weight:700;letter-spacing:-.02em}.sp{flex:1}
h1{font-size:21px;margin:0 0 4px;letter-spacing:-.02em}h2{font-size:16px;margin:0 0 12px}
.sub{color:var(--mute);font-size:13.5px;margin-bottom:20px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:22px;margin-bottom:18px}
table{width:100%;border-collapse:collapse;font-size:13.5px}
th{text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--mute);border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:10px 12px;border-bottom:1px solid #202024;vertical-align:top}tr:last-child td{border-bottom:0}
.num{font-family:"SF Mono",Menlo,Consolas,monospace;font-size:12.5px;text-align:right;white-space:nowrap}
.chip{display:inline-block;font-size:10.5px;font-weight:750;padding:3px 8px;border-radius:20px;text-transform:uppercase}
.c-ok{background:rgba(59,178,115,.16);color:#5FD39A}.c-bad{background:rgba(229,72,77,.16);color:#FF7E82}
.c-warn{background:rgba(245,165,36,.16);color:#F5C87A}.c-dim{background:#232328;color:var(--dim)}.c-org{background:rgba(255,107,26,.16);color:#FF9C5E}
input,select,textarea{width:100%;padding:10px 12px;background:var(--panel2);border:1px solid var(--line);color:var(--ink);border-radius:7px;font:inherit;font-size:14px}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--orange)}
label{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--mute);margin:0 0 6px;font-weight:600}
.field{margin-bottom:14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}
.btn{background:var(--orange);color:#0B0B0C;border:0;padding:10px 18px;border-radius:7px;font:inherit;font-weight:650;cursor:pointer;display:inline-block}
.btn:hover{background:#FF8033;text-decoration:none}
.btn2{background:transparent;border:1px solid var(--line);color:var(--ink);padding:8px 14px;border-radius:7px;font:inherit;font-size:13px;cursor:pointer;display:inline-block}
.btn2:hover{border-color:var(--orange);color:var(--orange);text-decoration:none}
.msg{border-radius:8px;padding:12px 15px;font-size:13.5px;margin-bottom:16px}
.m-ok{background:rgba(59,178,115,.1);border:1px solid rgba(59,178,115,.35);color:#8FE0B4}
.m-bad{background:rgba(229,72,77,.1);border:1px solid rgba(229,72,77,.35);color:#FF9A9D}
.m-warn{background:rgba(245,165,36,.09);border:1px solid rgba(245,165,36,.3);color:#F5C87A}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.tile{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:16px}
.tile .k{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--mute);margin-bottom:6px;font-weight:600}
.tile .v{font-size:24px;font-weight:700}
pre{background:#08080A;border:1px solid var(--line);border-radius:8px;padding:14px;font-size:12px;overflow:auto;max-height:280px;white-space:pre-wrap;word-break:break-word;color:var(--mute)}
.empty{color:var(--dim);padding:24px 0;text-align:center;font-size:14px}
.code{font-family:"SF Mono",Menlo,Consolas,monospace;font-size:22px;letter-spacing:.14em;background:var(--panel2);border:1px dashed var(--orange);padding:12px 18px;border-radius:8px;display:inline-block;color:var(--orange)}
.steps{counter-reset:s;list-style:none;padding:0;margin:0}.steps li{counter-increment:s;padding:8px 0 8px 36px;position:relative;color:var(--mute);font-size:14px}
.steps li:before{content:counter(s);position:absolute;left:0;top:7px;width:24px;height:24px;border-radius:50%;background:var(--orange);color:#0B0B0C;font-weight:750;font-size:12px;display:grid;place-items:center}
.hero{background:linear-gradient(135deg,#161619,#0F0F11);border:1px solid var(--line);border-radius:14px;padding:30px 30px 26px}
.wiz{margin:0 0 24px}
.wiz-head{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--orange);font-weight:750;margin-bottom:14px}
.wiz-bar{display:flex;gap:0;align-items:flex-start}
.wiz-seg{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;text-align:center}
.wiz-seg:before{content:'';position:absolute;top:15px;left:-50%;width:100%;height:2px;background:var(--line);z-index:0}
.wiz-seg:first-child:before{display:none}
.wiz-seg.done:before,.wiz-seg.now:before{background:var(--orange)}
.wiz-seg .dot{position:relative;z-index:1;width:32px;height:32px;border-radius:50%;display:grid;place-items:center;font-weight:750;font-size:13px;background:var(--panel2);border:2px solid var(--line);color:var(--dim)}
.wiz-seg.done .dot{background:var(--orange);border-color:var(--orange);color:#0B0B0C}
.wiz-seg.now .dot{border-color:var(--orange);color:var(--orange);box-shadow:0 0 0 4px rgba(255,107,26,.14)}
.wiz-seg .lbl{margin-top:8px;font-size:11.5px;color:var(--mute);max-width:110px;line-height:1.3}
.wiz-seg.now .lbl{color:var(--ink);font-weight:600}
.wiz-seg.done .lbl{color:var(--mute)}
.guide{counter-reset:g;display:flex;flex-direction:column;gap:0}
.guide .g{counter-increment:g;display:flex;gap:16px;padding:16px 0;border-bottom:1px solid #202024}
.guide .g:last-child{border-bottom:0}
.guide .g .gn{flex-shrink:0;width:30px;height:30px;border-radius:50%;background:var(--panel2);border:1px solid var(--line);color:var(--orange);font-weight:750;font-size:13px;display:grid;place-items:center}
.guide .g.on .gn{background:var(--orange);border-color:var(--orange);color:#0B0B0C}
.guide .g.done .gn{background:rgba(59,178,115,.16);border-color:rgba(59,178,115,.4);color:#5FD39A}
.guide .g .gt{font-weight:650;font-size:14.5px;margin-bottom:3px}
.guide .g .gd{color:var(--mute);font-size:13px;line-height:1.55}
.bignum{font-size:34px;font-weight:750;letter-spacing:-.02em;line-height:1}
.prog{height:8px;border-radius:20px;background:var(--panel2);overflow:hidden;margin-top:10px}
.prog>span{display:block;height:100%;background:var(--orange);border-radius:20px;transition:width .3s}
@media(max-width:640px){.grid{grid-template-columns:1fr}.wiz-seg .lbl{display:none}}
@media(max-width:640px){.grid{grid-template-columns:1fr}}
</style>
<script nonce="<?=e(Auth::nonce())?>">
document.addEventListener('submit',function(ev){var f=ev.target;if(f&&f.dataset&&f.dataset.confirm&&!confirm(f.dataset.confirm))ev.preventDefault();});
</script></head><body><header><div class="wrap"><div class="hd">
<div class="logo">O</div><a class="brand" href="index.php" style="color:var(--ink)">Outlier</a><div class="sp"></div>
<?php foreach ($nav as $label => $href) echo '<a class="btn2" href="' . e($href) . '">' . e($label) . '</a> '; ?>
</div></div></header><div class="wrap">
<?php }
function foot(){ echo '</div></body></html>'; }
function flash($msg, $err = null, $warn = null) {
    if ($msg) echo '<div class="msg m-ok">' . e($msg) . '</div>';
    if ($err) echo '<div class="msg m-bad">' . e($err) . '</div>';
    if ($warn) echo '<div class="msg m-warn">' . e($warn) . '</div>';
}
function wizard(array $steps, $current) {
    $n = count($steps);
    echo '<div class="wiz"><div class="wiz-head">Step ' . (int)$current . ' of ' . $n . ' &middot; ' . e($steps[$current-1]) . '</div><div class="wiz-bar">';
    foreach ($steps as $i => $label) {
        $k = $i + 1; $cls = $k < $current ? 'done' : ($k === $current ? 'now' : 'todo');
        echo '<div class="wiz-seg ' . $cls . '"><span class="dot">' . ($k < $current ? '&#10003;' : $k) . '</span><span class="lbl">' . e($label) . '</span></div>';
    }
    echo '</div></div>';
}
function customerNav($u) {
    $n = ['Dashboard' => 'app.php', 'Write a script' => 'write.php', 'Plan' => 'plan.php', 'Settings' => 'onboard.php', 'Log out' => 'logout.php'];
    if ($u['role'] === 'admin') $n = ['Admin' => 'admin.php'] + $n;
    return $n;
}
function adminNav() {
    return ['Customers' => 'admin.php', 'Codes' => 'admin.php?view=codes', 'Users' => 'admin.php?view=users',
            'Self-test' => 'selftest.php', 'My dashboard' => 'app.php', 'Log out' => 'logout.php'];
}
function statusChip($s) {
    $map = ['done'=>'c-ok','paying'=>'c-ok','active'=>'c-ok','pending'=>'c-warn','trial'=>'c-warn','queued'=>'c-warn','running'=>'c-warn',
            'past_due'=>'c-bad','failed'=>'c-bad','blocked'=>'c-bad','disabled'=>'c-bad','skipped'=>'c-dim','cancelled'=>'c-dim','churned'=>'c-dim','paused'=>'c-dim'];
    return '<span class="chip ' . ($map[$s] ?? 'c-dim') . '">' . e(str_replace('_', ' ', $s)) . '</span>';
}
