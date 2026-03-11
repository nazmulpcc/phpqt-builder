<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtimeA = new \Qt\Core\QThreadRuntime();
$runtimeB = new \Qt\Core\QThreadRuntime();

$runtimeA->start();
$runtimeB->start();

$startedAt = hrtime(true);
$jobA = $runtimeA->submit('usleep', [300000]);
$jobB = $runtimeB->submit('usleep', [300000]);

$runtimeA->await($jobA, 5000);
$runtimeB->await($jobB, 5000);
$elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

$errorPropagated = false;
try {
    $jobErr = $runtimeA->submit('definitely_not_a_real_function_name', []);
    $runtimeA->await($jobErr, 5000);
} catch (\Throwable) {
    $errorPropagated = true;
}

$statsA = $runtimeA->stats();
$statsB = $runtimeB->stats();
$stopA = $runtimeA->stop(2000);
$stopB = $runtimeB->stop(2000);

qt_runtime_result([
    'elapsed_ms' => $elapsedMs,
    'parallel_window_ok' => $elapsedMs < 550.0,
    'error_propagated' => $errorPropagated,
    'runtime_a_enqueued' => (int) ($statsA['enqueued'] ?? 0),
    'runtime_b_enqueued' => (int) ($statsB['enqueued'] ?? 0),
    'stop_a' => $stopA,
    'stop_b' => $stopB,
]);
