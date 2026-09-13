<?php
/* Decides, once per worker tick, who gets a run today. Cheap by design:
   a trial account is only checked for its own posts every two days; a paid
   account runs once per run_every_days and never while a batch is in flight. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/pipeline.php';

class Scheduler {
    const TRIAL_RECHECK_DAYS = 2;
    const FAIL_RETRY_DAYS    = 1;

    /** Why this customer cannot start right now, or null if they can. */
    public static function blocker(array $c, $manual = false) {
        if (!$c['user_id']) return 'Not linked to a login.';
        if (!$c['onboarded'] || !$c['ig_verified']) return 'Finish setup and verify your Instagram first.';
        if (in_array($c['status'], ['paused','churned','blocked'], true)) return 'Account is ' . $c['status'] . '.';
        if (!$c['auto_run'] && !$manual) return 'Weekly runs are paused.';
        if (one("SELECT id FROM runs WHERE customer_id=? AND status IN ('queued','running')", [$c['id']])) return 'A batch is already in progress.';
        $a = Billing::allowance($c['user_id']);
        if ($a['n'] === 0) return $a['why'];
        $last = one("SELECT started_at, status, kind FROM runs WHERE customer_id=? ORDER BY id DESC LIMIT 1", [$c['id']]);
        if ($last) {
            $age = (time() - strtotime($last['started_at'] . ' UTC')) / 86400;
            $need = $last['status'] === 'failed' ? self::FAIL_RETRY_DAYS
                  : ($last['kind'] === 'trial_check' && $last['status'] !== 'done' ? self::TRIAL_RECHECK_DAYS : (int)$c['run_every_days']);
            if ($age < $need && !($manual && $last['status'] === 'failed'))
                return 'Next run in ' . max(1, (int)ceil($need - $age)) . ' day(s).';
        }
        return null;
    }

    /** Trial customers must prove they post before the expensive run happens. */
    public static function needsTrialCheck(array $c) {
        $a = Billing::allowance($c['user_id']);
        $sub = $a['sub'] ?? null;
        if (!$sub || $sub['status'] !== 'trial') return false;
        return Billing::recentOwnPosts($c['id']) < Billing::TRIAL_MIN_POSTS;
    }

    public static function start(array $c, $manual = false) {
        $why = self::blocker($c, $manual);
        if ($why) throw new RuntimeException($why);
        if (self::needsTrialCheck($c)) return ['kind' => 'trial_check', 'run' => Pipeline::beginTrialCheck($c['id'])];
        return ['kind' => 'full', 'run' => Pipeline::beginRun($c['id'])];
    }

    /** Called by the worker. Starts at most $max runs per tick so Apify load stays flat. */
    public static function tick($max = 3) {
        $started = [];
        $cs = all("SELECT c.* FROM customers c JOIN users u ON u.id=c.user_id
                   WHERE c.onboarded=1 AND c.ig_verified=1 AND c.auto_run=1 AND u.status='active'
                   AND c.status IN ('trial','paying') ORDER BY c.id");
        foreach ($cs as $c) {
            if (count($started) >= $max) break;
            if (self::blocker($c)) continue;
            try { $r = self::start($c); $started[] = '@' . $c['ig_handle'] . ' ' . $r['kind'] . ' #' . $r['run']; }
            catch (Throwable $e) { $started[] = '@' . $c['ig_handle'] . ' not started: ' . $e->getMessage(); }
        }
        return $started;
    }
}
