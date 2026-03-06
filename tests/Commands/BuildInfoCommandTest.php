<?php

declare(strict_types=1);

use QtBuilder\Build\RuntimeManifest;
use QtBuilder\Build\RuntimeModuleMetadata;
use QtBuilder\Commands\BuildInfoCommand;

it('prints a table summary for the runtime manifest', function (): void {
    $buildRoot = qt_temp_dir('qtbuilder-build-info-');
    $manifestPath = $buildRoot . '/generated/runtime_manifest.json';
    $manifest = new RuntimeManifest(
        buildMode: RuntimeManifest::MODE_MODULAR,
        qtVersion: '6.7.1',
        qtVersionMajor: 6,
        qtVersionMinor: 7,
        qtVersionPatch: 1,
        extensionVersion: '0.1.0',
        builderAbiVersion: RuntimeManifest::BUILDER_ABI_VERSION,
        builtModules: ['QtCore', 'QtGui', 'QtWidgets'],
        buildOrder: ['QtCore', 'QtGui', 'QtWidgets'],
        modules: [
            'QtCore' => new RuntimeModuleMetadata(
                module: 'QtCore',
                extensionName: 'qtcore',
                dependencies: [],
                namespaces: ['Qt\\Core'],
                classCount: 182,
                includesSignalConnectionSupport: true,
            ),
            'QtGui' => new RuntimeModuleMetadata(
                module: 'QtGui',
                extensionName: 'qtgui',
                dependencies: ['QtCore'],
                namespaces: ['Qt\\Gui'],
                classCount: 195,
                includesSignalConnectionSupport: false,
            ),
            'QtWidgets' => new RuntimeModuleMetadata(
                module: 'QtWidgets',
                extensionName: 'qtwidgets',
                dependencies: ['QtCore', 'QtGui'],
                namespaces: ['Qt\\Widgets'],
                classCount: 191,
                includesSignalConnectionSupport: false,
            ),
        ],
    );
    $manifest->write($manifestPath);

    $result = qt_command_result(new BuildInfoCommand(), [
        '--build-root' => $buildRoot,
    ]);

    expect($result)->toBeSuccessfulCommandResult();
    expect($result['display'])->toContain(
        'Build mode: modular',
        'Qt version: 6.7.1',
        'Extension version: 0.1.0',
        'Builder ABI: phpqt-builder-abi-v1',
        'Build order: QtCore, QtGui, QtWidgets',
        'QtCore',
        'qtcore',
        'QtWidgets',
        'qtwidgets',
        'QtCore, QtGui',
        '191',
        'Qt\\Widgets',
    );
});

it('prints a single module summary when --module is provided', function (): void {
    $buildRoot = qt_temp_dir('qtbuilder-build-info-module-');
    $manifestPath = $buildRoot . '/generated/runtime_manifest.json';
    $manifest = new RuntimeManifest(
        buildMode: RuntimeManifest::MODE_MODULAR,
        qtVersion: '6.7.1',
        qtVersionMajor: 6,
        qtVersionMinor: 7,
        qtVersionPatch: 1,
        extensionVersion: '0.1.0',
        builderAbiVersion: RuntimeManifest::BUILDER_ABI_VERSION,
        builtModules: ['QtCore', 'QtGui', 'QtWidgets'],
        buildOrder: ['QtCore', 'QtGui', 'QtWidgets'],
        modules: [
            'QtCore' => new RuntimeModuleMetadata(
                module: 'QtCore',
                extensionName: 'qtcore',
                dependencies: [],
                namespaces: ['Qt\\Core'],
                classCount: 182,
                includesSignalConnectionSupport: true,
            ),
            'QtGui' => new RuntimeModuleMetadata(
                module: 'QtGui',
                extensionName: 'qtgui',
                dependencies: ['QtCore'],
                namespaces: ['Qt\\Gui'],
                classCount: 195,
                includesSignalConnectionSupport: false,
            ),
            'QtWidgets' => new RuntimeModuleMetadata(
                module: 'QtWidgets',
                extensionName: 'qtwidgets',
                dependencies: ['QtCore', 'QtGui'],
                namespaces: ['Qt\\Widgets'],
                classCount: 191,
                includesSignalConnectionSupport: false,
            ),
        ],
    );
    $manifest->write($manifestPath);

    $result = qt_command_result(new BuildInfoCommand(), [
        '--build-root' => $buildRoot,
        '--module' => 'QtWidgets',
    ]);

    expect($result)->toBeSuccessfulCommandResult();
    expect($result['display'])->toContain(
        'Build mode: modular',
        'Module: QtWidgets',
        'qtwidgets',
        'QtCore, QtGui',
        '191',
        'Qt\\Widgets',
    )->not->toContain('qtcore');
});

it('prints the manifest json unchanged when --format=json is used', function (): void {
    $buildRoot = qt_temp_dir('qtbuilder-build-info-json-');
    $manifestPath = $buildRoot . '/generated/runtime_manifest.json';
    $rawManifest = <<<'JSON'
{
    "schema_version": 1,
    "build_mode": "monolithic",
    "qt_version": "6.7.1",
    "qt_version_major": 6,
    "qt_version_minor": 7,
    "qt_version_patch": 1,
    "extension_version": "0.1.0",
    "builder_abi_version": "phpqt-builder-abi-v1",
    "built_modules": [
        "QtCore",
        "QtGui"
    ],
    "build_order": [
        "QtCore",
        "QtGui"
    ],
    "modules": {
        "QtCore": {
            "module": "QtCore",
            "extension_name": "qt",
            "dependencies": [],
            "namespaces": [
                "Qt\\Core"
            ],
            "class_count": 182,
            "includes_signal_connection_support": true
        },
        "QtGui": {
            "module": "QtGui",
            "extension_name": "qt",
            "dependencies": [
                "QtCore"
            ],
            "namespaces": [
                "Qt\\Gui"
            ],
            "class_count": 195,
            "includes_signal_connection_support": false
        }
    }
}
JSON;
    mkdir(dirname($manifestPath), 0777, true);
    file_put_contents($manifestPath, $rawManifest . PHP_EOL);

    $result = qt_command_result(new BuildInfoCommand(), [
        '--build-root' => $buildRoot,
        '--format' => 'json',
    ]);

    expect($result)->toBeSuccessfulCommandResult();
    expect($result['display'])->toBe($rawManifest . PHP_EOL);
});

it('fails with an actionable error when the runtime manifest is missing', function (): void {
    $buildRoot = qt_temp_dir('qtbuilder-build-info-missing-');

    $result = qt_command_result(new BuildInfoCommand(), [
        '--build-root' => $buildRoot,
    ]);

    expect($result)->toBeFailureCommandResult();
    expect($result['display'])->toContain(
        'Runtime manifest not found at',
        'Run `php qtb build` or `php qtb build:modules` first.',
    );
});
