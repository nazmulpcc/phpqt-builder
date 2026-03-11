<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();
$runtime->start();

$accepted = 0;
$rejected = 0;
$jobIds = [];

for ($i = 0; $i < 24; $i++) {
    try {
        $jobIds[] = $runtime->submit('usleep', [250000]);
        $accepted++;
    } catch (\Throwable) {
        $rejected++;
    }
}

$statsAfterSubmit = $runtime->stats();

foreach ($jobIds as $jobId) {
    try {
        $runtime->await((int) $jobId, 5000);
    } catch (\Throwable) {
        // ignore in fixture: this scenario validates queue behavior and counters
    }
}

$stopped = $runtime->stop(2000);
$statsAfterStop = $runtime->stats();

qt_runtime_result([
    'accepted' => $accepted,
    'rejected' => $rejected,
    'queue_max_depth' => (int) ($statsAfterSubmit['queue_max_depth'] ?? 0),
    'rejected_full' => (int) ($statsAfterSubmit['rejected_full'] ?? 0),
    'rejected_stopping_after_stop' => (int) ($statsAfterStop['rejected_stopping'] ?? 0),
    'stopped' => $stopped,
]);
