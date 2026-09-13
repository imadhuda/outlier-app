OUTLIER — deployment notes (v3: logins, admin + customer panels, plans)
=======================================================================

WHAT CHANGED
  • No more ?key= in any URL. Everyone logs in with email + password
    (or Google). Sessions are HttpOnly/Secure cookies, CSRF on every form,
    5-strikes lockout, Argon2id hashing, one-time links stored hashed.
  • Two panels: admin.php (you) and app.php (customers). A customer can only
    ever see their own runs, scripts and exports.
  • Self-serve signup → onboarding → Instagram ownership proof (bio code)
    → free trial (post 10 videos in 7 days → 10 scripts) → plan.
  • Plans: Starter $19/15 scripts, Growth $29/30, Unlimited $99. Promo and
    affiliate codes with discount + commission tracking. Payment is manual:
    customer requests, you send a payment link, you press "Paid — activate".
  • Weekly runs are scheduled automatically by the cron worker. Trial
    accounts get a cheap own-account check first (≈ $0.05), never the full
    scrape until they have actually posted 10 videos.

UPLOAD (replace everything except config.php)
  root: index.php login.php signup.php verify.php logout.php forgot.php
        google-auth.php onboard.php app.php plan.php admin.php export.php
        selftest.php install.php worker.php .htaccess config.sample.php
  lib/: db.php schema.php engine.php http.php apify.php anthropic.php mail.php
        queue.php pipeline.php ui.php xlsx.php auth.php billing.php
        scheduler.php report.php .htaccess
  tests/: engine_test.php pipeline_test.php pages_test.php run_cli.php .htaccess
  DELETE from the server: check2.php, hello.php, outlier-check.php, and
  config.sample.php once config.php is done.

CONFIG.PHP — add these keys (copy from config.sample.php)
  'base_url'        => 'https://social.boxite.co',
  'admin_email'     => 'imadhuda@gmail.com',
  'admin_password'  => '<NEW password — the old one was visible in screenshots>',
  'google_client_id' / 'google_client_secret'  (optional, see below)

FIRST RUN
  1. Open https://social.boxite.co/install.php — it asks for admin_password
     in a form (not the URL), builds the new tables, seeds the plans and
     creates your admin login from admin_email / admin_password.
  2. Log in at /login.php. Change the password under Settings.
  3. Delete install.php from the server.
  4. /selftest.php (admin only) shows the security checklist.
  5. Cron stays the same: */5 * * * *  /usr/local/bin/php -q /home/weigigbt/social.boxite.co/worker.php

GOOGLE SIGN-IN (optional)
  console.cloud.google.com → new project → APIs & Services → OAuth consent
  screen (External, add scopes email/profile and youtube.readonly) →
  Credentials → Create OAuth client ID → Web application →
  Authorised redirect URI: https://social.boxite.co/google-auth.php
  Paste the client ID + secret into config.php. Enable "YouTube Data API v3"
  for the "Connect YouTube" button. Leave both blank and the buttons hide.

INSTAGRAM VERIFICATION
  No Meta app review needed. The customer pastes a code (OUT-XXXXX) in their
  bio, we read the public profile once via Apify, then they remove it.
  You can also press "Mark verified" in the admin panel for people you know.

TESTS (236)
  php tests/run_cli.php       55  analysis engine
  php tests/pipeline_test.php 87  pipeline, trial gate, quota, promo codes (mocked APIs)
  php tests/pages_test.php    94  every page over HTTP: auth, CSRF, isolation, admin


────────────────────────────────────────────────────────────────────────
v4 additions (Script Writer, platforms, story ideas, audience questions)
────────────────────────────────────────────────────────────────────────
NEW customer features:
  • Script Writer (write.php) — the creator types a topic; we search THEIR
    scraped corpus (own + competitors) for real videos on it and write one
    script in their voice. No match? We ask for their research first, then
    write using the style of their top reels. Counts as 1 script from the
    plan; capped at 20/day per customer.
  • Platform choice at onboarding — Instagram (required; the analysis runs
    here) and optional YouTube connect via Google.
  • Story ideas — a button on the dashboard suggests quick Stories for today
    from the analysis. (We suggest ideas; we do NOT scrape anyone's stories —
    stories are 24h and not reliably scrapable.)
  • Audience questions — question-comments on the creator's own posts are
    surfaced as reply / video ideas (best-effort; scraped data can't always
    tell if you already replied).

TO UPGRADE an existing install: upload the new files and open install.php
once (log in as admin first). It adds three tables (writer_requests,
comment_leads, story_ideas) and the customers.platforms column. Nothing is
dropped; existing data is untouched.

NOTE on Instagram "login": a Google-style Instagram OAuth is not available
(Meta shut the simple API in Dec 2024; the real Graph API needs Business
account + Meta App Review). We use bio-code verification + public scraping,
which gives the same performance data without the review. YouTube uses real
Google OAuth.

TESTS now: 55 engine + 105 pipeline + 106 pages = 266.
