<?php

declare(strict_types=1);

it('q object supports basic lifecycle methods', function (): void {
    $payload = qt_runtime_payload('QtCore/qobject_smoke.php');

    expect($payload['name'])->toBe('Smoke')
        ->and($payload['has_parent'])->toBeTrue()
        ->and($payload['inherits_qobject'])->toBeTrue();
});

it('q date supports mutation and field access', function (): void {
    $payload = qt_runtime_payload('QtCore/qdate_smoke.php');

    expect($payload['was_null'])->toBeTrue()
        ->and($payload['set_ok'])->toBeTrue()
        ->and($payload['year'])->toBe(2025)
        ->and($payload['month'])->toBe(3)
        ->and($payload['day'])->toBe(2)
        ->and($payload['valid'])->toBeTrue();
});

it('q point supports coordinate mutation', function (): void {
    $payload = qt_runtime_payload('QtCore/qpoint_smoke.php');

    expect($payload['x'])->toBe(3)
        ->and($payload['y'])->toBe(4)
        ->and($payload['is_null'])->toBeFalse()
        ->and($payload['manhattan'])->toBe(7);
});

it('q string supports basic empty string operations', function (): void {
    $payload = qt_runtime_payload('QtCore/qstring_smoke.php');

    expect($payload['empty'])->toBeTrue()
        ->and($payload['size'])->toBe(0)
        ->and($payload['length'])->toBe(0)
        ->and($payload['std'])->toBe('')
        ->and($payload['upper'])->toBe('')
        ->and($payload['lower'])->toBe('')
        ->and($payload['trimmed'])->toBe('');
});
