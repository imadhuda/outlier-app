<?php
/* Customer setup — a step-by-step wizard until the account is live, then a
   plain settings page. Instagram ownership is proved with a code in the bio,
   which needs no Meta app review. */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/lib/apify.php';
require_once __DIR__ . '/lib/ui.php';
app_boot();
$u = Auth::requireLogin();
$cfg = outlier_config();
$msg = null; $err = null; $warn = null; $goto = null;

function cleanHandle($s) {
    $s = trim((string)$s);
    $s = preg_replace('#^https?://(www\.)?instagram\.com/#i', '', $s);
    return strtolower(trim(preg_replace('/[?#].*$/', '', $s), "@/ \t"));
}
$c = one("SELECT * FROM customers WHERE user_id=?", [$u['id']]);

try {
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $ig = cleanHandle($_POST['ig_handle'] ?? '');
        $comp = array_values(array_unique(array_filter(array_map('cleanHandle',
                 preg_split('/[\s,]+/', (string)($_POST['competitors'] ?? ''))))));
        $niche = trim((string)($_POST['niche'] ?? ''));
        $dur = in_array($_POST['duration_pref'] ?? '', ['30-40 sec','45-50 sec','60 sec'], true) ? $_POST['duration_pref'] : '30-40 sec';
        $lang = in_array($_POST['language'] ?? '', ['English','Hinglish','Urdu','Arabic'], true) ? $_POST['language'] : 'English';
        $plats = array_values(array_intersect(['instagram','youtube'], (array)($_POST['platforms'] ?? [])));
        if (!in_array('instagram', $plats, true)) $plats[] = 'instagram';   // analysis runs on Instagram for now
        $platforms = implode(',', $plats);
        if (!$ig) throw new RuntimeException('Your Instagram handle is required.');
        if (in_array($ig, $comp, true)) throw new RuntimeException('Your own handle is listed as a competitor.');
        if (count($comp) < 1) throw new RuntimeException('Add at least one competitor.');
        if (count($comp) > 6) throw new RuntimeException('Six competitors is the maximum.');
        if (mb_strlen($niche) < 4) throw new RuntimeException('Describe your niche.');

        /* Repeat-trial check against the ledger — a handle that had a trial under any
           other account is refused, whether or not that account still exists. */
        $seen = one("SELECT * FROM handle_ledger WHERE platform='instagram' AND handle=?", [$ig]);
        if ($seen && (int)$seen['customer_id'] !== (int)($c['id'] ?? 0)) {
            $owner = $seen['customer_id'] ? one("SELECT user_id FROM customers WHERE id=?", [$seen['customer_id']]) : null;
            if (!$owner || (int)$owner['user_id'] !== (int)$u['id'])
                throw new RuntimeException('@' . $ig . ' is already registered to another account. If that is you, log in to that account instead.');
        }

        if ($c) {
            $igChanged = $c['ig_handle'] !== $ig;
            q("UPDATE customers SET name=?, email=?, ig_handle=?, competitors=?, niche=?, language=?, platforms=?, duration_pref=?,
               ig_verified=IF(?,0,ig_verified), ig_verify_code=IF(?,NULL,ig_verify_code) WHERE id=?",
              [$u['name'] ?: $u['email'], $u['email'], $ig, json_encode($comp), $niche, $lang, $platforms, $dur,
               $igChanged ? 1 : 0, $igChanged ? 1 : 0, $c['id']]);
        } else {
            q("INSERT INTO customers (user_id,name,email,ig_handle,competitors,niche,language,platforms,duration_pref,status,trial_start,trial_end)
               VALUES (?,?,?,?,?,?,?,?,?,'trial',CURDATE(),DATE_ADD(CURDATE(), INTERVAL ? DAY))",
              [$u['id'], $u['name'] ?: $u['email'], $u['email'], $ig, json_encode($comp), $niche, $lang, $platforms, $dur, (int)($cfg['trial_days'] ?? 30)]);
        }
        $c = one("SELECT * FROM customers WHERE user_id=?", [$u['id']]);
        $msg = 'Saved.'; $goto = 'verify';
    }

    if ($act === 'password') {
        if (!$u['password_hash'] || !password_verify((string)($_POST['current'] ?? ''), $u['password_hash'])) throw new RuntimeException('Current password is wrong.');
        if (!Auth::passwordOk($_POST['password'] ?? '')) throw new RuntimeException('New password needs at least 10 characters with letters and numbers.');
        q("UPDATE users SET password_hash=? WHERE id=?", [Auth::hash($_POST['password']), $u['id']]);
        $msg = 'Password changed.';
    }

    if ($act === 'verify_ig' && $c) {
        if (!Auth::throttle('igverify', 10)) throw new RuntimeException('Too many verification attempts. Wait 15 minutes.');
        if (!$c['ig_verify_code']) {
            q("UPDATE customers SET ig_verify_code=? WHERE id=?", ['OUT-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)), $c['id']]);
            $c = one("SELECT * FROM customers WHERE id=?", [$c['id']]);
            $goto = 'verify';
        } else {
            $ap = new Apify($cfg['apify_token'] ?? '');
            $rows = $ap->runSync(Apify::IG_SCRAPER, Apify::igProfileInput($c['ig_handle']), 60);
            $bio = (string)($rows[0]['biography'] ?? '');
            $isPrivate = !empty($rows[0]['private']);
            if (!$rows) { $err = 'Instagram did not return that profile. Check the handle and that the account is public.'; $goto = 'verify'; }
            elseif ($isPrivate) { $err = 'That account is private. Make it public for the trial — we only read what anyone can see.'; $goto = 'verify'; }
            elseif (stripos($bio, $c['ig_verify_code']) === false) { $err = 'The code is not in the bio yet. Save the bio, wait a minute, then try again.'; $goto = 'verify'; }
            else {
                q("UPDATE customers SET ig_verified=1, onboarded=1, onboarded_at=COALESCE(onboarded_at, UTC_TIMESTAMP()) WHERE id=?", [$c['id']]);
                q("INSERT INTO handle_ledger (platform,handle,customer_id) VALUES ('instagram',?,?)
                   ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id)", [$c['ig_handle'], $c['id']]);
                $c = one("SELECT * FROM customers WHERE id=?", [$c['id']]);
                $msg = 'Instagram verified. You can remove the code from your bio now.';
                $goto = Auth::googleEnabled() ? 'youtube' : 'done';
            }
        }
    }
} catch (HttpError $e) { $err = 'Could not reach Instagram right now. Try again in a minute.'; $goto = 'verify'; }
  catch (Throwable $e) { $err = $e->getMessage(); }

$onboarded = $c && $c['onboarded'] && $c['ig_verified'];
$comp = $c ? implode(', ', json_decode((string)$c['competitors'], true) ?: []) : '';
$curPlats = $c ? array_filter(explode(',', (string)$c['platforms'])) : ['instagram'];
function platformField($cur) {
    $ig = in_array('instagram', $cur, true) || !$cur;
    $yt = in_array('youtube', $cur, true);
    return '<div class="field"><label>Which platforms do you post short-form on?</label>'
        . '<label style="text-transform:none;letter-spacing:0;color:var(--ink);font-weight:500;display:flex;align-items:center;gap:8px;margin:0 0 6px">'
        . '<input type="checkbox" checked disabled style="width:auto"> Instagram <span style="color:var(--dim);font-size:12px">(required — the analysis runs here for now)</span></label>'
        . '<label style="text-transform:none;letter-spacing:0;color:var(--ink);font-weight:500;display:flex;align-items:center;gap:8px;margin:0">'
        . '<input type="checkbox" name="platforms[]" value="youtube" style="width:auto"' . ($yt ? ' checked' : '') . '> YouTube <span style="color:var(--dim);font-size:12px">(optional — connect it below to factor your channel in)</span></label></div>';
}

/* ═══ SETTINGS MODE (already live) ═══════════════════════════════════════ */
if ($onboarded && ($_GET['mode'] ?? '') !== 'wizard') {
    head('Settings', customerNav($u));
    echo '<h1>Settings</h1><div class="sub">Change what we analyse, your script language and length, or your password. We never post anything.</div>';
    flash($msg, $err, $warn);
    echo '<div class="grid" style="align-items:start">';
    echo '<div class="card"><h2>Accounts &amp; niche</h2><form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="save">'
       . '<div class="field"><label>Your Instagram handle</label><input name="ig_handle" value="' . e($c['ig_handle']) . '" required>'
       . '<div style="font-size:12px;color:var(--dim);margin-top:5px">Changing this will ask you to verify again.</div></div>'
       . platformField($curPlats)
       . '<div class="field"><label>Competitors — 1 to 6 handles</label><input name="competitors" value="' . e($comp) . '" required></div>'
       . '<div class="field"><label>Your niche — headline, then sub-niches separated by commas</label><input name="niche" value="' . e($c['niche']) . '" required></div>'
       . '<div class="grid"><div class="field"><label>Script language</label><select name="language">';
    foreach (['English','Hinglish','Urdu','Arabic'] as $l) echo '<option' . ($c['language'] === $l ? ' selected' : '') . '>' . $l . '</option>';
    echo '</select></div><div class="field"><label>Script length</label><select name="duration_pref">';
    foreach (['30-40 sec','45-50 sec','60 sec'] as $d) echo '<option' . ($c['duration_pref'] === $d ? ' selected' : '') . '>' . $d . '</option>';
    echo '</select></div></div><button class="btn">Save changes</button></form></div>';

    echo '<div>';
    echo '<div class="card"><h2>Connections</h2>'
       . '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">' . statusChip('done') . '<span>Instagram @' . e($c['ig_handle']) . ' verified</span></div>';
    if (Auth::googleEnabled()) {
        echo $c['yt_verified']
            ? '<div style="display:flex;align-items:center;gap:10px">' . statusChip('done') . '<span>YouTube connected</span></div>'
            : '<a class="btn2" href="' . e(Auth::googleUrl('openid email profile https://www.googleapis.com/auth/youtube.readonly')) . '">Connect YouTube (optional)</a>';
    }
    echo '</div>';
    if ($u['password_hash']) {
        echo '<div class="card"><h2>Password</h2><form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="password">'
           . '<div class="field"><label>Current password</label><input name="current" type="password" required autocomplete="current-password"></div>'
           . '<div class="field"><label>New password</label><input name="password" type="password" required minlength="10" autocomplete="new-password"></div>'
           . '<button class="btn2">Change password</button></form></div>';
    }
    echo '</div></div>';
    echo '<div style="margin-top:4px"><a class="btn" href="app.php">&larr; Back to dashboard</a></div>';
    foot(); exit;
}

/* ═══ WIZARD MODE (not live yet) ═════════════════════════════════════════ */
$flow = ['welcome', 'accounts', 'verify'];
if (Auth::googleEnabled()) $flow[] = 'youtube';
$flow[] = 'done';
$labels = ['welcome'=>'Welcome','accounts'=>'Your accounts','verify'=>'Verify Instagram','youtube'=>'Connect YouTube','done'=>'Start posting'];

/* Which steps the customer's state allows them to reach. */
$reach = ['welcome'=>true, 'accounts'=>true, 'verify'=>(bool)$c,
          'youtube'=>(bool)($c && $c['ig_verified']), 'done'=>(bool)($c && $c['ig_verified'])];

$step = $goto ?: ($_GET['step'] ?? 'welcome');
if (!in_array($step, $flow, true) || empty($reach[$step])) {
    /* Land on the furthest reachable step. */
    $step = 'welcome';
    foreach ($flow as $k) if (!empty($reach[$k])) $step = $k;
}
$idx = array_search($step, $flow, true) + 1;
$stepLabels = array_map(fn($k) => $labels[$k], $flow);

head('Set up your account', customerNav($u));
echo '<div style="max-width:760px;margin:0 auto">';
wizard($stepLabels, $idx);
flash($msg, $err, $warn);

if ($step === 'welcome') {
    echo '<div class="hero"><h1 style="font-size:26px;margin-bottom:8px">Welcome to Outlier</h1>'
       . '<div style="color:var(--mute);font-size:15px;line-height:1.6;margin-bottom:22px">We study the reels that are actually winning in your niche — yours and your competitors\' — and write your next scripts in your own voice. Here is how the free trial works:</div>'
       . '<div class="guide">'
       . '<div class="g on"><div class="gn">1</div><div><div class="gt">Tell us your accounts</div><div class="gd">Your Instagram handle, 1–6 competitors, your niche, and the language and length you want your scripts in.</div></div></div>'
       . '<div class="g"><div class="gn">2</div><div><div class="gt">Verify your Instagram</div><div class="gd">Paste a short code in your bio for a minute so we know the account is really yours. This also stops anyone taking the free trial twice.</div></div></div>'
       . '<div class="g"><div class="gn">3</div><div><div class="gt">Post ' . Billing::TRIAL_MIN_POSTS . ' videos in 7 days</div><div class="gd">Keep creating as normal. We watch how your reels perform against your competitors\'.</div></div></div>'
       . '<div class="g"><div class="gn">4</div><div><div class="gt">Get your first ' . Billing::TRIAL_MIN_POSTS . ' scripts — free</div><div class="gd">Written to your voice, with the analysis behind them and an Excel report. Like them? Pick a plan and a fresh batch lands every week.</div></div></div>'
       . '</div><div style="margin-top:24px"><a class="btn" href="onboard.php?step=accounts">Let&rsquo;s set up &rarr;</a></div></div>';
}

elseif ($step === 'accounts') {
    echo '<div class="card"><h2>Step 1 — Your accounts and niche</h2>'
       . '<div class="sub">This is what we point the analysis at. You can change any of it later.</div>'
       . '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="save">'
       . platformField($curPlats)
       . '<div class="field"><label>Your Instagram handle</label><input name="ig_handle" value="' . e($c['ig_handle'] ?? '') . '" placeholder="yourhandle" required autofocus></div>'
       . '<div class="field"><label>Competitors — 1 to 6 handles</label><input name="competitors" value="' . e($comp) . '" placeholder="creator1, creator2, creator3" required>'
       . '<div style="font-size:12px;color:var(--dim);margin-top:5px">Accounts in your niche whose reels you want to beat. Comma or space separated.</div></div>'
       . '<div class="field"><label>Your niche — headline, then sub-niches separated by commas</label>'
       . '<input name="niche" value="' . e($c['niche'] ?? '') . '" placeholder="UAE real estate — solo brokers, brokerage owners, developers" required>'
       . '<div style="font-size:12px;color:var(--dim);margin-top:5px">Example: <i>UAE real estate — solo brokers, brokerage owners, developers</i></div></div>'
       . '<div class="grid"><div class="field"><label>Script language</label><select name="language">';
    foreach (['Hinglish','English','Urdu','Arabic'] as $l) echo '<option' . (($c['language'] ?? 'Hinglish') === $l ? ' selected' : '') . '>' . $l . '</option>';
    echo '</select></div><div class="field"><label>Script length</label><select name="duration_pref">';
    foreach (['30-40 sec','45-50 sec','60 sec'] as $d) echo '<option' . (($c['duration_pref'] ?? '30-40 sec') === $d ? ' selected' : '') . '>' . $d . '</option>';
    echo '</select></div></div>'
       . '<div style="display:flex;gap:10px;align-items:center"><a class="btn2" href="onboard.php?step=welcome">&larr; Back</a><button class="btn">Save &amp; continue &rarr;</button></div>'
       . '</form></div>';
}

elseif ($step === 'verify') {
    echo '<div class="card"><h2>Step 2 — Prove the Instagram account is yours</h2>';
    if (!$c['ig_verify_code']) {
        echo '<div class="sub">This takes a minute. It stops anyone else claiming your account — and stops the same person taking the free trial twice. Your account must be public (we only ever read what anyone can see; we never post).</div>'
           . '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="verify_ig">'
           . '<div style="display:flex;gap:10px;align-items:center"><a class="btn2" href="onboard.php?step=accounts">&larr; Back</a><button class="btn">Get my verification code</button></div></form>';
    } else {
        echo '<div class="sub">Almost there — put this code in your @' . e($c['ig_handle']) . ' bio, then press Verify.</div>'
           . '<ol class="steps" style="margin:8px 0 18px">'
           . '<li>Open Instagram &rarr; Edit profile &rarr; Bio</li>'
           . '<li>Paste this code anywhere in your bio and save:<div class="code" style="margin-top:10px">' . e($c['ig_verify_code']) . '</div></li>'
           . '<li>Wait about a minute, then press Verify below. You can remove the code afterwards.</li></ol>'
           . '<form method="post">' . Auth::csrfField() . '<input type="hidden" name="action" value="verify_ig">'
           . '<button class="btn">Verify now</button></form>';
    }
    echo '</div>';
}

elseif ($step === 'youtube') {
    echo '<div class="card"><h2>Step 3 — Connect YouTube <span style="color:var(--dim);font-weight:400">(optional)</span></h2>'
       . '<div class="sub">If you also post on YouTube, connect it and we will factor your channel in too. You can skip this and do it later from Settings.</div>';
    if ($c['yt_verified']) echo '<div class="msg m-ok">YouTube is already connected.</div>';
    else echo '<a class="btn" href="' . e(Auth::googleUrl('openid email profile https://www.googleapis.com/auth/youtube.readonly')) . '">Connect YouTube</a> ';
    echo '<a class="btn2" href="onboard.php?step=done">Skip for now &rarr;</a></div>';
}

elseif ($step === 'done') {
    $recent = Billing::recentOwnPosts($c['id']);
    echo '<div class="hero"><h1 style="font-size:26px;margin-bottom:8px">You&rsquo;re all set 🎉</h1>'
       . '<div style="color:var(--mute);font-size:15px;line-height:1.6;margin-bottom:8px">@' . e($c['ig_handle']) . ' is verified. Now the only thing left is to <b style="color:var(--ink)">post</b>.</div>'
       . '<div style="margin:18px 0"><div class="bignum">' . $recent . ' / ' . Billing::TRIAL_MIN_POSTS . '</div>'
       . '<div style="font-size:12.5px;color:var(--mute);margin-top:4px">videos posted in the last 7 days</div>'
       . '<div class="prog"><span style="width:' . min(100, round($recent / Billing::TRIAL_MIN_POSTS * 100)) . '%"></span></div></div>'
       . '<div class="guide" style="margin:8px 0 22px">'
       . '<div class="g done"><div class="gn">&#10003;</div><div><div class="gt">Accounts &amp; Instagram verified</div></div></div>'
       . '<div class="g on"><div class="gn">3</div><div><div class="gt">Post ' . Billing::TRIAL_MIN_POSTS . ' videos within 7 days</div><div class="gd">Keep posting as normal on @' . e($c['ig_handle']) . '. We check automatically and start your analysis the moment you hit ' . Billing::TRIAL_MIN_POSTS . '.</div></div></div>'
       . '<div class="g"><div class="gn">4</div><div><div class="gt">Get your first ' . Billing::TRIAL_MIN_POSTS . ' scripts</div><div class="gd">We email you and they appear on your dashboard, with the full analysis and an Excel report.</div></div></div>'
       . '</div><a class="btn" href="app.php">Go to my dashboard &rarr;</a></div>';
}

echo '</div>';
foot();
