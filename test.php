<?php

declare(strict_types=1);

use Qt\Core\QCoreApplication;
use Qt\Core\QThread;

if (!class_exists(QThread::class)) {
    fwrite(STDERR, "QThread not available. Build/load QtCore with ZTS support.\n");
    exit(1);
}

$workerBootstrapPath = __DIR__ . '/worker.php';
if (!is_file($workerBootstrapPath)) {
    fwrite(STDERR, "Missing worker bootstrap script: {$workerBootstrapPath}\n");
    exit(1);
}

$durations = [5, 5];
if (isset($argv[1], $argv[2])) {
    $durations = [max(1, (int) $argv[1]), max(1, (int) $argv[2])];
}

$app = new QCoreApplication();

$th1 = new QThread(bootstrapScript: $workerBootstrapPath);
$th2 = new QThread(bootstrapScript: $workerBootstrapPath);

$th1->on('progress', function (array $data) {
    $payload = $data['payload'] ?? [];
    echo "Progress of {$payload['name']}: {$payload['value']}% ({$payload['seconds']}s)\n";
});
$th2->on('progress', function (array $data) {
    $payload = $data['payload'] ?? [];
    echo "Progress of {$payload['name']}: {$payload['value']}% ({$payload['seconds']}s)\n";
});

$th1->on('result', function () use($th1){
    echo "Job 1 completed.\n";
    $th1->exit(0);
});

$th2->on('result', function () use ($th2) {
    echo "Job 2 completed.\n";
    $th2->exit(0);
});

$th1->start('qt_worker_blocking_sleep_job', ['Job 1', $durations[0]]);
$th2->start('qt_worker_blocking_sleep_job', ['Job 2', $durations[1]]);


QCoreApplication::exec();