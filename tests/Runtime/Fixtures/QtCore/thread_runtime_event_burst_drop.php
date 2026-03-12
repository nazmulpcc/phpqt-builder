<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();

$bootstrapFile = sys_get_temp_dir() . '/phpqt_worker_events_burst_' . bin2hex(random_bytes(4)) . '.php';
$bootstrapCode = <<<'PHP'
<?php
function qt_worker_burst_publish(int $count): int
{
    $published = 0;
    for ($i = 0; $i < $count; $i++) {
        if (\Qt\Core\QThreadRuntime::publish('burst', ['i' => $i])) {
            $published++;
        }
    }

    return $published;
}
PHP;
file_put_contents($bootstrapFile, $bootstrapCode);

$runtime->setBootstrapScript($bootstrapFile);
$runtime->start();

$received = 0;
$runtime->on('burst', static function (array $event) use (&$received): void {
    $received++;
});

$jobId = $runtime->submit('qt_worker_burst_publish', [200]);
$published = $runtime->await($jobId, 5000);
$drained = $runtime->drainEvents(-1);
$stats = $runtime->stats();
$stopped = $runtime->stop(2000);

@unlink($bootstrapFile);

qt_runtime_result([
    'published' => (int) $published,
    'received' => $received,
    'drained' => $drained,
    'events_out_enqueued' => (int) ($stats['events_out_enqueued'] ?? 0),
    'events_out_dropped_full' => (int) ($stats['events_out_dropped_full'] ?? 0),
    'stopped' => $stopped,
]);
