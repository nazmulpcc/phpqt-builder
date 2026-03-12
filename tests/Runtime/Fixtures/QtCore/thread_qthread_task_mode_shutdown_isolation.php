<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');

$bootstrapFile = sys_get_temp_dir() . '/phpqt_qthread_task_shutdown_isolation_' . bin2hex(random_bytes(4)) . '.php';
$bootstrapCode = <<<'PHP'
<?php
function qt_worker_emit_progress_seconds(string $name, int $seconds): array
{
    $seconds = max(1, $seconds);
    $ticks = $seconds * 10;

    \Qt\Core\QThread::publish('progress', [
        'name' => $name,
        'value' => 0,
        'seconds' => $seconds,
    ]);

    for ($i = 1; $i <= $ticks; $i++) {
        \Qt\Core\QThread::msleep(100);
        \Qt\Core\QThread::publish('progress', [
            'name' => $name,
            'value' => (int) floor(($i * 100) / $ticks),
            'seconds' => $seconds,
        ]);
    }

    $result = [
        'name' => $name,
        'status' => 'done',
        'seconds' => $seconds,
    ];
    \Qt\Core\QThread::publish('result', $result);

    return $result;
}
PHP;
file_put_contents($bootstrapFile, $bootstrapCode);

$threadA = new \Qt\Core\QThread(null, $bootstrapFile);
$threadB = new \Qt\Core\QThread(null, $bootstrapFile);

foreach ([
    'start',
    'on',
    'off',
    'drainEvents',
    'isRunning',
    'isFinished',
    'wait',
] as $method) {
    if (!method_exists($threadA, $method)) {
        @unlink($bootstrapFile);
        qt_runtime_skip(sprintf('QThread::%s() is unavailable in this build.', $method));
    }
}

$aDone = false;
$bDone = false;
$bResultAfterADone = false;
$bProgressTotal = 0;
$bProgressAfterADone = 0;

$aProgressListener = $threadA->on('progress', static function (): void {
});
$bProgressListener = $threadB->on('progress', static function (array $event) use (&$aDone, &$bProgressTotal, &$bProgressAfterADone): void {
    $payload = $event['payload'] ?? [];
    if (!is_array($payload)) {
        return;
    }

    $bProgressTotal++;
    if ($aDone) {
        $bProgressAfterADone++;
    }
});

$aResultListener = $threadA->on('result', static function (array $event) use (&$aDone): void {
    $payload = $event['payload'] ?? null;
    if (is_array($payload)) {
        $aDone = true;
    }
});
$bResultListener = $threadB->on('result', static function (array $event) use (&$aDone, &$bDone, &$bResultAfterADone): void {
    $payload = $event['payload'] ?? null;
    if (!is_array($payload)) {
        return;
    }

    $bDone = true;
    if ($aDone) {
        $bResultAfterADone = true;
    }
});

$threadA->start('qt_worker_emit_progress_seconds', ['A', 2]);
$threadB->start('qt_worker_emit_progress_seconds', ['B', 4]);

$deadline = microtime(true) + 15.0;
$timedOut = true;
while (microtime(true) < $deadline) {
    $threadA->drainEvents(128);
    $threadB->drainEvents(128);

    if ($aDone && $bDone) {
        $timedOut = false;
        break;
    }

    \Qt\Core\QThread::msleep(2);
}

$waitA = $threadA->wait(3000);
$waitB = $threadB->wait(3000);
$threadA->drainEvents(-1);
$threadB->drainEvents(-1);

$offAProgress = $threadA->off($aProgressListener);
$offBProgress = $threadB->off($bProgressListener);
$offAResult = $threadA->off($aResultListener);
$offBResult = $threadB->off($bResultListener);

@unlink($bootstrapFile);

qt_runtime_result([
    'timed_out' => $timedOut,
    'a_done' => $aDone,
    'b_done' => $bDone,
    'b_result_after_a_done' => $bResultAfterADone,
    'b_progress_total' => $bProgressTotal,
    'b_progress_after_a_done' => $bProgressAfterADone,
    'wait_a' => $waitA,
    'wait_b' => $waitB,
    'off_a_progress' => $offAProgress,
    'off_b_progress' => $offBProgress,
    'off_a_result' => $offAResult,
    'off_b_result' => $offBResult,
]);
