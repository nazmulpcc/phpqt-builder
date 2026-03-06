<?php

declare(strict_types=1);

use QtBuilder\Tests\Runtime\Support\QtRuntimeProcessRunner;

it('reports monolithic build metadata through Qt\\BuildInfo', function (): void {
    $payload = qt_runtime_payload('QtCore/build_info.php');

    expect($payload['build_mode'])->toBe('monolithic')
        ->and($payload['qt_version'])->not->toBe('')
        ->and($payload['extension_version'])->not->toBe('')
        ->and($payload['has_qtcore'])->toBeTrue()
        ->and($payload['is_qtcore_loaded'])->toBeTrue()
        ->and($payload['built_modules'])->toBe($payload['loaded_modules'])
        ->and($payload['qtcore_info']['module'] ?? null)->toBe('QtCore')
        ->and($payload['qtcore_info']['loaded'] ?? null)->toBeTrue()
        ->and($payload['manifest']['build_mode'] ?? null)->toBe('monolithic')
        ->and($payload['manifest']['qt_version'] ?? null)->toBe($payload['qt_version'])
        ->and($payload['manifest']['loaded_modules'] ?? null)->toBe($payload['loaded_modules']);
});

it('reports only qtcore as loaded when a split build loads qtcore alone', function (): void {
    $result = QtRuntimeProcessRunner::runFixtureWithModules(
        'Modules/build_info.php',
        ['QtCore'],
    );

    if ($result->isSkipped()) {
        test()->markTestSkipped($result->skipReason());
    }

    expect($result->exitCode())->toBe(
        0,
        sprintf(
            "Split BuildInfo fixture failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
            $result->stdout(),
            $result->stderr(),
        ),
    );

    $payload = $result->payload();

    expect($payload['build_mode'] ?? null)->toBe('modular')
        ->and($payload['has_qtcore'] ?? false)->toBeTrue()
        ->and($payload['is_qtcore_loaded'] ?? false)->toBeTrue()
        ->and($payload['loaded_modules'] ?? null)->toBe(['QtCore'])
        ->and($payload['qtcore_info']['loaded'] ?? null)->toBeTrue()
        ->and($payload['manifest']['loaded_modules'] ?? null)->toBe(['QtCore']);
});

it('tracks split module load state after dependency-ordered registration', function (): void {
    $result = QtRuntimeProcessRunner::runFixtureWithModules(
        'Modules/build_info.php',
        ['QtWidgets'],
    );

    if ($result->isSkipped()) {
        test()->markTestSkipped($result->skipReason());
    }

    expect($result->exitCode())->toBe(
        0,
        sprintf(
            "Split BuildInfo dependency fixture failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
            $result->stdout(),
            $result->stderr(),
        ),
    );

    $payload = $result->payload();

    expect($payload['build_mode'] ?? null)->toBe('modular')
        ->and($payload['loaded_modules'] ?? null)->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($payload['is_qtgui_loaded'] ?? false)->toBeTrue()
        ->and($payload['is_qtwidgets_loaded'] ?? false)->toBeTrue()
        ->and($payload['qtwidgets_info']['dependencies'] ?? null)->toBe(['QtCore', 'QtGui'])
        ->and($payload['qtwidgets_info']['loaded'] ?? null)->toBeTrue()
        ->and($payload['manifest']['loaded_modules'] ?? null)->toBe(['QtCore', 'QtGui', 'QtWidgets']);
});
