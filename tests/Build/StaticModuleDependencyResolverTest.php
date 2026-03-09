<?php

declare(strict_types=1);

use QtBuilder\Build\Dependencies\ResolvedModuleGraph;
use QtBuilder\Build\Dependencies\StaticModuleDependencyResolver;

it('loads supported modules from the static manifest', function (): void {
    $resolver = new StaticModuleDependencyResolver();

    expect($resolver->supportedModules())->toBe([
        'QtCore',
        'QtGui',
        'QtWidgets',
        'QtNetwork',
        'QtBluetooth',
        'QtSql',
        'QtPrintSupport',
        'QtMultimedia',
        'QtOpenGL',
        'QtOpenGLWidgets',
        'Qt3DCore',
        'Qt3DRender',
        'Qt3DInput',
        'Qt3DExtras',
        'QtQml',
        'QtQuick',
        'QtQuick3D',
        'QtWebSockets',
        'QtWebChannel',
        'QtWebEngineCore',
        'QtWebEngineQuick',
    ]);
});

it('expands qtbluetooth dependencies in topological order', function (): void {
    $resolver = new StaticModuleDependencyResolver();

    $graph = $resolver->resolve(['QtBluetooth']);

    expect($graph)->toBeInstanceOf(ResolvedModuleGraph::class)
        ->and($graph->requestedModules)->toBe(['QtBluetooth'])
        ->and($graph->expandedModules())->toBe(['QtCore', 'QtNetwork', 'QtBluetooth'])
        ->and($graph->autoAddedModules())->toBe(['QtCore', 'QtNetwork'])
        ->and($graph->dependenciesFor('QtBluetooth'))->toBe(['QtCore', 'QtNetwork'])
        ->and($graph->extensionNameFor('QtBluetooth'))->toBe('qtbluetooth')
        ->and($graph->dependencySource)->toBe('static_manifest');
});

it('expands transitive dependencies with a stable topological order', function (): void {
    $resolver = new StaticModuleDependencyResolver();

    $graph = $resolver->resolve(['QtQuick3D']);

    expect($graph)->toBeInstanceOf(ResolvedModuleGraph::class)
        ->and($graph->requestedModules)->toBe(['QtQuick3D'])
        ->and($graph->expandedModules())->toBe(['QtCore', 'QtGui', 'QtNetwork', 'QtQml', 'QtQuick', 'QtQuick3D'])
        ->and($graph->autoAddedModules())->toBe(['QtCore', 'QtGui', 'QtNetwork', 'QtQml', 'QtQuick'])
        ->and($graph->dependenciesFor('QtQuick3D'))->toBe(['QtCore', 'QtGui', 'QtQml', 'QtQuick'])
        ->and($graph->extensionNameFor('QtQuick3D'))->toBe('qtquick3d')
        ->and($graph->dependencySource)->toBe('static_manifest');
});

it('expands qt3d extras dependencies in topological order', function (): void {
    $resolver = new StaticModuleDependencyResolver();

    $graph = $resolver->resolve(['Qt3DExtras']);

    expect($graph)->toBeInstanceOf(ResolvedModuleGraph::class)
        ->and($graph->requestedModules)->toBe(['Qt3DExtras'])
        ->and($graph->expandedModules())->toBe(['QtCore', 'QtGui', 'Qt3DCore', 'Qt3DRender', 'Qt3DInput', 'Qt3DExtras'])
        ->and($graph->autoAddedModules())->toBe(['QtCore', 'QtGui', 'Qt3DCore', 'Qt3DRender', 'Qt3DInput'])
        ->and($graph->dependenciesFor('Qt3DExtras'))->toBe(['QtCore', 'QtGui', 'Qt3DCore', 'Qt3DRender', 'Qt3DInput'])
        ->and($graph->extensionNameFor('Qt3DExtras'))->toBe('qt3dextras')
        ->and($graph->dependencySource)->toBe('static_manifest');
});

it('normalizes duplicate and blank requested modules before resolving', function (): void {
    $resolver = new StaticModuleDependencyResolver();

    $graph = $resolver->resolve(['QtGui', ' QtGui ', '', 'QtCore']);

    expect($graph->requestedModules)->toBe(['QtGui', 'QtCore'])
        ->and($graph->expandedModules())->toBe(['QtCore', 'QtGui']);
});

it('defaults to QtCore when no modules are requested', function (): void {
    $resolver = new StaticModuleDependencyResolver();

    $graph = $resolver->resolve([]);

    expect($graph->requestedModules)->toBe(['QtCore'])
        ->and($graph->buildOrder)->toBe(['QtCore']);
});

it('passes through unmapped requested modules with a warning-ready graph', function (): void {
    $resolver = new StaticModuleDependencyResolver();

    $graph = $resolver->resolve(['QtBogus', 'QtQuick3D']);

    expect($graph->requestedModules)->toBe(['QtBogus', 'QtQuick3D'])
        ->and($graph->unmappedModules)->toBe(['QtBogus'])
        ->and($graph->expandedModules())->toBe(['QtCore', 'QtGui', 'QtNetwork', 'QtQml', 'QtQuick', 'QtQuick3D', 'QtBogus'])
        ->and($graph->autoAddedModules())->toBe(['QtCore', 'QtGui', 'QtNetwork', 'QtQml', 'QtQuick'])
        ->and($graph->dependenciesFor('QtBogus'))->toBe(['QtCore'])
        ->and($graph->extensionNameFor('QtBogus'))->toBe('qtbogus');
});

it('rejects manifest entries that reference unknown dependencies', function (): void {
    $manifestPath = qt_temp_dir('qtbuilder-static-manifest-bad-dependency-') . '/manifest.json';
    file_put_contents($manifestPath, json_encode([
        'schema_version' => 1,
        'qt_major' => 6,
        'modules' => [
            'QtCore' => [
                'extension_name' => 'qtcore',
                'dependencies' => [],
            ],
            'QtGui' => [
                'extension_name' => 'qtgui',
                'dependencies' => ['QtCore', 'QtPhantom'],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $resolver = new StaticModuleDependencyResolver($manifestPath);

    expect(fn () => $resolver->resolve(['QtGui']))
        ->toThrow(
            RuntimeException::class,
            sprintf('Static module dependency manifest entry QtGui references unknown dependency QtPhantom.'),
        );
});

it('rejects cycles in the static manifest', function (): void {
    $manifestPath = qt_temp_dir('qtbuilder-static-manifest-cycle-') . '/manifest.json';
    file_put_contents($manifestPath, json_encode([
        'schema_version' => 1,
        'qt_major' => 6,
        'modules' => [
            'QtCore' => [
                'extension_name' => 'qtcore',
                'dependencies' => ['QtGui'],
            ],
            'QtGui' => [
                'extension_name' => 'qtgui',
                'dependencies' => ['QtCore'],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $resolver = new StaticModuleDependencyResolver($manifestPath);

    expect(fn () => $resolver->resolve(['QtGui']))
        ->toThrow(RuntimeException::class, 'Module dependency cycle detected in static manifest:');
});
