<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();
$runtime->start();

$jobId = $runtime->submit(['DateTimeImmutable', 'createFromFormat'], ['U', '1700000000']);
$result = $runtime->await($jobId, 5000);

$nonStaticRejected = false;
try {
    $runtime->submit(['DateTimeImmutable', 'format'], ['Y']);
} catch (\Throwable) {
    $nonStaticRejected = true;
}

$unknownClassRejected = false;
$unknownClassErroredOnAwait = false;
try {
    $unknownJobId = $runtime->submit(['Qt\\Core\\DefinitelyMissingRuntimeClass', 'run'], []);
    try {
        $runtime->await($unknownJobId, 5000);
    } catch (\Throwable) {
        $unknownClassErroredOnAwait = true;
    }
} catch (\Throwable) {
    $unknownClassRejected = true;
}

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
    'non_static_rejected' => $nonStaticRejected,
    'unknown_class_rejected' => $unknownClassRejected,
    'unknown_class_errored_on_await' => $unknownClassErroredOnAwait,
    'closure_rejected' => $closureRejected,
    'stopped' => $stopped,
]);
