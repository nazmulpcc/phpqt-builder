<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\Attributes\Signal::class, 'Qt signal attributes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QPhpSignalConnection::class, 'Qt PHP signal connection class is unavailable in this build.');

foreach (['on', 'off', 'emit'] as $method) {
    if (!method_exists(\Qt\Core\QObject::class, $method)) {
        qt_runtime_skip(sprintf('%s::%s() is unavailable in this build.', \Qt\Core\QObject::class, $method));
    }
}

final class RuntimePhpSignalValidationWorker extends \Qt\Core\QObject
{
    #[\Qt\Core\Attributes\Signal(['string'])]
    protected function dataChanged(): void {}
}

final class RuntimePhpSignalValidationPublicSignalWorker extends \Qt\Core\QObject
{
    #[\Qt\Core\Attributes\Signal(['string'])]
    public function badSignal(): void {}
}

final class RuntimePhpSignalValidationInvalidTypeWorker extends \Qt\Core\QObject
{
    #[\Qt\Core\Attributes\Signal(['callable'])]
    protected function badTypedSignal(): void {}
}

/**
 * @return array{threw: bool, message: string}
 */
function runtime_php_signal_probe(callable $callback): array
{
    try {
        $callback();
        return ['threw' => false, 'message' => ''];
    } catch (\Throwable $e) {
        return ['threw' => true, 'message' => $e->getMessage()];
    }
}

$worker = new RuntimePhpSignalValidationWorker();
$connection = $worker->on('dataChanged', static function (string $value): void {
    $_ = $value;
});

$unknownOn = runtime_php_signal_probe(static function () use ($worker): void {
    $worker->on('missingSignal', static function (): void {});
});

$unknownEmit = runtime_php_signal_probe(static function () use ($worker): void {
    $worker->emit('missingSignal');
});

$wrongCount = runtime_php_signal_probe(static function () use ($worker): void {
    $worker->emit('dataChanged');
});

$wrongType = runtime_php_signal_probe(static function () use ($worker): void {
    $worker->emit('dataChanged', [123]);
});

$publicSignalProbe = runtime_php_signal_probe(static function (): void {
    $worker = new RuntimePhpSignalValidationPublicSignalWorker();
    $worker->on('badSignal', static function (string $value): void {
        $_ = $value;
    });
});

$invalidTypeProbe = runtime_php_signal_probe(static function (): void {
    $worker = new RuntimePhpSignalValidationInvalidTypeWorker();
    $worker->emit('badTypedSignal');
});

qt_runtime_result([
    'connection_is_object' => $connection instanceof \Qt\Core\QPhpSignalConnection,
    'unknown_on' => $unknownOn,
    'unknown_emit' => $unknownEmit,
    'wrong_count' => $wrongCount,
    'wrong_type' => $wrongType,
    'public_signal' => $publicSignalProbe,
    'invalid_type' => $invalidTypeProbe,
]);
