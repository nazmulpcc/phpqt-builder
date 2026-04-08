<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_static_connect_phase3_worker_class.php';

qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QThread::class, 'QtCore thread classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QMetaObjectConnection::class, 'QtCore connection classes are unavailable in this build.');

if (!method_exists(\Qt\Core\QObject::class, 'connect') || !method_exists(\Qt\Core\QObject::class, 'disconnect')) {
    qt_runtime_skip(\Qt\Core\QObject::class . '::connect()/disconnect() are unavailable in this build.');
}

$thread = new \Qt\Core\QThread();
$worker = new RuntimeStaticConnectPhase3Worker();

$probe = static function (callable $attempt): array {
    try {
        $connection = $attempt();
        if ($connection instanceof \Qt\Core\QMetaObjectConnection) {
            \Qt\Core\QObject::disconnect($connection);
        }

        return [
            'threw' => false,
            'message' => '',
            'is_connection' => $connection instanceof \Qt\Core\QMetaObjectConnection,
        ];
    } catch (\Throwable $e) {
        return [
            'threw' => true,
            'message' => $e->getMessage(),
            'is_connection' => false,
        ];
    }
};

$explicitOverload = $probe(static fn() => \Qt\Core\QObject::connect($thread, 'destroyed()', $worker, 'doWork()'));

qt_runtime_result([
    'bare_signal' => $probe(static fn() => \Qt\Core\QObject::connect($thread, 'started', $worker, 'doWork()')),
    'macro_signal' => $probe(static fn() => \Qt\Core\QObject::connect($thread, 'SIGNAL(started())', $worker, 'doWork()')),
    'bare_method' => $probe(static fn() => \Qt\Core\QObject::connect($thread, 'started()', $worker, 'doWork')),
    'macro_method' => $probe(static fn() => \Qt\Core\QObject::connect($thread, 'started()', $worker, 'SLOT(doWork())')),
    'unknown_signal' => $probe(static fn() => \Qt\Core\QObject::connect($thread, 'missingSignal()', $worker, 'doWork()')),
    'unknown_method' => $probe(static fn() => \Qt\Core\QObject::connect($thread, 'started()', $worker, 'missingMethod()')),
    'explicit_overload' => $explicitOverload,
]);
