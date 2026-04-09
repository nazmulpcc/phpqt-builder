<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_php_signal_stress_classes.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\Attributes\Signal::class, 'Qt signal attributes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QPhpSignalConnection::class, 'Qt PHP signal connection class is unavailable in this build.');

foreach (['on', 'emit', 'moveToThread'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

$iterations = qt_runtime_php_signal_env_int('PHPQT_PHP_SIGNAL_RACE_ITERATIONS', 32);

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = (string) \Qt\Core\QThread::currentThreadId();

$timeoutIterations = 0;
$failedIterations = 0;
$totalHits = 0;
$prestartIterations = 0;
$poststartIterations = 0;
$allMoveOk = true;
$allConnectionsAreObjects = true;

for ($i = 1; $i <= $iterations; $i++) {
    $thread = new \Qt\Core\QThread();
    $sender = new RuntimePhpSignalStressEmitter();
    $receiver = new RuntimePhpSignalStressReceiver();
    $receiver->setProperty('expected_hits', 1);
    $message = 'race-' . $i;

    $connection = $sender->on('dataChanged', $receiver, 'capture');
    $moveOk = $receiver->moveToThread($thread);
    $allMoveOk = $allMoveOk && $moveOk;
    $allConnectionsAreObjects = $allConnectionsAreObjects && $connection instanceof \Qt\Core\QPhpSignalConnection;

    if (($i % 2) === 0) {
        $poststartIterations++;
        $thread->start();
        $sender->fire($message);
    } else {
        $prestartIterations++;
        $sender->fire($message);
        $thread->start();
    }

    $waitOk = $thread->wait(3000);
    if (!$waitOk) {
        $timeoutIterations++;
        if ($thread->isRunning()) {
            $thread->quit();
            $thread->wait(1000);
        }
    }

    $hits = (int) $receiver->property('hits');
    $totalHits += $hits;
    $receiverThreadId = (string) $receiver->property('receiver_thread_id');
    $messagesCsv = (string) $receiver->property('messages_csv');

    if ($hits !== 1
        || $messagesCsv !== $message
        || $receiverThreadId === ''
        || $receiverThreadId === $mainThreadId
        || (int) $receiver->property('thread_mismatch_hits') !== 0
        || (int) $receiver->property('sender_null_hits') !== 0
        || (int) $receiver->property('sender_class_mismatch_hits') !== 0
        || (int) $receiver->property('sender_signal_mismatch_hits') !== 0) {
        $failedIterations++;
    }
}

qt_runtime_result([
    'iterations' => $iterations,
    'prestart_iterations' => $prestartIterations,
    'poststart_iterations' => $poststartIterations,
    'timeout_iterations' => $timeoutIterations,
    'failed_iterations' => $failedIterations,
    'total_hits' => $totalHits,
    'all_move_ok' => $allMoveOk,
    'all_connections_are_objects' => $allConnectionsAreObjects,
]);
