<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();

$bootstrapFile = sys_get_temp_dir() . '/phpqt_worker_events_publish_' . bin2hex(random_bytes(4)) . '.php';
$bootstrapCode = <<<'PHP'
<?php
function qt_worker_publish_dynamic_events(): int
{
    for ($i = 1; $i <= 5; $i++) {
        \Qt\Core\QThreadRuntime::publish('progress', ['step' => $i]);
        if (($i % 2) === 0) {
            \Qt\Core\QThreadRuntime::publish('decompressed', ['chunk' => $i]);
        }
        usleep(20000);
    }

    return 123;
}
PHP;
file_put_contents($bootstrapFile, $bootstrapCode);

$runtime->setBootstrapScript($bootstrapFile);
$runtime->start();

$progress = [];
$decompressed = [];

$progressListener = $runtime->on('progress', static function (array $event) use (&$progress): void {
    $progress[] = (int) ($event['payload']['step'] ?? -1);
});
$decompressedListener = $runtime->on('decompressed', static function (array $event) use (&$decompressed): void {
    $decompressed[] = (int) ($event['payload']['chunk'] ?? -1);
});

$offInvalid = $runtime->off(9999999);

$jobId = $runtime->submit('qt_worker_publish_dynamic_events', []);
$drained = 0;
$result = null;
while (true) {
    $drained += $runtime->drainEvents(16);
    $result = $runtime->await($jobId, 30);
    if ($result !== null) {
        break;
    }
}
$drained += $runtime->drainEvents(-1);

$offProgress = $runtime->off($progressListener);
$offDecompressed = $runtime->off($decompressedListener);
$stats = $runtime->stats();
$stopped = $runtime->stop(2000);

@unlink($bootstrapFile);

qt_runtime_result([
    'result' => $result,
    'progress_count' => count($progress),
    'decompressed_count' => count($decompressed),
    'progress_first' => $progress[0] ?? null,
    'off_invalid' => $offInvalid,
    'off_progress' => $offProgress,
    'off_decompressed' => $offDecompressed,
    'drained' => $drained,
    'events_out_enqueued' => (int) ($stats['events_out_enqueued'] ?? 0),
    'events_out_drained' => (int) ($stats['events_out_drained'] ?? 0),
    'stopped' => $stopped,
]);
