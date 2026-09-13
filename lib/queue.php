<?php
require_once __DIR__ . '/db.php';

/**
 * Job queue. Claiming is a single conditional UPDATE, so two cron ticks
 * overlapping can never take the same job.
 */
class Queue {
    const MAX_ATTEMPTS = 4;
    const STALE_MINUTES = 15;      // a 'working' job older than this crashed mid-step

    public static function push($customerId, $step, array $payload = [], $runId = null, $delaySecs = 0) {
        q("INSERT INTO jobs (customer_id, run_id, step, payload, run_after)
           VALUES (?,?,?,?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))",
          [$customerId, $runId, $step, json_encode($payload, JSON_UNESCAPED_UNICODE), (int)$delaySecs]);
        return lastId();
    }

    /** Take one due job, or null. Also revives jobs orphaned by a crashed tick. */
    public static function claim() {
        q("UPDATE jobs SET state='pending', locked_at=NULL
           WHERE state='working' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)",
          [self::STALE_MINUTES]);

        $row = one("SELECT * FROM jobs
                    WHERE state='pending' AND run_after <= UTC_TIMESTAMP()
                    ORDER BY id ASC LIMIT 1");
        if (!$row) return null;

        $n = q("UPDATE jobs SET state='working', locked_at=UTC_TIMESTAMP(), attempts=attempts+1
                WHERE id=? AND state='pending'", [$row['id']])->rowCount();
        if ($n === 0) return null;                      // another tick beat us to it

        $row['attempts'] = (int)$row['attempts'] + 1;
        $row['payload']  = json_decode((string)$row['payload'], true) ?: [];
        return $row;
    }

    public static function done($id) { q("UPDATE jobs SET state='done', locked_at=NULL WHERE id=?", [$id]); }

    /** Retry with backoff while attempts remain, then fail the job and its run. */
    public static function fail($job, $message, $retryable = true) {
        $msg = substr((string)$message, 0, 900);
        if ($retryable && $job['attempts'] < self::MAX_ATTEMPTS) {
            $delay = [0, 60, 300, 900][min((int)$job['attempts'], 3)];
            q("UPDATE jobs SET state='pending', locked_at=NULL, last_error=?,
                      run_after=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id=?",
              [$msg, $delay, $job['id']]);
            return 'retry in ' . $delay . 's';
        }
        q("UPDATE jobs SET state='failed', locked_at=NULL, last_error=? WHERE id=?", [$msg, $job['id']]);
        if (!empty($job['run_id'])) {
            q("UPDATE runs SET status='failed', finished_at=UTC_TIMESTAMP(), error=? WHERE id=?",
              [$msg, $job['run_id']]);
        }
        return 'failed permanently';
    }

    public static function stats() {
        return one("SELECT
            SUM(state='pending') pending, SUM(state='working') working,
            SUM(state='done') done, SUM(state='failed') failed FROM jobs") ?: [];
    }
}
