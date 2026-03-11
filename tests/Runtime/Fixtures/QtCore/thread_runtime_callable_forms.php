<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();
$runtime->start();

$jobId = $runtime->submit(['DateTimeImmutable', 'createFromFormat'], ['U', '1700000000']);
$result = $runtime->await($jobId, 5000);

$closureRejected = false;
try {
    $runtime->submit(static fn (): int => 1, []);
} catch (\Throwable) {
    $closureRejected = true;
}

$stopped = $runtime->stop(2000);

qt_runtime_result([
    'array_callable_object' => is_object($result),
    'array_callable_class' => is_object($result) ? get_class($result) : '',
    'closure_rejected' => $closureRejected,
    'stopped' => $stopped,
]);
