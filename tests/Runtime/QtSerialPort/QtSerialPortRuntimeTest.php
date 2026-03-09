<?php

declare(strict_types=1);

it('covers serial port info standard baud rates and port configuration', function (): void {
    $payload = qt_runtime_payload('QtSerialPort/serial_port_smoke.php');

    expect($payload['has_standard_baud_rates'])->toBeTrue()
        ->and($payload['has_9600'])->toBeTrue()
        ->and($payload['has_115200'])->toBeTrue()
        ->and($payload['port_name'])->toBe('ttyUSB0')
        ->and($payload['baud_rate'])->toBe(9600)
        ->and($payload['is_open'])->toBeFalse();
});
