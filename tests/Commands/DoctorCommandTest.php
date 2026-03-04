<?php

declare(strict_types=1);

use QtBuilder\Commands\DoctorCommand;
use QtBuilder\System\QtDetectionResult;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;

it('returns failure and json when checks fail', function (): void {
    $system = FakeSystemInformation::passing();
    $system->setExtension('cparser', false);

    $result = qt_command_result(new DoctorCommand($system), ['--format' => 'json']);

    expect($result)->toBeFailureCommandResult();

    $payload = qt_decode_json($result['display']);
    expect($payload['summary']['status'])->toBe('fail');
});

it('rejects unsupported formats', function (): void {
    $system = FakeSystemInformation::passing();

    $result = qt_command_result(new DoctorCommand($system), ['--format' => 'yaml']);

    expect($result)->toBeFailureCommandResult()
        ->and($result['display'])->toContain('Unsupported format');
});

it('marks qt check as failure when qt is not detected', function (): void {
    $system = FakeSystemInformation::passing();
    $system->setQtDetectionResult(
        new QtDetectionResult(
            false,
            'No working Qt discovery path found (qtpaths/qmake/pkg-config Qt6Core).',
            ['attempts' => []],
        ),
    );

    $result = qt_command_result(new DoctorCommand($system), ['--format' => 'json']);
    $payload = qt_decode_json($result['display']);

    expect($result)->toBeFailureCommandResult()
        ->and($payload['summary']['status'])->toBe('fail');

    $qtCheck = null;
    foreach ($payload['checks'] as $check) {
        if ($check['id'] === 'qt_discovery') {
            $qtCheck = $check;
            break;
        }
    }

    expect($qtCheck)->not->toBeNull();
    expect($qtCheck['status'])->toBe('fail');
});
