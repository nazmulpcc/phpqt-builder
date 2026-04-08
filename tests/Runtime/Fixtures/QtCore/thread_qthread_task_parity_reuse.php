<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');

$bootstrapFile = sys_get_temp_dir() . '/phpqt_qthread_parity_reuse_' . bin2hex(random_bytes(4)) . '.php';
$bootstrapCode = <<<'PHP'
<?php
function qt_worker_task_simple(string $label, int $ticks): array
{
    $ticks = max(1, $ticks);
    for ($i = 1; $i <= $ticks; $i++) {
        \Qt\Core\QThread::publish('progress', ['label' => $label, 'tick' => $i]);
        \Qt\Core\QThread::msleep(5);
    }
    $result = ['label' => $label, 'status' => 'done', 'tick' => $ticks];
    \Qt\Core\QThread::publish('result', $result);
    return $result;
}
PHP;
file_put_contents($bootstrapFile, $bootstrapCode);

$thread = new \Qt\Core\QThread(null, $bootstrapFile);
foreach ([
    'start', 'on', 'off', 'drainEvents', 'wait', 'isRunning', 'isFinished',
    'onStarted', 'onFinished', 'setStackSize', 'stackSize', 'currentThread', 'currentThreadId',
] as $method) {
    if (!method_exists($thread, $method)) {
        @unlink($bootstrapFile);
        qt_runtime_skip(sprintf('QThread::%s() is unavailable in this build.', $method));
    }
}

$threadIdString = static function (mixed $raw): string {
    if (is_scalar($raw) || $raw === null) {
        return (string) $raw;
    }
    if (is_object($raw) && method_exists($raw, '__toString')) {
        return (string) $raw;
    }
    return '';
};

$ownerCurrentThreadObject = is_object(\Qt\Core\QThread::currentThread());
$ownerThreadIdType = get_debug_type(\Qt\Core\QThread::currentThreadId());
$ownerThreadIdString = $threadIdString(\Qt\Core\QThread::currentThreadId());

$stackBytes = 2 * 1024 * 1024;
$thread->setStackSize($stackBytes);
$stackApplied = ((int) $thread->stackSize()) === $stackBytes;

$startedHits = 0;
$finishedHits = 0;
$startedConn = $thread->onStarted(static function () use (&$startedHits): void {
    $startedHits++;
});
$finishedConn = $thread->onFinished(static function () use (&$finishedHits): void {
    $finishedHits++;
});

$results = [];
$resultConn = $thread->on('result', static function (array $event) use (&$results): void {
    $payload = $event['payload'] ?? null;
    if (is_array($payload)) {
        $label = (string) ($payload['label'] ?? '');
        if ($label !== '') {
            $results[$label] = $payload;
        }
    }
});

$run1RunningObserved = false;
$run1TimedOut = true;
$thread->start('qt_worker_task_simple', ['reuse-1', 100], \Qt\Core\QThread::HighPriority);
$deadline = microtime(true) + 6.0;
while (microtime(true) < $deadline) {
    $thread->drainEvents(128);
    if (!$run1RunningObserved && $thread->isRunning()) {
        $run1RunningObserved = true;
    }
    if (isset($results['reuse-1']) && !$thread->isRunning()) {
        $run1TimedOut = false;
        break;
    }
    \Qt\Core\QThread::msleep(1);
}
$run1WaitOk = $thread->wait(3000);
$thread->drainEvents(-1);
$run1FinishedAfterWait = $thread->isFinished();
$run1RunningAfterWait = $thread->isRunning();

$run2RunningObserved = false;
$run2TimedOut = true;
$thread->start('qt_worker_task_simple', ['reuse-2', 60], \Qt\Core\QThread::NormalPriority);
$deadline = microtime(true) + 6.0;
while (microtime(true) < $deadline) {
    $thread->drainEvents(128);
    if (!$run2RunningObserved && $thread->isRunning()) {
        $run2RunningObserved = true;
    }
    if (isset($results['reuse-2']) && !$thread->isRunning()) {
        $run2TimedOut = false;
        break;
    }
    \Qt\Core\QThread::msleep(1);
}
$run2WaitOk = $thread->wait(3000);
$thread->drainEvents(-1);
$run2FinishedAfterWait = $thread->isFinished();
$run2RunningAfterWait = $thread->isRunning();

$disconnectStarted = \Qt\Core\QObject::disconnect($startedConn);
$disconnectFinished = \Qt\Core\QObject::disconnect($finishedConn);
$offResult = $thread->off($resultConn);

@unlink($bootstrapFile);

qt_runtime_result([
    'owner_current_thread_object' => $ownerCurrentThreadObject,
    'owner_thread_id_type' => $ownerThreadIdType,
    'owner_thread_id_string_non_empty' => $ownerThreadIdString !== '',
    'stack_applied' => $stackApplied,
    'started_hits' => $startedHits,
    'finished_hits' => $finishedHits,
    'run1_running_observed' => $run1RunningObserved,
    'run1_timed_out' => $run1TimedOut,
    'run1_wait_ok' => $run1WaitOk,
    'run1_finished_after_wait' => $run1FinishedAfterWait,
    'run1_running_after_wait' => $run1RunningAfterWait,
    'run1_status' => (string) (($results['reuse-1']['status'] ?? '')),
    'run2_running_observed' => $run2RunningObserved,
    'run2_timed_out' => $run2TimedOut,
    'run2_wait_ok' => $run2WaitOk,
    'run2_finished_after_wait' => $run2FinishedAfterWait,
    'run2_running_after_wait' => $run2RunningAfterWait,
    'run2_status' => (string) (($results['reuse-2']['status'] ?? '')),
    'disconnect_started' => $disconnectStarted,
    'disconnect_finished' => $disconnectFinished,
    'off_result' => $offResult,
]);
