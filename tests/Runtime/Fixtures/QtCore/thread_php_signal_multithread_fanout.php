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

$threadCount = qt_runtime_php_signal_env_int('PHPQT_PHP_SIGNAL_FANOUT_THREADS', 4);
$messageCount = qt_runtime_php_signal_env_int('PHPQT_PHP_SIGNAL_FANOUT_MESSAGES', 12);

$messages = ['fanout-0'];
for ($i = 1; $i <= $messageCount; $i++) {
    $messages[] = 'fanout-' . $i;
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$mainThreadId = (string) \Qt\Core\QThread::currentThreadId();
$sender = new RuntimePhpSignalStressEmitter();

$threads = [];
$receivers = [];
$allMoveOk = true;
$allConnectionsAreObjects = true;
$startedThreads = 0;
$finishedThreads = 0;
$timedOut = false;

$timeout = new class () extends \Qt\Core\QObject {
    public bool $timedOut = false;
    private int $timerId = 0;

    public function arm(int $timeoutMs): void
    {
        $this->timerId = $this->startTimer($timeoutMs);
    }

    public function disarm(): void
    {
        if ($this->timerId > 0) {
            $this->killTimer($this->timerId);
            $this->timerId = 0;
        }
    }

    protected function timerEvent(\Qt\Core\QTimerEvent $event): void
    {
        if ($event->timerId() !== $this->timerId) {
            return;
        }

        $this->timedOut = true;
        $this->disarm();
        \Qt\Core\QCoreApplication::quit();
    }
};
$timeout->arm(10000);

for ($i = 0; $i < $threadCount; $i++) {
    $thread = new \Qt\Core\QThread();
    $receiver = new RuntimePhpSignalStressReceiver();
    $receiver->setProperty('expected_hits', count($messages));

    $connection = $sender->on('dataChanged', $receiver, 'capture');
    $moveOk = $receiver->moveToThread($thread);

    $allMoveOk = $allMoveOk && $moveOk;
    $allConnectionsAreObjects = $allConnectionsAreObjects && $connection instanceof \Qt\Core\QPhpSignalConnection;

    $threads[] = $thread;
    $receivers[] = $receiver;

    $thread->onStarted(static function () use (&$startedThreads, $threadCount, $sender, $messages): void {
        $startedThreads++;
        if ($startedThreads !== $threadCount) {
            return;
        }

        foreach ($messages as $message) {
            $sender->fire($message);
        }
    });

    $thread->onFinished(static function () use (&$finishedThreads, $threadCount, $timeout): void {
        $finishedThreads++;
        if ($finishedThreads < $threadCount) {
            return;
        }

        $timeout->disarm();
        \Qt\Core\QCoreApplication::quit();
    });
}

foreach ($threads as $thread) {
    $thread->start();
}

\Qt\Core\QCoreApplication::exec();
$timedOut = $timeout->timedOut;

$waitAllOk = true;
$badReceivers = 0;
foreach ($threads as $index => $thread) {
    $waitOk = $thread->wait(5000);
    if (!$waitOk) {
        $waitAllOk = false;
        if ($thread->isRunning()) {
            $thread->quit();
            $thread->wait(1000);
        }
    }

    $receiver = $receivers[$index];
    $receiverThreadId = (string) $receiver->property('receiver_thread_id');
    if ((int) $receiver->property('hits') !== count($messages)
        || (string) $receiver->property('messages_csv') !== implode(',', $messages)
        || $receiverThreadId === ''
        || $receiverThreadId === $mainThreadId
        || (int) $receiver->property('thread_mismatch_hits') !== 0
        || (int) $receiver->property('sender_null_hits') !== 0
        || (int) $receiver->property('sender_class_mismatch_hits') !== 0
        || (int) $receiver->property('sender_signal_mismatch_hits') !== 0) {
        $badReceivers++;
    }
}

$totalHits = 0;
foreach ($receivers as $receiver) {
    $totalHits += (int) $receiver->property('hits');
}

qt_runtime_result([
    'thread_count' => $threadCount,
    'message_count' => count($messages),
    'expected_total_hits' => $threadCount * count($messages),
    'total_hits' => $totalHits,
    'bad_receivers' => $badReceivers,
    'timed_out' => $timedOut,
    'started_threads' => $startedThreads,
    'finished_threads' => $finishedThreads,
    'wait_all_ok' => $waitAllOk,
    'all_move_ok' => $allMoveOk,
    'all_connections_are_objects' => $allConnectionsAreObjects,
]);
