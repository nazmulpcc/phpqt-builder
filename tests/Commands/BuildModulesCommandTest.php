<?php

declare(strict_types=1);

use QtBuilder\Commands\BuildModulesCommand;
use QtBuilder\Tests\Support\FakeExtensionBootstrapper;
use QtBuilder\Tests\Support\FakeSystemInformation;

it('builds split module trees with a shared sdk and skips unavailable sibling abi', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-modules-' . bin2hex(random_bytes(4));
    $sharedRoot = $buildRoot . '/ext';
    $sharedClassesDir = $sharedRoot . '/classes';
    $qtCoreRoot = $buildRoot . '/QtCore';
    $qtWidgetsRoot = $buildRoot . '/QtWidgets';
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

    expect($result)->toBeSuccessfulCommandResult()
        ->and(is_file($buildRoot . '/generated/module_graph.json'))->toBeTrue()
        ->and(is_file($qtCoreRoot . '/generated/module_abi.json'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/generated/module_abi.json'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qobject.h'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qwidget.h'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qshortcutcarrier.h'))->toBeTrue()
        ->and(is_file($qtCoreRoot . '/ext/classes/qt_qmetaobjectconnection.h'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qwidget.cpp'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qshortcutcarrier.cpp'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qexternalwidget.cpp'))->toBeFalse()
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
        'PHP_ADD_INCLUDE([' . $sharedRoot . '])',
        'PHP_ADD_INCLUDE([' . $sharedClassesDir . '])',
    );

    $qtWidgetsManifest = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/module_abi.json'));
    expect($qtWidgetsManifest['module'])->toBe('QtWidgets')
        ->and($qtWidgetsManifest['extension_name'])->toBe('qtwidgets')
        ->and($qtWidgetsManifest['classes'])->toBe(['QShortcutCarrier', 'QWidget'])
        ->and($qtWidgetsManifest['dependency_modules'])->toBe(['QtCore'])
        ->and($qtWidgetsManifest['shared_include_dirs'])->toBe([$sharedRoot, $sharedClassesDir])
        ->and($qtWidgetsManifest['includes_signal_connection_support'])->toBeFalse();

    $qtWidgetsSummary = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/build_summary.json'));
    expect($qtWidgetsSummary['generated_classes'])->toBe(2)
        ->and($qtWidgetsSummary['skipped_classes'])->toBe(1)
        ->and($qtWidgetsSummary['bootstrap_disabled'] ?? false)->toBeFalse();

    $qtWidgetsSkippedClasses = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/skipped_classes.json'));
    $skippedByClass = [];
    foreach ($qtWidgetsSkippedClasses as $skippedClass) {
        $skippedByClass[$skippedClass['class']] = $skippedClass['reason_code'];
    }

    expect($skippedByClass['QExternalWidget'] ?? null)->toBe('unsupported_parent_class');

    $qtWidgetsSkippedMethods = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/skipped_methods.json'));
    $shortcutReasons = array_values(array_map(
        static fn(array $entry): string => (string) ($entry['reason_code'] ?? ''),
        array_filter(
            $qtWidgetsSkippedMethods,
            static fn(array $entry): bool => ($entry['class'] ?? null) === 'QShortcutCarrier',
        ),
    ));

    expect($shortcutReasons)->toContain('unsupported_return_type', 'unsupported_parameter_type');

    $graph = qt_decode_json((string) file_get_contents($buildRoot . '/generated/module_graph.json'));
    expect($graph['build_order'])->toBe(['QtCore', 'QtWidgets'])
        ->and($graph['dependencies']['QtWidgets'] ?? null)->toBe(['QtCore']);
});

it('supports sibling inheritance and method wrappers during split builds', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-modules-siblings-' . bin2hex(random_bytes(4));
    $sharedRoot = $buildRoot . '/ext';
    $sharedClassesDir = $sharedRoot . '/classes';
    $qtGuiRoot = $buildRoot . '/QtGui';
    $qtWidgetsRoot = $buildRoot . '/QtWidgets';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildModulesCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtGui,QtWidgets',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and(is_file($qtGuiRoot . '/ext/classes/qt_qstandarditemmodel.cpp'))->toBeTrue()
        ->and(is_file($qtGuiRoot . '/ext/classes/qt_qicon.cpp'))->toBeTrue()
        ->and(is_file($qtGuiRoot . '/ext/classes/qt_qkeysequence.cpp'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qexternalwidget.cpp'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qshortcutcarrier.cpp'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qstandarditemmodel.h'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qicon.h'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qkeysequence.h'))->toBeTrue()
        ->and($result['display'])->toContain('Extension load order: qtcore, qtgui, qtwidgets');

    expect(array_map(static fn($context): string => $context->extensionName, $bootstrapper->contexts))
        ->toBe(['qtcore', 'qtgui', 'qtwidgets']);

    $qtWidgetsConfig = (string) file_get_contents($qtWidgetsRoot . '/ext/config.m4');
    expect($qtWidgetsConfig)->toContain(
        'PHP_ADD_INCLUDE([' . $sharedRoot . '])',
        'PHP_ADD_INCLUDE([' . $sharedClassesDir . '])',
        'PHP_ADD_INCLUDE([' . $fixtureRoot . '/include/QtGui])',
    );

    $externalSource = (string) file_get_contents($qtWidgetsRoot . '/ext/classes/qt_qexternalwidget.cpp');
    expect($externalSource)->toContain('#include "qt_qstandarditemmodel.h"');

    $shortcutSource = (string) file_get_contents($qtWidgetsRoot . '/ext/classes/qt_qshortcutcarrier.cpp');
    expect($shortcutSource)->toContain('#include "qt_qicon.h"', '#include "qt_qkeysequence.h"');

    $qtWidgetsManifest = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/module_abi.json'));
    expect($qtWidgetsManifest['classes'])->toBe(['QExternalWidget', 'QShortcutCarrier', 'QWidget'])
        ->and($qtWidgetsManifest['dependency_modules'])->toBe(['QtCore', 'QtGui']);

    $qtWidgetsSkippedClasses = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/skipped_classes.json'));
    expect($qtWidgetsSkippedClasses)->toBe([]);

    $qtWidgetsSkippedMethods = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/skipped_methods.json'));
    $shortcutExternalReasons = array_values(array_filter(
        array_map(
            static fn(array $entry): ?string => ($entry['class'] ?? null) === 'QShortcutCarrier'
                ? (string) ($entry['reason_code'] ?? '')
                : null,
            $qtWidgetsSkippedMethods,
        ),
        static fn(?string $reason): bool => $reason === 'unsupported_external_module_dependency',
    ));
    expect($shortcutExternalReasons)->toBe([]);

    $graph = qt_decode_json((string) file_get_contents($buildRoot . '/generated/module_graph.json'));
    expect($graph['build_order'])->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($graph['dependencies']['QtGui'] ?? null)->toBe(['QtCore'])
        ->and($graph['dependencies']['QtWidgets'] ?? null)->toBe(['QtCore', 'QtGui']);
});

it('normalizes relative split build roots to absolute shared include paths', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $workspace = qt_temp_dir('qtbuilder-build-modules-relative-');
    $resolvedWorkspace = realpath($workspace) ?: $workspace;
    $previousCwd = getcwd();
    if (!is_string($previousCwd) || $previousCwd === '') {
        throw new RuntimeException('Could not read the current working directory.');
    }

    chdir($workspace);

    try {
        $bootstrapper = new FakeExtensionBootstrapper();

        $result = qt_command_result(
            new BuildModulesCommand(FakeSystemInformation::passing(), $bootstrapper),
            [
                '--qt-path' => $fixtureRoot,
                '--modules' => 'QtWidgets',
                '--output' => 'build',
                '--jobs' => '2',
            ],
        );

        $sharedRoot = $resolvedWorkspace . '/build/ext';
        $sharedClassesDir = $sharedRoot . '/classes';
        $qtCoreRoot = $resolvedWorkspace . '/build/QtCore';
        $qtWidgetsRoot = $resolvedWorkspace . '/build/QtWidgets';

        expect($result)->toBeSuccessfulCommandResult()
            ->and(is_file($qtCoreRoot . '/generated/module_abi.json'))->toBeTrue()
            ->and(is_file($qtWidgetsRoot . '/generated/module_abi.json'))->toBeTrue();

        $qtWidgetsConfig = (string) file_get_contents($qtWidgetsRoot . '/ext/config.m4');
        expect($qtWidgetsConfig)->toContain(
            'PHP_ADD_INCLUDE([' . $sharedRoot . '])',
            'PHP_ADD_INCLUDE([' . $sharedClassesDir . '])',
        );

        $qtCoreManifest = qt_decode_json((string) file_get_contents($qtCoreRoot . '/generated/module_abi.json'));
        expect($qtCoreManifest['build_root_dir'])->toBe($qtCoreRoot)
            ->and($qtCoreManifest['output_dir'])->toBe($qtCoreRoot . '/ext')
            ->and($qtCoreManifest['metadata_dir'])->toBe($qtCoreRoot . '/generated')
            ->and($qtCoreManifest['accepted_candidates_path'])->toBe($qtCoreRoot . '/generated/accepted_candidates.json')
            ->and($qtCoreManifest['class_cache_dir'])->toBe($resolvedWorkspace . '/build/classes')
            ->and($qtCoreManifest['include_dirs'])->toBe([
                $qtCoreRoot . '/ext',
                $qtCoreRoot . '/ext/classes',
            ])
            ->and($qtCoreManifest['shared_include_dirs'])->toBe([
                $sharedRoot,
                $sharedClassesDir,
            ]);
    } finally {
        chdir($previousCwd);
    }
});

it('supports generating split module trees without bootstrapping', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-modules-no-build-' . bin2hex(random_bytes(4));
    $qtCoreRoot = $buildRoot . '/QtCore';
    $qtWidgetsRoot = $buildRoot . '/QtWidgets';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildModulesCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtWidgets',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and($bootstrapper->contexts)->toHaveCount(0)
        ->and(is_file($qtCoreRoot . '/ext/config.m4'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/config.m4'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qwidget.cpp'))->toBeTrue()
        ->and(is_file($qtCoreRoot . '/ext/configure'))->toBeFalse()
        ->and(is_file($qtWidgetsRoot . '/ext/configure'))->toBeFalse()
        ->and(is_file($qtCoreRoot . '/ext/Makefile'))->toBeFalse()
        ->and(is_file($qtWidgetsRoot . '/ext/Makefile'))->toBeFalse()
        ->and(is_file($qtCoreRoot . '/ext/build/gen_stub.php'))->toBeFalse()
        ->and(is_file($qtWidgetsRoot . '/ext/build/gen_stub.php'))->toBeFalse()
        ->and($result['display'])->toContain('Skipping bootstrap (--no-build).');

    $qtCoreSummary = qt_decode_json((string) file_get_contents($qtCoreRoot . '/generated/build_summary.json'));
    $qtWidgetsSummary = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/build_summary.json'));
    expect($qtCoreSummary['bootstrap_disabled'] ?? null)->toBeTrue()
        ->and($qtCoreSummary['bootstrap'])->toBeNull()
        ->and($qtWidgetsSummary['bootstrap_disabled'] ?? null)->toBeTrue()
        ->and($qtWidgetsSummary['bootstrap'])->toBeNull();
});

it('fails fast when split module dependencies contain a cycle', function (): void {
    $fixtureRoot = qt_fixture_path('module-cycle-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-modules-cycle-' . bin2hex(random_bytes(4));
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildModulesCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtGui,QtWidgets',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeFailureCommandResult()
        ->and($result['display'])->toContain('Module dependency cycle detected:')
        ->and($bootstrapper->contexts)->toHaveCount(0)
        ->and(is_file($buildRoot . '/generated/module_graph.json'))->toBeFalse();
});
