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

$phases = qt_runtime_php_signal_env_int('PHPQT_PHP_SIGNAL_CHURN_PHASES', 12);

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = (string) \Qt\Core\QThread::currentThreadId();

$totalHits = 0;
$phaseFailures = 0;
$timeoutPhases = 0;
$allMoveOk = true;
$allConnectionsAreObjects = true;

for ($phase = 1; $phase <= $phases; $phase++) {
    $thread = new \Qt\Core\QThread();
    $sender = new RuntimePhpSignalStressEmitter();
    $receiver = new RuntimePhpSignalStressReceiver();
    $receiver->setProperty('expected_hits', 2);
    $message = 'phase-' . $phase;

    $beforeConnection = $sender->on('dataChanged', $receiver, 'capture');
    $moveOk = $receiver->moveToThread($thread);
    $afterConnection = $sender->on('dataChanged', $receiver, 'capture');

    $allMoveOk = $allMoveOk && $moveOk;
    $allConnectionsAreObjects = $allConnectionsAreObjects
        && $beforeConnection instanceof \Qt\Core\QPhpSignalConnection
        && $afterConnection instanceof \Qt\Core\QPhpSignalConnection;

    $thread->start();
    $sender->fire($message);

    $waitOk = $thread->wait(3000);
    if (!$waitOk) {
        $timeoutPhases++;
        if ($thread->isRunning()) {
            $thread->quit();
            $thread->wait(1000);
        }
    }

    $hits = (int) $receiver->property('hits');
    $totalHits += $hits;
    $receiverThreadId = (string) $receiver->property('receiver_thread_id');
    $messagesCsv = (string) $receiver->property('messages_csv');

    if ($hits !== 2
        || $messagesCsv !== $message . ',' . $message
        || $receiverThreadId === ''
        || $receiverThreadId === $mainThreadId
        || (int) $receiver->property('thread_mismatch_hits') !== 0
        || (int) $receiver->property('sender_null_hits') !== 0
        || (int) $receiver->property('sender_class_mismatch_hits') !== 0
        || (int) $receiver->property('sender_signal_mismatch_hits') !== 0) {
        $phaseFailures++;
    }
}

qt_runtime_result([
    'phases' => $phases,
    'expected_total_hits' => $phases * 2,
    'total_hits' => $totalHits,
    'phase_failures' => $phaseFailures,
    'timeout_phases' => $timeoutPhases,
    'all_move_ok' => $allMoveOk,
    'all_connections_are_objects' => $allConnectionsAreObjects,
]);
