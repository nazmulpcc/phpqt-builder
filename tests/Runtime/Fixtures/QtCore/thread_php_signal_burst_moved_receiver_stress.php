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

$messageCount = qt_runtime_php_signal_env_int('PHPQT_PHP_SIGNAL_BURST_COUNT', 48);
$messages = [];
for ($i = 1; $i <= $messageCount; $i++) {
    $messages[] = 'burst-' . $i;
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = (string) \Qt\Core\QThread::currentThreadId();
$thread = new \Qt\Core\QThread();
$sender = new RuntimePhpSignalStressEmitter();
$receiver = new RuntimePhpSignalStressReceiver();
$receiver->setProperty('expected_hits', $messageCount);

$connection = $sender->on('dataChanged', $receiver, 'capture');
$moveOk = $receiver->moveToThread($thread);

$thread->start();
foreach ($messages as $message) {
    $sender->fire($message);
}

$waitOk = $thread->wait(5000);
if (!$waitOk && $thread->isRunning()) {
    $thread->quit();
    $thread->wait(1000);
}

$messagesCsv = (string) $receiver->property('messages_csv');
$receiverThreadId = (string) $receiver->property('receiver_thread_id');

qt_runtime_result([
    'move_ok' => $moveOk,
    'wait_ok' => $waitOk,
    'timed_out' => !$waitOk,
    'message_count' => $messageCount,
    'hits' => (int) $receiver->property('hits'),
    'messages_csv' => $messagesCsv,
    'expected_csv' => implode(',', $messages),
    'main_thread_id' => $mainThreadId,
    'receiver_thread_id' => $receiverThreadId,
    'callback_on_worker_thread' => $receiverThreadId !== '' && $receiverThreadId !== $mainThreadId,
    'thread_mismatch_hits' => (int) $receiver->property('thread_mismatch_hits'),
    'sender_null_hits' => (int) $receiver->property('sender_null_hits'),
    'sender_class' => (string) $receiver->property('sender_class'),
    'sender_class_mismatch_hits' => (int) $receiver->property('sender_class_mismatch_hits'),
    'sender_signal' => (int) $receiver->property('sender_signal'),
    'sender_signal_mismatch_hits' => (int) $receiver->property('sender_signal_mismatch_hits'),
    'connection_is_object' => $connection instanceof \Qt\Core\QPhpSignalConnection,
]);
