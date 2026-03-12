<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');

$thread = new \Qt\Core\QThread();

foreach (['onStarted', 'onFinished', 'start', 'quit', 'isRunning', 'isFinished', 'wait'] as $method) {
    if (!method_exists($thread, $method)) {
        qt_runtime_skip(sprintf('QThread::%s() is unavailable in this build.', $method));
    }
}

$startedHits = 0;
$finishedHits = 0;

$startedConnection = $thread->onStarted(static function () use (&$startedHits, $thread): void {
    $startedHits++;
    $thread->quit();
});

$finishedConnection = $thread->onFinished(static function () use (&$finishedHits): void {
    $finishedHits++;
});

$thread->start();

$timedOut = true;
for ($i = 0; $i < 500; $i++) {
    // Method calls create owner-thread safe points that drain queued callbacks.
    $running = $thread->isRunning();
    $finished = $thread->isFinished();

    if ($running && $i === 100) {
        $thread->quit();
    }

    if ($finished && $finishedHits > 0) {
        $timedOut = false;
        break;
    }

    \Qt\Core\QThread::msleep(5);
}

$waitOk = $thread->wait(3000);

qt_runtime_result([
    'started_hits' => $startedHits,
    'finished_hits' => $finishedHits,
    'timed_out' => $timedOut,
    'wait_ok' => $waitOk,
    'connections_are_objects' => is_object($startedConnection) && is_object($finishedConnection),
]);
