<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');

$bootstrapFile = sys_get_temp_dir() . '/phpqt_qthread_parity_interrupt_' . bin2hex(random_bytes(4)) . '.php';
$bootstrapCode = <<<'PHP'
<?php
function qt_worker_task_interruptible(string $label, int $ticks): array
{
    $ticks = max(1, $ticks);
    for ($i = 1; $i <= $ticks; $i++) {
        $message = \Qt\Core\QThread::receive(1);
        if (is_array($message) && ($message['event'] ?? '') === '__interrupt') {
            $result = ['label' => $label, 'status' => 'interrupted', 'tick' => $i];
            \Qt\Core\QThread::publish('result', $result);
            return $result;
        }

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
    'requestInterruption', 'isInterruptionRequested',
] as $method) {
    if (!method_exists($thread, $method)) {
        @unlink($bootstrapFile);
        qt_runtime_skip(sprintf('QThread::%s() is unavailable in this build.', $method));
    }
}

$ticks = 0;
$result = [];

$progressConn = $thread->on('progress', static function (array $event) use (&$ticks): void {
    $payload = $event['payload'] ?? null;
    if (!is_array($payload)) {
        return;
    }
    if ((string) ($payload['label'] ?? '') === 'interrupt') {
        $ticks = max($ticks, (int) ($payload['tick'] ?? 0));
    }
});

$resultConn = $thread->on('result', static function (array $event) use (&$result): void {
    $payload = $event['payload'] ?? null;
    if (is_array($payload) && (string) ($payload['label'] ?? '') === 'interrupt') {
        $result = $payload;
    }
});

$timedOut = true;
$interruptRequested = false;
$interruptionStateAfterRequest = false;

$thread->start('qt_worker_task_interruptible', ['interrupt', 1200], \Qt\Core\QThread::NormalPriority);
$deadline = microtime(true) + 6.0;
while (microtime(true) < $deadline) {
    $thread->drainEvents(128);

    if (!$interruptRequested && $ticks >= 15) {
        $thread->requestInterruption();
        $interruptRequested = true;
        $interruptionStateAfterRequest = $thread->isInterruptionRequested();
    }

    if ($result !== [] && !$thread->isRunning()) {
        $timedOut = false;
        break;
    }

    \Qt\Core\QThread::msleep(1);
}

$waitOk = $thread->wait(3000);
$thread->drainEvents(-1);
$finishedAfterWait = $thread->isFinished();
$runningAfterWait = $thread->isRunning();
$offProgress = $thread->off($progressConn);
$offResult = $thread->off($resultConn);

@unlink($bootstrapFile);

qt_runtime_result([
    'timed_out' => $timedOut,
    'wait_ok' => $waitOk,
    'finished_after_wait' => $finishedAfterWait,
    'running_after_wait' => $runningAfterWait,
    'interrupt_requested' => $interruptRequested,
    'interruption_state_after_request' => $interruptionStateAfterRequest,
    'status' => (string) ($result['status'] ?? ''),
    'off_progress' => $offProgress,
    'off_result' => $offResult,
]);
