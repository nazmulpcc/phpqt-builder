<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();

$bootstrapFile = sys_get_temp_dir() . '/phpqt_worker_bootstrap_array_' . bin2hex(random_bytes(4)) . '.php';
$bootstrapCode = <<<'PHP'
<?php
final class WorkerTasks
{
    public static function multiply(int $a, int $b): int
    {
        return $a * $b;
    }
}
PHP;
file_put_contents($bootstrapFile, $bootstrapCode);

$runtime->setBootstrapScript($bootstrapFile);
$runtime->start();

$jobId = $runtime->submit(['WorkerTasks', 'multiply'], [6, 7]);
$result = null;
$awaitErrored = false;
try {
    $result = $runtime->await($jobId, 3000);
} catch (\Throwable) {
    $awaitErrored = true;
}

$stats = $runtime->stats();
$stopped = $runtime->stop(2000);

@unlink($bootstrapFile);

qt_runtime_result([
    'result' => $result,
    'await_errored' => $awaitErrored,
    'worker_bootstrap_failed' => (bool) ($stats['worker_bootstrap_failed'] ?? true),
    'stopped' => $stopped,
    'main_has_class' => class_exists('WorkerTasks', false),
]);
