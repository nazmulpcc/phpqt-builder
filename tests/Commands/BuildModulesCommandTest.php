<?php

declare(strict_types=1);

use QtBuilder\Commands\BuildModulesCommand;
use QtBuilder\Tests\Support\FakeExtensionBootstrapper;
use QtBuilder\Tests\Support\FakeSystemInformation;

it('builds split module trees with auto-added static manifest dependencies', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-modules-' . bin2hex(random_bytes(4));
    $sharedRoot = $buildRoot . '/ext';
    $sharedClassesDir = $sharedRoot . '/classes';
    $qtCoreRoot = $buildRoot . '/QtCore';
    $qtGuiRoot = $buildRoot . '/QtGui';
    $qtWidgetsRoot = $buildRoot . '/QtWidgets';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildModulesCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtWidgets',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and(is_file($buildRoot . '/generated/module_graph.json'))->toBeTrue()
        ->and(is_file($buildRoot . '/generated/runtime_manifest.json'))->toBeTrue()
        ->and(is_file($qtCoreRoot . '/generated/module_abi.json'))->toBeTrue()
        ->and(is_file($qtGuiRoot . '/generated/module_abi.json'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/generated/module_abi.json'))->toBeTrue()
        ->and(is_file($qtCoreRoot . '/ext/classes/qt_buildinfo.cpp'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qobject.h'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_buildinfo.h'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qwidget.h'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qshortcutcarrier.h'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qstandarditemmodel.h'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qicon.h'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_qkeysequence.h'))->toBeTrue()
        ->and(is_file($qtCoreRoot . '/ext/classes/qt_qmetaobjectconnection.h'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qwidget.cpp'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qshortcutcarrier.cpp'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qexternalwidget.cpp'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qmetaobjectconnection.h'))->toBeFalse()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_buildinfo.cpp'))->toBeFalse()
        ->and($result['display'])->toContain(
            'Requested modules: QtWidgets',
            'Auto-added dependency modules: QtCore, QtGui',
            'Expanded modules: QtCore, QtGui, QtWidgets',
            'Building QtCore as qtcore...',
            'Building QtGui as qtgui...',
            'Building QtWidgets as qtwidgets...',
            'Extension load order: qtcore, qtgui, qtwidgets',
        );

    expect(array_map(static fn($context): string => $context->extensionName, $bootstrapper->contexts))
        ->toBe(['qtcore', 'qtgui', 'qtwidgets']);

    $qtWidgetsConfig = (string) file_get_contents($qtWidgetsRoot . '/ext/config.m4');
    expect($qtWidgetsConfig)->toContain(
        'PHP_ADD_INCLUDE([' . $sharedRoot . '])',
        'PHP_ADD_INCLUDE([' . $sharedClassesDir . '])',
    );

    $qtWidgetsManifest = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/module_abi.json'));
    expect($qtWidgetsManifest['module'])->toBe('QtWidgets')
        ->and($qtWidgetsManifest['extension_name'])->toBe('qtwidgets')
        ->and($qtWidgetsManifest['classes'])->toBe(['QExternalWidget', 'QShortcutCarrier', 'QWidget'])
        ->and($qtWidgetsManifest['dependency_modules'])->toBe(['QtCore', 'QtGui'])
        ->and($qtWidgetsManifest['build_mode'])->toBe('modular')
        ->and($qtWidgetsManifest['qt_version'])->toBe('6.7.1')
        ->and($qtWidgetsManifest['extension_version'])->toBe('0.1.0')
        ->and($qtWidgetsManifest['builder_abi_version'])->toBe('phpqt-builder-abi-v1')
        ->and($qtWidgetsManifest['class_count'])->toBe(3)
        ->and($qtWidgetsManifest['namespaces'])->toBe(['Qt\\Widgets'])
        ->and($qtWidgetsManifest['shared_include_dirs'])->toBe([$sharedRoot, $sharedClassesDir])
        ->and($qtWidgetsManifest['includes_signal_connection_support'])->toBeFalse();

    $runtimeManifest = qt_decode_json((string) file_get_contents($buildRoot . '/generated/runtime_manifest.json'));
    expect($runtimeManifest['build_mode'])->toBe('modular')
        ->and($runtimeManifest['qt_version'])->toBe('6.7.1')
        ->and($runtimeManifest['requested_modules'])->toBe(['QtWidgets'])
        ->and($runtimeManifest['expanded_modules'])->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($runtimeManifest['dependency_source'])->toBe('static_manifest')
        ->and($runtimeManifest['built_modules'])->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($runtimeManifest['modules']['QtCore']['extension_name'] ?? null)->toBe('qtcore')
        ->and($runtimeManifest['modules']['QtWidgets']['dependencies'] ?? null)->toBe(['QtCore', 'QtGui']);

    $qtWidgetsSource = (string) file_get_contents($qtWidgetsRoot . '/ext/qtwidgets.cpp');
    expect($qtWidgetsSource)->toContain(
        '#include "qt_buildinfo.h"',
        'qt_buildinfo_register_module("QtWidgets", "qtwidgets", "6.7.1", "phpqt-builder-abi-v1")',
        'php_info_print_table_row(2, "current module", "QtWidgets");',
        'php_info_print_table_row(2, "dependency modules", "QtCore, QtGui");',
        'php_info_print_table_row(2, "loaded modules", ZSTR_VAL(qt_buildinfo_loaded_modules));',
    );

    $qtCoreSource = (string) file_get_contents($buildRoot . '/QtCore/ext/qtcore.cpp');
    expect($qtCoreSource)->toContain(
        'php_info_print_table_row(2, "current module", "QtCore");',
        'php_info_print_table_row(2, "dependency modules", "-");',
        'php_info_print_table_row(2, "built modules", ZSTR_VAL(qt_buildinfo_built_modules));',
        'php_info_print_table_row(2, "loaded modules", ZSTR_VAL(qt_buildinfo_loaded_modules));',
    );

    $buildInfoStub = (string) file_get_contents($qtCoreRoot . '/ext/classes/qt_buildinfo.stub.php');
    expect($buildInfoStub)->toContain(
        "public const string MODE_MONOLITHIC = 'monolithic';",
        "public const string MODE_MODULAR = 'modular';",
    );

    $qtWidgetsSummary = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/build_summary.json'));
    expect($qtWidgetsSummary['requested_modules'] ?? null)->toBe(['QtWidgets'])
        ->and($qtWidgetsSummary['expanded_modules'] ?? null)->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($qtWidgetsSummary['dependency_source'] ?? null)->toBe('static_manifest')
        ->and($qtWidgetsSummary['generated_classes'])->toBe(3)
        ->and($qtWidgetsSummary['skipped_classes'])->toBe(0)
        ->and($qtWidgetsSummary['bootstrap_disabled'] ?? false)->toBeFalse();

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
    expect($graph['requested_modules'])->toBe(['QtWidgets'])
        ->and($graph['expanded_modules'])->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($graph['dependency_source'])->toBe('static_manifest')
        ->and($graph['build_order'])->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($graph['dependencies']['QtWidgets'] ?? null)->toBe(['QtCore', 'QtGui']);
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
            'modules' => 'QtGui,QtWidgets',
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
        ->and($result['display'])->toContain(
            'Requested modules: QtGui, QtWidgets',
            'Auto-added dependency modules: QtCore',
            'Expanded modules: QtCore, QtGui, QtWidgets',
            'Extension load order: qtcore, qtgui, qtwidgets',
        );

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

    $qtWidgetsSource = (string) file_get_contents($qtWidgetsRoot . '/ext/qtwidgets.cpp');
    expect($qtWidgetsSource)->toContain(
        'php_info_print_table_row(2, "current module", "QtWidgets");',
        'php_info_print_table_row(2, "dependency modules", "QtCore, QtGui");',
        'php_info_print_table_row(2, "loaded modules", ZSTR_VAL(qt_buildinfo_loaded_modules));',
    );

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
                'modules' => 'QtWidgets',
                '--output' => 'build',
                '--jobs' => '2',
            ],
        );

        $sharedRoot = $resolvedWorkspace . '/build/ext';
        $sharedClassesDir = $sharedRoot . '/classes';
        $qtCoreRoot = $resolvedWorkspace . '/build/QtCore';
        $qtWidgetsRoot = $resolvedWorkspace . '/build/QtWidgets';

        expect($result)->toBeSuccessfulCommandResult()
            ->and(is_file($resolvedWorkspace . '/build/QtGui/generated/module_abi.json'))->toBeTrue()
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
            'modules' => 'QtWidgets',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and($bootstrapper->contexts)->toHaveCount(0)
        ->and(is_file($qtCoreRoot . '/ext/config.m4'))->toBeTrue()
        ->and(is_file($buildRoot . '/QtGui/ext/config.m4'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/config.m4'))->toBeTrue()
        ->and(is_file($qtWidgetsRoot . '/ext/classes/qt_qwidget.cpp'))->toBeTrue()
        ->and(is_file($qtCoreRoot . '/ext/configure'))->toBeFalse()
        ->and(is_file($buildRoot . '/QtGui/ext/configure'))->toBeFalse()
        ->and(is_file($qtWidgetsRoot . '/ext/configure'))->toBeFalse()
        ->and(is_file($qtCoreRoot . '/ext/Makefile'))->toBeFalse()
        ->and(is_file($buildRoot . '/QtGui/ext/Makefile'))->toBeFalse()
        ->and(is_file($qtWidgetsRoot . '/ext/Makefile'))->toBeFalse()
        ->and(is_file($qtCoreRoot . '/ext/build/gen_stub.php'))->toBeFalse()
        ->and(is_file($buildRoot . '/QtGui/ext/build/gen_stub.php'))->toBeFalse()
        ->and(is_file($qtWidgetsRoot . '/ext/build/gen_stub.php'))->toBeFalse()
        ->and($result['display'])->toContain('Skipping bootstrap (--no-build).');

    $qtCoreSummary = qt_decode_json((string) file_get_contents($qtCoreRoot . '/generated/build_summary.json'));
    $qtGuiSummary = qt_decode_json((string) file_get_contents($buildRoot . '/QtGui/generated/build_summary.json'));
    $qtWidgetsSummary = qt_decode_json((string) file_get_contents($qtWidgetsRoot . '/generated/build_summary.json'));
    expect($qtCoreSummary['bootstrap_disabled'] ?? null)->toBeTrue()
        ->and($qtCoreSummary['bootstrap'])->toBeNull()
        ->and($qtGuiSummary['bootstrap_disabled'] ?? null)->toBeTrue()
        ->and($qtGuiSummary['bootstrap'])->toBeNull()
        ->and($qtWidgetsSummary['bootstrap_disabled'] ?? null)->toBeTrue()
        ->and($qtWidgetsSummary['bootstrap'])->toBeNull();
});

it('generates enum holder classes into their owning split modules', function (): void {
    $fixtureRoot = qt_fixture_path('enum-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-modules-enums-' . bin2hex(random_bytes(4));
    $qtCoreRoot = $buildRoot . '/QtCore';
    $qtSqlRoot = $buildRoot . '/QtSql';
    $sharedClassesDir = $buildRoot . '/ext/classes';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildModulesCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore,QtSql',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and(is_file($qtCoreRoot . '/ext/classes/qt_enum_qt_connection_type.stub.php'))->toBeTrue()
        ->and(is_file($qtCoreRoot . '/ext/classes/qt_enum_qt_core_q_connection_carrier_mode.stub.php'))->toBeTrue()
        ->and(is_file($qtSqlRoot . '/ext/classes/qt_enum_qt_sql_q_sql_param_type.stub.php'))->toBeTrue()
        ->and(is_file($qtSqlRoot . '/ext/classes/qt_enum_qt_sql_q_sql_table_type.stub.php'))->toBeTrue()
        ->and(is_file($buildRoot . '/generated/enum_candidate_headers.json'))->toBeTrue()
        ->and(is_file($sharedClassesDir . '/qt_enum_qt_connection_type.h'))->toBeFalse()
        ->and(is_file($sharedClassesDir . '/qt_enum_qt_sql_q_sql_param_type.h'))->toBeFalse();

    $qtCoreStub = (string) file_get_contents($qtCoreRoot . '/ext/classes/qt_enum_qt_connection_types.stub.php');
    expect($qtCoreStub)->toContain('namespace Qt;', 'final class ConnectionTypes');

    $qtSqlStub = (string) file_get_contents($qtSqlRoot . '/ext/classes/qt_enum_qt_sql_q_sql_param_type_flag.stub.php');
    expect($qtSqlStub)->toContain('namespace Qt\\Sql\\QSql;', 'final class ParamTypeFlag');

    $qtSqlManifest = qt_decode_json((string) file_get_contents($qtSqlRoot . '/generated/module_abi.json'));
    expect($qtSqlManifest['module'])->toBe('QtSql')
        ->and($qtSqlManifest['dependency_modules'])->toBe(['QtCore'])
        ->and($qtSqlManifest['classes'])->toBe(['QSqlQueryLike']);

    $enumCandidateHeaders = qt_decode_json((string) file_get_contents($buildRoot . '/generated/enum_candidate_headers.json'));
    expect($enumCandidateHeaders)->toContainEqual([
        'header' => $fixtureRoot . '/include/QtCore/qnamespace.h',
        'module' => 'QtCore',
        'types' => ['Qt::ConnectionType', 'Qt::ConnectionTypes'],
    ]);
});

it('builds unmapped split modules with a manifest warning', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-modules-unmapped-' . bin2hex(random_bytes(4));
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildModulesCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtSvg',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and($result['display'])->toContain(
            'Requested modules: QtSvg',
            'Auto-added dependency modules: QtCore',
            'Manifest warning: QtSvg has no static dependency manifest entry; only the implicit QtCore dependency will be applied for that module.',
            'Expanded modules: QtCore, QtSvg',
            'Building QtCore as qtcore...',
            'Building QtSvg as qtsvg...',
            'Extension load order: qtcore, qtsvg',
        );
    expect($bootstrapper->contexts)->toHaveCount(2)
        ->and(array_map(static fn($context): string => $context->extensionName, $bootstrapper->contexts))->toBe(['qtcore', 'qtsvg'])
        ->and(is_file($buildRoot . '/QtCore/generated/module_abi.json'))->toBeTrue()
        ->and(is_file($buildRoot . '/QtSvg/generated/module_abi.json'))->toBeTrue()
        ->and(is_file($buildRoot . '/generated/module_graph.json'))->toBeTrue();

    $manifest = qt_decode_json((string) file_get_contents($buildRoot . '/QtSvg/generated/module_abi.json'));
    expect($manifest['module'])->toBe('QtSvg')
        ->and($manifest['extension_name'])->toBe('qtsvg')
        ->and($manifest['dependency_modules'])->toBe(['QtCore'])
        ->and($manifest['classes'])->toBe(['QSvgPoint']);
});
