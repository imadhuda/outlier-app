<?php
/* Every table declares utf8mb4 explicitly. The database default on the target
   server is latin1, which would silently mangle Hinglish text and em-dashes. */
function outlier_schema() {
    $T = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [

    'users' => "CREATE TABLE IF NOT EXISTS users (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        email          VARCHAR(190) NOT NULL,
        password_hash  VARCHAR(255) NULL,
        name           VARCHAR(120) NOT NULL DEFAULT '',
        role           ENUM('customer','admin') NOT NULL DEFAULT 'customer',
        google_id      VARCHAR(64)  NULL,
        email_verified TINYINT(1)   NOT NULL DEFAULT 0,
        verify_token   VARCHAR(64)  NULL,
        reset_token    VARCHAR(64)  NULL,
        reset_expires  DATETIME     NULL,
        status         ENUM('active','disabled') NOT NULL DEFAULT 'active',
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at  DATETIME     NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_email (email),
        UNIQUE KEY uq_google (google_id)
    ) $T",

    'login_attempts' => "CREATE TABLE IF NOT EXISTS login_attempts (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip         VARCHAR(45)  NOT NULL,
        email      VARCHAR(190) NOT NULL DEFAULT '',
        attempted  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        success    TINYINT(1)   NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY ix_ip_time (ip, attempted),
        KEY ix_email_time (email, attempted)
    ) $T",

    'plans' => "CREATE TABLE IF NOT EXISTS plans (
        id            VARCHAR(32)   NOT NULL,
        name          VARCHAR(80)   NOT NULL,
        price_usd     DECIMAL(8,2)  NOT NULL DEFAULT 0,
        scripts_month INT UNSIGNED  NULL,
        runs_month    TINYINT UNSIGNED NOT NULL DEFAULT 4,
        active        TINYINT(1)    NOT NULL DEFAULT 1,
        sort          TINYINT       NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) $T",

    'promo_codes' => "CREATE TABLE IF NOT EXISTS promo_codes (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code           VARCHAR(40)  NOT NULL,
        kind           ENUM('promo','affiliate') NOT NULL DEFAULT 'promo',
        discount_pct   TINYINT UNSIGNED NOT NULL DEFAULT 0,
        discount_usd   DECIMAL(8,2) NOT NULL DEFAULT 0,
        affiliate_user INT UNSIGNED NULL,
        commission_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
        max_uses       INT UNSIGNED NULL,
        uses           INT UNSIGNED NOT NULL DEFAULT 0,
        expires_at     DATE         NULL,
        active         TINYINT(1)   NOT NULL DEFAULT 1,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_code (code)
    ) $T",

    'subscriptions' => "CREATE TABLE IF NOT EXISTS subscriptions (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id       INT UNSIGNED NOT NULL,
        plan_id       VARCHAR(32)  NOT NULL,
        status        ENUM('trial','active','past_due','cancelled') NOT NULL DEFAULT 'trial',
        promo_code    VARCHAR(40)  NULL,
        price_paid    DECIMAL(8,2) NULL,
        started_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        renews_at     DATE         NULL,
        cancelled_at  DATETIME     NULL,
        scripts_used  INT UNSIGNED NOT NULL DEFAULT 0,
        period_start  DATE         NULL,
        stripe_sub_id VARCHAR(80)  NULL,
        PRIMARY KEY (id),
        KEY ix_user (user_id),
        KEY ix_renews (status, renews_at)
    ) $T",

    'customers' => "CREATE TABLE IF NOT EXISTS customers (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id       INT UNSIGNED NULL,
        ig_verified   TINYINT(1)    NOT NULL DEFAULT 0,
        ig_verify_code VARCHAR(12)  NULL,
        yt_verified   TINYINT(1)    NOT NULL DEFAULT 0,
        yt_channel_id VARCHAR(64)   NULL,
        onboarded     TINYINT(1)    NOT NULL DEFAULT 0,
        onboarded_at  DATETIME      NULL,
        name          VARCHAR(120)  NOT NULL,
        email         VARCHAR(190)  NOT NULL,
        ig_handle     VARCHAR(120)  NOT NULL DEFAULT '',
        yt_channel    VARCHAR(190)  NOT NULL DEFAULT '',
        niche         VARCHAR(160)  NOT NULL DEFAULT '',
        competitors   TEXT          NULL,
        language      VARCHAR(40)   NOT NULL DEFAULT 'English',
        platforms     VARCHAR(40)   NOT NULL DEFAULT 'instagram',
        status        ENUM('trial','paying','paused','churned','blocked') NOT NULL DEFAULT 'trial',
        trial_start   DATE          NULL,
        trial_end     DATE          NULL,
        price_aed     DECIMAL(10,2) NULL,
        next_bill     DATE          NULL,
        notes         TEXT          NULL,
        voice_profile MEDIUMTEXT    NULL,
        voice_built_at DATETIME     NULL,
        hook_patterns TEXT          NULL,
        duration_pref VARCHAR(20)   NOT NULL DEFAULT '30-40 sec',
        run_every_days TINYINT UNSIGNED NOT NULL DEFAULT 7,
        auto_run      TINYINT(1)    NOT NULL DEFAULT 1,
        created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_email (email),
        KEY ix_status (status),
        KEY ix_next_bill (next_bill)
    ) $T",

    /* Survives customer deletion on purpose: this is the repeat-trial check. */
    'handle_ledger' => "CREATE TABLE IF NOT EXISTS handle_ledger (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        platform       ENUM('instagram','youtube') NOT NULL,
        handle         VARCHAR(190) NOT NULL,
        customer_id    INT UNSIGNED NULL,
        first_trial_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_platform_handle (platform, handle)
    ) $T",

    'runs' => "CREATE TABLE IF NOT EXISTS runs (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id       INT UNSIGNED NOT NULL,
        kind              ENUM('full','trial_check') NOT NULL DEFAULT 'full',
        started_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at       DATETIME     NULL,
        status            ENUM('queued','running','done','failed','skipped') NOT NULL DEFAULT 'queued',
        posts_scored      INT UNSIGNED NOT NULL DEFAULT 0,
        too_fresh         INT UNSIGNED NOT NULL DEFAULT 0,
        outliers_found    INT UNSIGNED NOT NULL DEFAULT 0,
        false_outliers    INT UNSIGNED NOT NULL DEFAULT 0,
        scripts_delivered INT UNSIGNED NOT NULL DEFAULT 0,
        apify_cost_usd    DECIMAL(10,4) NOT NULL DEFAULT 0,
        findings          MEDIUMTEXT   NULL,
        narrative         MEDIUMTEXT   NULL,
        error             TEXT         NULL,
        PRIMARY KEY (id),
        KEY ix_customer (customer_id),
        KEY ix_status (status),
        CONSTRAINT fk_runs_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    ) $T",

    'reels' => "CREATE TABLE IF NOT EXISTS reels (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        run_id        INT UNSIGNED NOT NULL,
        customer_id   INT UNSIGNED NOT NULL,
        is_own        TINYINT(1)   NOT NULL DEFAULT 0,
        handle        VARCHAR(120) NOT NULL,
        code          VARCHAR(60)  NOT NULL,
        url           VARCHAR(300) NOT NULL DEFAULT '',
        plays         INT UNSIGNED NOT NULL DEFAULT 0,
        views         INT UNSIGNED NOT NULL DEFAULT 0,
        likes         INT UNSIGNED NOT NULL DEFAULT 0,
        comment_count INT UNSIGNED NOT NULL DEFAULT 0,
        duration_s    DECIMAL(8,2) NOT NULL DEFAULT 0,
        posted_at     DATETIME     NULL,
        age_days      DECIMAL(10,2) NULL,
        ratio         DECIMAL(6,4) NULL,
        outlier_score DECIMAL(10,3) NOT NULL DEFAULT 0,
        velocity      DECIMAL(12,2) NOT NULL DEFAULT 0,
        flag          ENUM('normal','false','high-retention') NOT NULL DEFAULT 'normal',
        is_outlier    TINYINT(1)   NOT NULL DEFAULT 0,
        hook          TEXT         NULL,
        transcript    MEDIUMTEXT   NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_run_code (run_id, code),
        KEY ix_customer (customer_id),
        KEY ix_velocity (velocity),
        CONSTRAINT fk_reels_run FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE CASCADE
    ) $T",

    'scripts' => "CREATE TABLE IF NOT EXISTS scripts (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        run_id         INT UNSIGNED NOT NULL,
        customer_id    INT UNSIGNED NOT NULL,
        idx            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        niche          VARCHAR(120) NOT NULL DEFAULT '',
        concept        VARCHAR(190) NOT NULL DEFAULT '',
        hook_pattern   VARCHAR(60)  NOT NULL DEFAULT '',
        hook_line      TEXT         NULL,
        body           MEDIUMTEXT   NULL,
        duration_label VARCHAR(30)  NOT NULL DEFAULT '',
        caption        TEXT         NULL,
        cta_keyword    VARCHAR(60)  NOT NULL DEFAULT '',
        source_insight TEXT         NULL,
        status         ENUM('draft','recorded','posted','scored') NOT NULL DEFAULT 'draft',
        posted_code    VARCHAR(60)  NULL,
        actual_plays   INT UNSIGNED NULL,
        actual_velocity DECIMAL(12,2) NULL,
        scored_at      DATETIME     NULL,
        PRIMARY KEY (id),
        KEY ix_run (run_id),
        KEY ix_customer_status (customer_id, status),
        CONSTRAINT fk_scripts_run FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE CASCADE
    ) $T",

    /* The queue is what makes this work on shared hosting: cron ticks often,
       each tick does one small step well inside the execution-time limit. */
    'jobs' => "CREATE TABLE IF NOT EXISTS jobs (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id  INT UNSIGNED NOT NULL,
        run_id       INT UNSIGNED NULL,
        step         VARCHAR(40)  NOT NULL,
        payload      MEDIUMTEXT   NULL,
        state        ENUM('pending','working','done','failed') NOT NULL DEFAULT 'pending',
        attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_error   TEXT         NULL,
        run_after    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at    DATETIME     NULL,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_claim (state, run_after),
        KEY ix_customer (customer_id)
    ) $T",

    /* On-demand single scripts from the Script Writer (topic -> referenced script). */
    'writer_requests' => "CREATE TABLE IF NOT EXISTS writer_requests (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id    INT UNSIGNED NOT NULL,
        user_id        INT UNSIGNED NULL,
        topic          VARCHAR(300) NOT NULL,
        had_reference  TINYINT(1)   NOT NULL DEFAULT 0,
        notes          MEDIUMTEXT   NULL,
        refs           MEDIUMTEXT   NULL,
        hook_pattern   VARCHAR(60)  NOT NULL DEFAULT '',
        hook_line      TEXT         NULL,
        body           MEDIUMTEXT   NULL,
        duration_label VARCHAR(30)  NOT NULL DEFAULT '',
        caption        TEXT         NULL,
        cta_keyword    VARCHAR(60)  NOT NULL DEFAULT '',
        source_insight TEXT         NULL,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_customer (customer_id)
    ) $T",

    /* Comments on the creator's own posts that look unanswered — reply + video ideas. */
    'comment_leads' => "CREATE TABLE IF NOT EXISTS comment_leads (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id  INT UNSIGNED NOT NULL,
        run_id       INT UNSIGNED NULL,
        reel_code    VARCHAR(60)  NOT NULL DEFAULT '',
        text         VARCHAR(600) NOT NULL,
        is_question  TINYINT(1)   NOT NULL DEFAULT 0,
        answered     TINYINT(1)   NOT NULL DEFAULT 0,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_customer (customer_id),
        UNIQUE KEY uq_cust_reel_text (customer_id, reel_code, text(160))
    ) $T",

    /* One row per customer per day: cached AI story ideas so we don't re-call the model. */
    'story_ideas' => "CREATE TABLE IF NOT EXISTS story_ideas (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_id  INT UNSIGNED NOT NULL,
        day          DATE         NOT NULL,
        ideas        MEDIUMTEXT   NULL,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cust_day (customer_id, day)
    ) $T",

    /* A customer asks for a paid plan; admin activates it after payment. */
    'plan_requests' => "CREATE TABLE IF NOT EXISTS plan_requests (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     INT UNSIGNED NOT NULL,
        plan_id     VARCHAR(32)  NOT NULL,
        promo_code  VARCHAR(40)  NULL,
        price_usd   DECIMAL(8,2) NOT NULL DEFAULT 0,
        status      ENUM('pending','done','rejected') NOT NULL DEFAULT 'pending',
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_status (status)
    ) $T",

    'settings' => "CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(64) NOT NULL,
        v TEXT NULL,
        PRIMARY KEY (k)
    ) $T",
    ];
}

/** Columns added after the first release. Applied by install.php, idempotent. */
/** Seed rows the app expects. INSERT IGNORE, so re-running never duplicates. */
function outlier_seed() {
    return [
        "INSERT IGNORE INTO plans (id,name,price_usd,scripts_month,runs_month,sort) VALUES
            ('trial',    'Free trial',  0,    10,  1, 0),
            ('starter',  'Starter',     19,   15,  4, 1),
            ('growth',   'Growth',      29,   30,  4, 2),
            ('unlimited','Unlimited',   99,   NULL,8, 3)",
    ];
}

function outlier_migrations() {
    return [
        ['customers', 'voice_profile',  "ALTER TABLE customers ADD COLUMN voice_profile MEDIUMTEXT NULL AFTER notes"],
        ['customers', 'voice_built_at', "ALTER TABLE customers ADD COLUMN voice_built_at DATETIME NULL AFTER voice_profile"],
        ['customers', 'hook_patterns',  "ALTER TABLE customers ADD COLUMN hook_patterns TEXT NULL AFTER voice_built_at"],
        ['runs',      'narrative',      "ALTER TABLE runs ADD COLUMN narrative MEDIUMTEXT NULL AFTER findings"],
        ['customers', 'duration_pref',  "ALTER TABLE customers ADD COLUMN duration_pref VARCHAR(20) NOT NULL DEFAULT '30-40 sec' AFTER hook_patterns"],
        ['customers', 'run_every_days', "ALTER TABLE customers ADD COLUMN run_every_days TINYINT UNSIGNED NOT NULL DEFAULT 7 AFTER duration_pref"],
        ['customers', 'auto_run',       "ALTER TABLE customers ADD COLUMN auto_run TINYINT(1) NOT NULL DEFAULT 1 AFTER run_every_days"],
        ['customers', 'user_id',        "ALTER TABLE customers ADD COLUMN user_id INT UNSIGNED NULL AFTER id, ADD KEY ix_user (user_id)"],
        ['customers', 'ig_verified',    "ALTER TABLE customers ADD COLUMN ig_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER user_id"],
        ['customers', 'ig_verify_code', "ALTER TABLE customers ADD COLUMN ig_verify_code VARCHAR(12) NULL AFTER ig_verified"],
        ['customers', 'yt_verified',    "ALTER TABLE customers ADD COLUMN yt_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER ig_verify_code"],
        ['customers', 'yt_channel_id',  "ALTER TABLE customers ADD COLUMN yt_channel_id VARCHAR(64) NULL AFTER yt_verified"],
        ['customers', 'onboarded',      "ALTER TABLE customers ADD COLUMN onboarded TINYINT(1) NOT NULL DEFAULT 0 AFTER yt_channel_id"],
        ['customers', 'onboarded_at',   "ALTER TABLE customers ADD COLUMN onboarded_at DATETIME NULL AFTER onboarded"],
        ['customers', 'platforms',      "ALTER TABLE customers ADD COLUMN platforms VARCHAR(40) NOT NULL DEFAULT 'instagram' AFTER language"],
        ['runs',      'kind',           "ALTER TABLE runs ADD COLUMN kind ENUM('full','trial_check') NOT NULL DEFAULT 'full' AFTER customer_id"],
    ];
}
