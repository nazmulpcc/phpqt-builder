<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/thread_static_connect_phase3_worker_class.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QProcess::class, 'QtCore process classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QMetaObjectConnection::class, 'QtCore connection classes are unavailable in this build.');

if (!method_exists(\Qt\Core\QObject::class, 'connect') || !method_exists(\Qt\Core\QObject::class, 'disconnect')) {
    qt_runtime_skip(\Qt\Core\QObject::class . '::connect()/disconnect() are unavailable in this build.');
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);

$receiver = new RuntimeStaticConnectPhase3Worker();
$nameSender = new \Qt\Core\QObject();
$doomed = new \Qt\Core\QObject();
$process = new \Qt\Core\QProcess();

$optionalConnection = \Qt\Core\QObject::connect(
    $nameSender,
    'objectNameChanged(QString)',
    $receiver,
    'captureOptional(QString)',
);

$exitOnlyConnection = \Qt\Core\QObject::connect(
    $process,
    'finished(int,QProcess::ExitStatus)',
    $receiver,
    'captureExitCodeOnly(int)',
);

$finishedConnection = \Qt\Core\QObject::connect(
    $process,
    'finished(int,QProcess::ExitStatus)',
    $receiver,
    'captureProcessFinished(int,QProcess::ExitStatus)',
);

$destroyedConnection = \Qt\Core\QObject::connect(
    $doomed,
    'destroyed(QObject*)',
    $receiver,
    'captureDestroyed(QObject*)',
);

$finished = false;
$process->onFinished(static function () use ($app, &$finished): void {
    $finished = true;
    \Qt\Core\QCoreApplication::quit();
});

$nameSender->setObjectName('alpha');
$doomed->deleteLater();
$process->setProgram(PHP_BINARY);
$process->setArguments(['-r', 'usleep(50000); exit(7);']);
$process->start();
\Qt\Core\QCoreApplication::exec();

qt_runtime_result([
    'wait_ok' => $finished,
    'optional_value' => (string) $receiver->property('phase31_optional_value'),
    'exit_code_only' => (int) $receiver->property('phase31_exit_code_only'),
    'finished_exit_code' => (int) $receiver->property('phase31_finished_exit_code'),
    'finished_exit_status' => (int) $receiver->property('phase31_finished_exit_status'),
    'destroyed_wrapped' => (bool) $receiver->property('phase31_destroyed_wrapped'),
    'destroyed_class' => (string) $receiver->property('phase31_destroyed_class'),
    'connections_are_objects' => $optionalConnection instanceof \Qt\Core\QMetaObjectConnection
        && $exitOnlyConnection instanceof \Qt\Core\QMetaObjectConnection
        && $finishedConnection instanceof \Qt\Core\QMetaObjectConnection
        && $destroyedConnection instanceof \Qt\Core\QMetaObjectConnection,
]);
