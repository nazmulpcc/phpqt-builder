<?php

declare(strict_types=1);

use QtBuilder\Commands\BuildModulesCommand;
use QtBuilder\Tests\Support\FakeExtensionBootstrapper;
use QtBuilder\Tests\Support\FakeSystemInformation;

it('builds split module trees with imported qtcore abi', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-modules-' . bin2hex(random_bytes(4));
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildModulesCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtWidgets',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    $qtCoreRoot = $buildRoot . '/QtCore';
    $qtWidgetsRoot = $buildRoot . '/QtWidgets';

    expect($result)->toBeSuccessfulCommandResult()
        ->and(is_file($qtCoreRoot . '/generated/module_abi.json'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/generated/module_abi.json'))->toBeTrue()
        ->and(is_file($qtCoreRoot . '/ext/classes/qt_qobject.cpp'))->toBeTrue()
        ->and(is_file($qtCoreRoot . '/ext/classes/qt_qmetaobjectconnection.h'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qwidget.cpp'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qobject.cpp'))->toBeFalse()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qmetaobjectconnection.h'))->toBeFalse()
        ->and($result['display'])->toContain(
            'Building QtCore as qtcore...',
            'Building QtWidgets as qtwidgets...',
            'Extension load order: qtcore, qtwidgets',
        );

    expect(array_map(static fn($context): string => $context->extensionName, $bootstrapper->contexts))
        ->toBe(['qtcore', 'qtwidgets']);

    $qtWidgetsConfig = (string) file_get_contents($qtWidgetsRoot . '/ext/config.m4');
    expect($qtWidgetsConfig)->toContain(
        'PHP_ADD_INCLUDE([' . $qtCoreRoot . '/ext])',
        'PHP_ADD_INCLUDE([' . $qtCoreRoot . '/ext/classes])',
    );

    $qtWidgetsSource = (string) file_get_contents($qtWidgetsRoot . '/ext/classes/qt_qwidget.cpp');
    expect($qtWidgetsSource)->toContain('#include "qt_qobject.h"', '#include "qt_qmetaobjectconnection.h"');

    $qtCoreManifest = qt_decode_json((string) file_get_contents($qtCoreRoot . '/generated/module_abi.json'));
    $qtWidgetsManifest = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/module_abi.json'));
    expect($qtCoreManifest['module'])->toBe('QtCore')
        ->and($qtCoreManifest['extension_name'])->toBe('qtcore')
        ->and($qtCoreManifest['includes_signal_connection_support'])->toBeTrue()
        ->and($qtWidgetsManifest['module'])->toBe('QtWidgets')
        ->and($qtWidgetsManifest['extension_name'])->toBe('qtwidgets')
        ->and($qtWidgetsManifest['classes'])->toBe(['QWidget'])
        ->and($qtWidgetsManifest['includes_signal_connection_support'])->toBeFalse();

    $qtWidgetsSummary = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/build_summary.json'));
    expect($qtWidgetsSummary['generated_classes'])->toBe(1)
        ->and($qtWidgetsSummary['skipped_classes'])->toBe(1);

    $qtWidgetsSkippedClasses = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/skipped_classes.json'));
    $skippedByClass = [];
    foreach ($qtWidgetsSkippedClasses as $skippedClass) {
        $skippedByClass[$skippedClass['class']] = $skippedClass['reason_code'];
    }

    expect($skippedByClass['QExternalWidget'] ?? null)->toBe('unsupported_external_module_dependency');
});
