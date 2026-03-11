<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();
$runtime->start();

$jobId = $runtime->submit('sleep', [1]);
$first = $runtime->await($jobId, 5);
$second = $runtime->await($jobId, 5000);
$stats = $runtime->stats();
$stopped = $runtime->stop(2000);

qt_runtime_result([
    'first_is_null' => $first === null,
    'second_is_zero' => $second === 0,
    'timeouts' => (int) ($stats['timeouts'] ?? 0),
    'stopped' => $stopped,
]);
