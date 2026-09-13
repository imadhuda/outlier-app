<?php
/* Cron entry point — command line only; the web server never serves this file.
   Each tick: schedule anyone who is due, then work the queue one small step at
   a time, always finishing well inside the execution-time limit. */
if (php_sapi_name() !== 'cli' && !defined('OUTLIER_ADMIN_TICK')) { http_response_code(404); exit; }
require_once __DIR__ . '/lib/pipeline.php';
require_once __DIR__ . '/lib/scheduler.php';

/* Cron mails anything this prints. A misconfigured install must fail quietly
   once, not fatal-error every five minutes for the rest of the month. */
try { outlier_config(); db(); }
catch (Throwable $e) {
    $m = gmdate('c') . '  NOT CONFIGURED: ' . $e->getMessage() . "\n";
    @file_put_contents(__DIR__ . '/worker.log', $m, FILE_APPEND | LOCK_EX);
    if (php_sapi_name() === 'cli') { fwrite(STDERR, $m); }
    exit(0);
}

$started = microtime(true);
$budget  = 80;                       // seconds — stay clear of the 120s ceiling
$done    = 0; $log = [];

try { foreach (Scheduler::tick() as $s) $log[] = 'scheduled ' . $s; }
catch (Throwable $e) { $log[] = 'scheduler ERROR: ' . $e->getMessage(); }

while ((microtime(true) - $started) < $budget) {
    $job = Queue::claim();
    if (!$job) { if (!$log) $log[] = 'idle'; break; }
    try {
        $msg = Pipeline::handle($job);
        Queue::done($job['id']);
        $log[] = "#{$job['id']} {$job['step']}: $msg";
    } catch (HttpError $e) {
        $log[] = "#{$job['id']} {$job['step']} HTTP {$e->status}: " . Queue::fail($job, $e->getMessage(), $e->retryable);
    } catch (Throwable $e) {
        $log[] = "#{$job['id']} {$job['step']} ERROR: " . Queue::fail($job, $e->getMessage(), false);
    }
    $done++;
    if ($done >= 5) break;           // leave room for the next tick
}

$out = gmdate('c') . '  ' . implode(' | ', $log) . "\n";
@file_put_contents(__DIR__ . '/worker.log', $out, FILE_APPEND | LOCK_EX);
if (php_sapi_name() === 'cli') echo $out;
