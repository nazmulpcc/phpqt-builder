<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();

$bootstrapFile = sys_get_temp_dir() . '/phpqt_worker_bootstrap_' . bin2hex(random_bytes(4)) . '.php';
$bootstrapCode = <<<'PHP'
<?php
function qt_worker_add(int $a, int $b): int
{
    return $a + $b;
}
PHP;
file_put_contents($bootstrapFile, $bootstrapCode);

$runtime->setBootstrapScript($bootstrapFile);
$runtime->start();

$jobId = $runtime->submit('qt_worker_add', [19, 23]);
$result = $runtime->await($jobId, 3000);
$stats = $runtime->stats();
$stopped = $runtime->stop(2000);

@unlink($bootstrapFile);

qt_runtime_result([
    'sum' => $result,
    'worker_bootstrap_failed' => (bool) ($stats['worker_bootstrap_failed'] ?? true),
    'stopped' => $stopped,
    'main_has_function' => function_exists('qt_worker_add'),
]);
