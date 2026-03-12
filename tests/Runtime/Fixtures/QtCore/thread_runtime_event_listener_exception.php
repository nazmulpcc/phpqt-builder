<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();

$bootstrapFile = sys_get_temp_dir() . '/phpqt_worker_events_exception_' . bin2hex(random_bytes(4)) . '.php';
$bootstrapCode = <<<'PHP'
<?php
function qt_worker_emit_for_listener_exception(): int
{
    for ($i = 1; $i <= 4; $i++) {
        \Qt\Core\QThreadRuntime::publish('progress', ['step' => $i]);
        usleep(15000);
    }

    return 7;
}
PHP;
file_put_contents($bootstrapFile, $bootstrapCode);

$runtime->setBootstrapScript($bootstrapFile);
$runtime->start();

$firstHits = 0;
$secondHits = 0;

$runtime->on('progress', static function (array $event) use (&$firstHits): void {
    $firstHits++;
    throw new RuntimeException('listener boom');
});

$runtime->on('progress', static function (array $event) use (&$secondHits): void {
    $secondHits++;
});

$jobId = $runtime->submit('qt_worker_emit_for_listener_exception', []);
$result = null;
while (true) {
    $runtime->drainEvents(32);
    $result = $runtime->await($jobId, 50);
    if ($result !== null) {
        break;
    }
}
$runtime->drainEvents(-1);

$stats = $runtime->stats();
$stopped = $runtime->stop(2000);
@unlink($bootstrapFile);

qt_runtime_result([
    'result' => $result,
    'first_hits' => $firstHits,
    'second_hits' => $secondHits,
    'listener_dispatch_errors' => (int) ($stats['listener_dispatch_errors'] ?? 0),
    'stopped' => $stopped,
]);
