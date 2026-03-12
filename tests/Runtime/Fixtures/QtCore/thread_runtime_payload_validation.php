<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();
$runtime->start();

$resourceRejected = false;
$closureRejected = false;

$stream = fopen(__FILE__, 'rb');
try {
    $runtime->submit('strlen', [$stream]);
} catch (\Throwable) {
    $resourceRejected = true;
}
if (is_resource($stream)) {
    fclose($stream);
}

try {
    $runtime->submit('count', [[static fn (): int => 1]]);
} catch (\Throwable) {
    $closureRejected = true;
}

$jobId = $runtime->submit('strlen', ['qt-worker']);
$value = $runtime->await($jobId, 5000);
$stopped = $runtime->stop(2000);

qt_runtime_result([
    'resource_rejected' => $resourceRejected,
    'closure_rejected' => $closureRejected,
    'normal_value' => $value,
    'stopped' => $stopped,
]);
