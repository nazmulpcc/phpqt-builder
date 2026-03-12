<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');

$bootstrapFile = sys_get_temp_dir() . '/phpqt_qthread_parity_quit_exit_' . bin2hex(random_bytes(4)) . '.php';
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
    'start', 'on', 'off', 'drainEvents', 'wait', 'isRunning', 'isFinished', 'quit', 'exit',
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
    if ((string) ($payload['label'] ?? '') === 'quit-exit') {
        $ticks = max($ticks, (int) ($payload['tick'] ?? 0));
    }
});
$resultConn = $thread->on('result', static function (array $event) use (&$result): void {
    $payload = $event['payload'] ?? null;
    if (is_array($payload) && (string) ($payload['label'] ?? '') === 'quit-exit') {
        $result = $payload;
    }
});

$timedOut = true;
$quitExitCalled = false;

$thread->start('qt_worker_task_simple', ['quit-exit', 200], \Qt\Core\QThread::NormalPriority);
$deadline = microtime(true) + 6.0;
while (microtime(true) < $deadline) {
    $thread->drainEvents(128);

    if (!$quitExitCalled && $ticks >= 10 && $thread->isRunning()) {
        $thread->quit();
        $thread->exit(0);
        $quitExitCalled = true;
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
    'quit_exit_called' => $quitExitCalled,
    'status' => (string) ($result['status'] ?? ''),
    'off_progress' => $offProgress,
    'off_result' => $offResult,
]);
