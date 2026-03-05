<?php

declare(strict_types=1);

use QtBuilder\Commands\InspectCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;

it('inspects a class by name using qt-path discovery', function (): void {
    $result = qt_command_result(
        new InspectCommand(FakeSystemInformation::passing()),
        [
            'class' => 'QPoint',
            '--qt-path' => qt_fixture_path('qt'),
            '--format' => 'json',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult();
    $payload = qt_decode_json($result['display']);

    expect($payload['name'])->toBe('QPoint');
});

it('supports an explicit header override', function (): void {
    $result = qt_command_result(
        new InspectCommand(FakeSystemInformation::passing()),
        [
            'class' => 'QSignalFixture',
            '--header' => qt_fixture_path('signals-qt/include/QtCore/qsignalfixture.h'),
            '--format' => 'json',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult();
    $payload = qt_decode_json($result['display']);

    expect($payload['name'])->toBe('QSignalFixture');
});

it('fails with a clear message when no header can be resolved', function (): void {
    $result = qt_command_result(
        new InspectCommand(FakeSystemInformation::passing()),
        [
            'class' => 'QDefinitelyMissingClass',
        ],
    );

    expect($result)->toBeFailureCommandResult()
        ->and($result['display'])->toContain('Unable to locate header for class "QDefinitelyMissingClass".');
});

