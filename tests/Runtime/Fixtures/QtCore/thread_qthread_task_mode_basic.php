<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');

$bootstrapFile = sys_get_temp_dir() . '/phpqt_qthread_task_basic_' . bin2hex(random_bytes(4)) . '.php';
$bootstrapCode = <<<'PHP'
<?php
function qt_worker_emit_progress(int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        \Qt\Core\QThread::publish('progress', ['i' => $i]);
        \Qt\Core\QThread::msleep(5);
    }
}
PHP;
file_put_contents($bootstrapFile, $bootstrapCode);

$thread = new \Qt\Core\QThread(null, $bootstrapFile);

foreach (['start', 'on', 'off', 'drainEvents', 'isRunning', 'isFinished', 'wait'] as $method) {
    if (!method_exists($thread, $method)) {
        @unlink($bootstrapFile);
        qt_runtime_skip(sprintf('QThread::%s() is unavailable in this build.', $method));
    }
}

$progressHits = 0;
$listenerId = $thread->on('progress', static function (array $event) use (&$progressHits): void {
    $progressHits++;
});

$thread->start('qt_worker_emit_progress', [5]);

$firstTimedOut = true;
for ($i = 0; $i < 1200; $i++) {
    $thread->drainEvents(-1);
    if ($thread->isFinished()) {
        $firstTimedOut = false;
        break;
    }
    \Qt\Core\QThread::msleep(2);
}

$firstWaitOk = $thread->wait(2000);
$afterFirst = $progressHits;

$thread->start('qt_worker_emit_progress', [3], \Qt\Core\QThread::NormalPriority);

$secondTimedOut = true;
for ($i = 0; $i < 1200; $i++) {
    $thread->drainEvents(-1);
    if ($thread->isFinished()) {
        $secondTimedOut = false;
        break;
    }
    \Qt\Core\QThread::msleep(2);
}

$secondWaitOk = $thread->wait(2000);
$thread->drainEvents(-1);
$listenerRemoved = $thread->off($listenerId);

@unlink($bootstrapFile);

qt_runtime_result([
    'first_timed_out' => $firstTimedOut,
    'second_timed_out' => $secondTimedOut,
    'first_wait_ok' => $firstWaitOk,
    'second_wait_ok' => $secondWaitOk,
    'after_first' => $afterFirst,
    'after_second' => $progressHits,
    'listener_removed' => $listenerRemoved,
    'is_finished' => $thread->isFinished(),
    'is_running' => $thread->isRunning(),
]);

