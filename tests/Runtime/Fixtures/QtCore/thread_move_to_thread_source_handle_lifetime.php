<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_move_to_thread_phase2_worker_class.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QEvent::class, 'QtCore event classes are unavailable in this build.');

foreach (['moveToThread', 'onObjectNameChanged', 'thread', 'property'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

if (!method_exists(\Qt\Core\QCoreApplication::class, 'postEvent')) {
    qt_runtime_skip(\Qt\Core\QCoreApplication::class . '::postEvent() is unavailable in this build.');
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);

$finishedThreads = 0;
$quitWhenFinished = static function () use (&$finishedThreads): void {
    $finishedThreads++;
    if ($finishedThreads === 2) {
        \Qt\Core\QCoreApplication::quit();
    }
};

$threadAlive = new \Qt\Core\QThread();
$workerAlive = new RuntimeMoveToThreadPhase2Worker();
$aliveSignalHits = 0;
$workerAlive->onObjectNameChanged(static function () use (&$aliveSignalHits): void {
    $aliveSignalHits++;
});
$moveAliveOk = $workerAlive->moveToThread($threadAlive);
\Qt\Core\QCoreApplication::postEvent($workerAlive, new \Qt\Core\QEvent(1001));
unset($workerAlive);
gc_collect_cycles();
$threadAlive->onFinished($quitWhenFinished);

$threadDead = new \Qt\Core\QThread();
$workerDead = new RuntimeMoveToThreadPhase2Worker();
$moveDeadOk = $workerDead->moveToThread($threadDead);
\Qt\Core\QCoreApplication::postEvent($workerDead, new \Qt\Core\QEvent(1002));
$threadDead->onFinished($quitWhenFinished);

$threadAlive->start();
$threadDead->start();
\Qt\Core\QCoreApplication::exec();

$waitAliveOk = $threadAlive->wait(3000);
$waitDeadOk = $threadDead->wait(3000);

$probe = static function (callable $callback): array {
    try {
        $callback();

        return [
            'threw' => false,
            'message' => null,
        ];
    } catch (\Throwable $e) {
        return [
            'threw' => true,
            'message' => $e->getMessage(),
        ];
    }
};

$threadProbe = $probe(static function () use ($workerDead): void {
    $workerDead->thread();
});
$propertyProbe = $probe(static function () use ($workerDead): void {
    $workerDead->property('data');
});

qt_runtime_result([
    'move_alive_ok' => $moveAliveOk,
    'move_dead_ok' => $moveDeadOk,
    'alive_signal_hits' => $aliveSignalHits,
    'wait_alive_ok' => $waitAliveOk,
    'wait_dead_ok' => $waitDeadOk,
    'thread_probe' => $threadProbe,
    'property_probe' => $propertyProbe,
]);
