<?php

declare(strict_types=1);

it('q object supports basic lifecycle methods', function (): void {
    $payload = qt_runtime_inline_payload(<<<'PHP'
    echo 'PHPQT_RESULT=' . json_encode((function (): array {
        $parent = new \Qt\Core\QObject();
        $child = new \Qt\Core\QObject();
        $child->setObjectName('Smoke');
        $child->setParent($parent);

        return [
            'name' => $child->objectName(),
            'has_parent' => $child->parent() instanceof \Qt\Core\QObject,
            'inherits_qobject' => $child->inherits('QObject'),
        ];
    })(), JSON_THROW_ON_ERROR) . PHP_EOL;
    PHP);

    expect($payload['name'])->toBe('Smoke')
        ->and($payload['has_parent'])->toBeTrue()
        ->and($payload['inherits_qobject'])->toBeTrue();
});

it('q date supports mutation and field access', function (): void {
    $payload = qt_runtime_inline_payload(<<<'PHP'
    echo 'PHPQT_RESULT=' . json_encode((function (): array {
        $date = new \Qt\Core\QDate();

        return [
            'was_null' => $date->isNull(),
            'set_ok' => $date->setDate(2025, 3, 2),
            'year' => $date->year(),
            'month' => $date->month(),
            'day' => $date->day(),
            'valid' => $date->isValid(),
        ];
    })(), JSON_THROW_ON_ERROR) . PHP_EOL;
    PHP);

    expect($payload['was_null'])->toBeTrue()
        ->and($payload['set_ok'])->toBeTrue()
        ->and($payload['year'])->toBe(2025)
        ->and($payload['month'])->toBe(3)
        ->and($payload['day'])->toBe(2)
        ->and($payload['valid'])->toBeTrue();
});

it('q point supports coordinate mutation', function (): void {
    $payload = qt_runtime_inline_payload(<<<'PHP'
    echo 'PHPQT_RESULT=' . json_encode((function (): array {
        $point = new \Qt\Core\QPoint();
        $point->setX(3);
        $point->setY(4);

        return [
            'x' => $point->x(),
            'y' => $point->y(),
            'is_null' => $point->isNull(),
            'manhattan' => $point->manhattanLength(),
        ];
    })(), JSON_THROW_ON_ERROR) . PHP_EOL;
    PHP);

    expect($payload['x'])->toBe(3)
        ->and($payload['y'])->toBe(4)
        ->and($payload['is_null'])->toBeFalse()
        ->and($payload['manhattan'])->toBe(7);
});

it('q string supports basic empty string operations', function (): void {
    $payload = qt_runtime_inline_payload(<<<'PHP'
    echo 'PHPQT_RESULT=' . json_encode((function (): array {
        $string = new \Qt\Core\QString();

        return [
            'empty' => $string->isEmpty(),
            'size' => $string->size(),
            'length' => $string->length(),
            'std' => $string->toStdString(),
            'upper' => $string->toUpper(),
            'lower' => $string->toLower(),
            'trimmed' => $string->trimmed(),
        ];
    })(), JSON_THROW_ON_ERROR) . PHP_EOL;
    PHP);

    expect($payload['empty'])->toBeTrue()
        ->and($payload['size'])->toBe(0)
        ->and($payload['length'])->toBe(0)
        ->and($payload['std'])->toBe('')
        ->and($payload['upper'])->toBe('')
        ->and($payload['lower'])->toBe('')
        ->and($payload['trimmed'])->toBe('');
});
