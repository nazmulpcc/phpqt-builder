<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();
$runtime->start();

$jobIds = [];
$submitErrors = 0;

for ($i = 0; $i < 20; $i++) {
    try {
        $jobIds[] = $runtime->submit('usleep', [200000]);
    } catch (\Throwable) {
        $submitErrors++;
    }
}

$stopOk = $runtime->stop(1);

$awaitCanceled = 0;
$awaitTimeout = 0;
$awaitErrors = 0;

foreach ($jobIds as $jobId) {
    try {
        $value = $runtime->await((int) $jobId, 30);
        if ($value === false) {
            $awaitCanceled++;
        } elseif ($value === null) {
            $awaitTimeout++;
        }
    } catch (\Throwable) {
        $awaitErrors++;
    }
}

$stats = $runtime->stats();

qt_runtime_result([
    'stop_ok' => $stopOk,
    'submitted' => count($jobIds),
    'submit_errors' => $submitErrors,
    'await_canceled' => $awaitCanceled,
    'await_timeout' => $awaitTimeout,
    'await_errors' => $awaitErrors,
    'stats_canceled' => (int) ($stats['canceled'] ?? 0),
    'stats_worker_crash' => (int) ($stats['worker_crash'] ?? 0),
    'stats_running' => (bool) ($stats['running'] ?? true),
]);
