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

it('prints helpful human-readable metadata for qt discovery', function (): void {
    $system = FakeSystemInformation::passing();
    $system->setQtDetectionResult(
        new QtDetectionResult(
            true,
            'Qt 6.8.1 discovered via qtpaths6.',
            [
                'tool' => 'qtpaths6',
                'path' => '/usr/bin/qtpaths6',
                'version' => '6.8.1',
                'headers' => '/opt/qt/include',
                'libs' => '/opt/qt/lib',
                'host_prefix' => '/opt/qt',
            ],
        ),
    );

    $result = qt_command_result(new DoctorCommand($system), []);

    expect($result)->toBeSuccessfulCommandResult()
        ->and($result['display'])->toContain('tool: qtpaths6')
        ->and($result['display'])->toContain('headers: /opt/qt/include')
        ->and($result['display'])->toContain('libs: /opt/qt/lib');
});

it('fails when make is missing even if ninja exists', function (): void {
    $system = FakeSystemInformation::passing();
    $system->setExecutable('make', null);
    $system->setExecutable('ninja', '/usr/bin/ninja');

    $result = qt_command_result(new DoctorCommand($system), ['--format' => 'json']);
    $payload = qt_decode_json($result['display']);

    $makeCheck = null;
    foreach ($payload['checks'] as $check) {
        if ($check['id'] === 'make') {
            $makeCheck = $check;
            break;
        }
    }

    expect($result)->toBeFailureCommandResult()
        ->and($makeCheck)->not->toBeNull()
        ->and($makeCheck['status'])->toBe('fail')
        ->and($makeCheck['message'])->toContain('make was not found');
});
