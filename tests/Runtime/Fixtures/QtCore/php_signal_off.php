<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QTimer::class, 'QtCore timer classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\Attributes\Signal::class, 'Qt signal attributes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QPhpSignalConnection::class, 'Qt PHP signal connection class is unavailable in this build.');

foreach (['on', 'off', 'emit'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

final class RuntimePhpSignalOffEmitter extends \Qt\Core\QObject
{
    #[\Qt\Core\Attributes\Signal(['string'])]
    protected function ping(): void {}
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$emitter = new RuntimePhpSignalOffEmitter();
$hits = 0;

$connection = $emitter->on('ping', static function (string $message) use (&$hits): void {
    $hits++;
    $_ = $message;
});

$offOk = $emitter->off($connection);
$offAgain = $emitter->off($connection);

$timer = new \Qt\Core\QTimer();
$timer->setSingleShot(true);
$timer->onTimeout(static function (): void {
    \Qt\Core\QCoreApplication::quit();
});
$timer->start(25);

$emitter->emit('ping', ['after-off']);
\Qt\Core\QCoreApplication::exec();

qt_runtime_result([
    'connection_is_object' => $connection instanceof \Qt\Core\QPhpSignalConnection,
    'off_ok' => $offOk,
    'off_again' => $offAgain,
    'hits' => $hits,
]);
