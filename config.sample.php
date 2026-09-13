<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Outlier — configuration
   Copy this file to config.php and fill it in. Never upload config.sample.php
   with real values; delete it from the server after copying.
   ═══════════════════════════════════════════════════════════════════════════ */
return [

  /* ── Database ── cPanel → MySQL Databases ──────────────────────────────── */
  'db' => [
    'host' => 'localhost',
    'name' => 'weigigbt_outlier',
    'user' => 'weigigbt_outlier',
    'pass' => '',
  ],

  /* ── Site ──────────────────────────────────────────────────────────────── */
  'base_url'       => 'https://social.boxite.co',   // no trailing slash; used in emails and Google sign-in

  /* ── First admin login ─────────────────────────────────────────────────── */
  /* install.php creates this login ONCE. After that, change the password from
     inside the app (Settings) — this file is never read for logins again.
     At least 10 characters, letters and numbers. Never put it in a URL. */
  'admin_email'    => 'imadhuda@gmail.com',
  'admin_password' => '',

  /* ── API keys ──────────────────────────────────────────────────────────── */
  'apify_token'    => '',               // console.apify.com → Settings → Integrations  (you pay; customers never see it)
  'anthropic_key'  => '',               // console.anthropic.com → API Keys
  'resend_key'     => '',               // resend.com → API Keys (blank = no email: signups verify instantly)
  'from_email'     => 'imad@boxite.co', // must be on a domain verified in Resend
  'from_name'      => 'Outlier',

  /* ── Google sign-in + YouTube connect (optional, both blank = off) ─────── */
  /* console.cloud.google.com → APIs & Services → Credentials → OAuth client
     (Web application). Authorised redirect URI:  {base_url}/google-auth.php
     Enable "YouTube Data API v3" for the YouTube connect button. */
  'google_client_id'     => '',
  'google_client_secret' => '',

  /* ── Behaviour ─────────────────────────────────────────────────────────── */
  'trial_days'          => 30,
  'own_posts'           => 20,  // the creator's own posts — cheap, and it scores their content
  'competitor_posts'    => 30,  // more history per competitor means better patterns
  'competitor_transcripts' => 8,// the paid step: only genuine outliers are transcribed
  'own_transcripts'     => 6,   // ONLY on the first run, to build the voice profile
  'scripts_per_run'     => 7,   // weekly batch for paying customers (trial batch = 10)
  'min_days_to_score'   => 7,   // never score a post younger than this
  'timezone'            => 'Asia/Dubai',   // display only; the server clock is UTC
];
