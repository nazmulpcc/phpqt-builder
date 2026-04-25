<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_move_to_thread_phase2_worker_class.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');

if (!method_exists(\Qt\Core\QObject::class, 'moveToThread')) {
    qt_runtime_skip(\Qt\Core\QObject::class . '::moveToThread() is unavailable in this build.');
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$thread = new \Qt\Core\QThread();
$worker = new RuntimeMoveToThreadPhase2Worker();
$worker->state = 'source-custom';
$worker->meta = ['mode' => 'custom'];

$moveOk = $worker->moveToThread($thread);

$cloneResult = static function () use ($worker): array {
    try {
        $clone = clone $worker;
        unset($clone);

        return [
            'threw' => false,
            'message' => null,
        ];
    } catch (\Throwable $e) {
        return [
            'threw' => true,
            'message' => $e->getMessage(),
        ];
    }
};

$cloneProbe = $cloneResult();
ob_start();
var_dump($worker);
$dump = (string) ob_get_clean();

qt_runtime_result([
    'move_ok' => $moveOk,
    'clone_probe' => $cloneProbe,
    'dump' => $dump,
    'dump_has_state' => str_contains($dump, 'state'),
    'dump_has_meta' => str_contains($dump, 'meta'),
    'dump_has_source_custom' => str_contains($dump, 'source-custom'),
]);
