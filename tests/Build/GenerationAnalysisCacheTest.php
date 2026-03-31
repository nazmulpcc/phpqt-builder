<?php

declare(strict_types=1);

use QtBuilder\Build\BuildExecutionRequest;
use QtBuilder\Build\Dependencies\ResolvedModuleGraph;
use QtBuilder\Build\EnumHolderConstant;
use QtBuilder\Build\EnumHolderDefinition;
use QtBuilder\Build\EnumHolderRegistry;
use QtBuilder\Build\GenerationAnalysisCache;
use QtBuilder\Build\ImportedModuleAbi;
use QtBuilder\Build\ModuleAbiManifest;
use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpClassConstant;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Definition\PhpProperty;
use QtBuilder\Qt\QtInstallation;
use QtBuilder\Scanning\HeaderCandidate;

it('round-trips generation analysis payloads through the replay cache', function (): void {
    $cache = new GenerationAnalysisCache();
    $metadataDir = qt_temp_dir('qtbuilder-generation-cache-');
    $request = qt_generation_cache_request();
    $acceptedCandidates = qt_generation_cache_candidates();
    $preparedClassData = qt_generation_cache_prepared_class_data();
    $classNamespaces = ['QPoint' => 'Qt\\Core'];
    $enumRegistry = qt_generation_cache_enum_registry();
    $generation = qt_generation_cache_generation($acceptedCandidates);

    $path = $cache->write(
        $metadataDir,
        $request,
        $acceptedCandidates,
        [],
        $preparedClassData,
        $classNamespaces,
        $enumRegistry,
        $generation,
    );

    expect(is_file($path))->toBeTrue();

    $loaded = $cache->load(
        $metadataDir,
        $request,
        $acceptedCandidates,
        [],
        $preparedClassData,
        $classNamespaces,
        $enumRegistry,
    );

    expect($loaded)->not->toBeNull()
        ->and($loaded['generated_classes'])->toBe(['QPoint', 'QPointList'])
        ->and($loaded['passes'])->toBe(1)
        ->and($loaded['requires_signal_connection_support'])->toBeTrue()
        ->and($loaded['synthetic_class_namespaces'])->toBe(['QPointList' => 'Qt\\Core'])
        ->and($loaded['generated_php_classes']['QPoint']->toArray())->toBe($generation['generated_php_classes']['QPoint']->toArray())
        ->and($loaded['generated_php_classes']['QPointList']->toArray())->toBe($generation['generated_php_classes']['QPointList']->toArray());
});

it('invalidates when prepared class data changes', function (): void {
    $cache = new GenerationAnalysisCache();
    $metadataDir = qt_temp_dir('qtbuilder-generation-cache-');
    $request = qt_generation_cache_request();
    $acceptedCandidates = qt_generation_cache_candidates();
    $preparedClassData = qt_generation_cache_prepared_class_data();
    $classNamespaces = ['QPoint' => 'Qt\\Core'];
    $enumRegistry = qt_generation_cache_enum_registry();

    $cache->write(
        $metadataDir,
        $request,
        $acceptedCandidates,
        [],
        $preparedClassData,
        $classNamespaces,
        $enumRegistry,
        qt_generation_cache_generation($acceptedCandidates),
    );

    $preparedClassData['QPoint']['methods'][] = [
        'name' => 'y',
        'return_type' => 'int',
        'parameters' => [],
    ];

    expect($cache->load(
        $metadataDir,
        $request,
        $acceptedCandidates,
        [],
        $preparedClassData,
        $classNamespaces,
        $enumRegistry,
    ))->toBeNull();
});

it('invalidates when enum holders change', function (): void {
    $cache = new GenerationAnalysisCache();
    $metadataDir = qt_temp_dir('qtbuilder-generation-cache-');
    $request = qt_generation_cache_request();
    $acceptedCandidates = qt_generation_cache_candidates();
    $preparedClassData = qt_generation_cache_prepared_class_data();
    $classNamespaces = ['QPoint' => 'Qt\\Core'];
    $enumRegistry = qt_generation_cache_enum_registry();

    $cache->write(
        $metadataDir,
        $request,
        $acceptedCandidates,
        [],
        $preparedClassData,
        $classNamespaces,
        $enumRegistry,
        qt_generation_cache_generation($acceptedCandidates),
    );

    $changedRegistry = new EnumHolderRegistry([
        'Qt::Orientation' => new EnumHolderDefinition(
            module: 'QtCore',
            cppType: 'Qt::Orientation',
            phpNamespace: 'Qt\\Core',
            phpClassName: 'Orientation',
            constants: [new EnumHolderConstant('Horizontal', 1)],
            headerPath: '/tmp/qnamespace.h',
        ),
        'Qt::AlignmentFlag' => new EnumHolderDefinition(
            module: 'QtCore',
            cppType: 'Qt::AlignmentFlag',
            phpNamespace: 'Qt\\Core',
            phpClassName: 'AlignmentFlag',
            constants: [new EnumHolderConstant('AlignLeft', 1)],
            headerPath: '/tmp/qnamespace.h',
        ),
    ]);

    expect($cache->load(
        $metadataDir,
        $request,
        $acceptedCandidates,
        [],
        $preparedClassData,
        $classNamespaces,
        $changedRegistry,
    ))->toBeNull();
});

it('invalidates when imported abi changes', function (): void {
    $cache = new GenerationAnalysisCache();
    $metadataDir = qt_temp_dir('qtbuilder-generation-cache-');
    $acceptedCandidates = qt_generation_cache_candidates();
    $preparedClassData = qt_generation_cache_prepared_class_data();
    $classNamespaces = ['QPoint' => 'Qt\\Core'];
    $enumRegistry = qt_generation_cache_enum_registry();
    $request = qt_generation_cache_request();

    $cache->write(
        $metadataDir,
        $request,
        $acceptedCandidates,
        [],
        $preparedClassData,
        $classNamespaces,
        $enumRegistry,
        qt_generation_cache_generation($acceptedCandidates),
    );

    $requestWithAbi = qt_generation_cache_request(qt_generation_cache_imported_abi());

    expect($cache->load(
        $metadataDir,
        $requestWithAbi,
        $acceptedCandidates,
        [],
        $preparedClassData,
        $classNamespaces,
        $enumRegistry,
    ))->toBeNull();
});

it('invalidates when module graph changes', function (): void {
    $cache = new GenerationAnalysisCache();
    $metadataDir = qt_temp_dir('qtbuilder-generation-cache-');
    $request = qt_generation_cache_request();
    $acceptedCandidates = qt_generation_cache_candidates();
    $preparedClassData = qt_generation_cache_prepared_class_data();
    $classNamespaces = ['QPoint' => 'Qt\\Core'];
    $enumRegistry = qt_generation_cache_enum_registry();

    $cache->write(
        $metadataDir,
        $request,
        $acceptedCandidates,
        [],
        $preparedClassData,
        $classNamespaces,
        $enumRegistry,
        qt_generation_cache_generation($acceptedCandidates),
    );

    $changedRequest = qt_generation_cache_request(
        importedAbi: null,
        modules: ['QtCore', 'QtGui'],
        graph: new ResolvedModuleGraph(
            requestedModules: ['QtCore'],
            dependencies: ['QtCore' => [], 'QtGui' => ['QtCore']],
            buildOrder: ['QtCore', 'QtGui'],
            extensionNames: ['QtCore' => 'qt', 'QtGui' => 'qt'],
            dependencySource: 'static_manifest',
        ),
    );

    expect($cache->load(
        $metadataDir,
        $changedRequest,
        $acceptedCandidates,
        [],
        $preparedClassData,
        $classNamespaces,
        $enumRegistry,
    ))->toBeNull();
});

function qt_generation_cache_request(?ImportedModuleAbi $importedAbi = null, array $modules = ['QtCore'], ?ResolvedModuleGraph $graph = null): BuildExecutionRequest
{
    $installation = new QtInstallation(
        rootPath: '/tmp/qt',
        osFamily: 'Linux',
        includeRoots: ['/tmp/qt/include'],
        libraryRoots: ['/tmp/qt/lib'],
        moduleHeaderRoots: ['QtCore' => '/tmp/qt/include/QtCore'],
    );

    $graph ??= new ResolvedModuleGraph(
        requestedModules: $modules,
        dependencies: ['QtCore' => []],
        buildOrder: $modules,
        extensionNames: array_fill_keys($modules, 'qt'),
        dependencySource: 'static_manifest',
    );

    return new BuildExecutionRequest(
        installation: $installation,
        buildRootDir: '/tmp/build-root',
        outputDir: '/tmp/build-root/ext',
        modules: $modules,
        requestedModules: $modules,
        extensionName: 'qt',
        extensionVersion: '0.1.0',
        jobs: 1,
        resolvedModuleGraph: $graph,
        importedAbi: $importedAbi,
    );
}

/**
 * @return list<HeaderCandidate>
 */
function qt_generation_cache_candidates(): array
{
    return [
        new HeaderCandidate('QtCore', 'QPoint', '/tmp/qt/include/QtCore/QPoint', '/tmp/qt/include/QtCore/QPoint'),
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function qt_generation_cache_prepared_class_data(): array
{
    return [
        'QPoint' => [
            'name' => 'QPoint',
            'qualified_name' => 'QPoint',
            'module' => 'QtCore',
            'bases' => [],
            'methods' => [[
                'name' => 'x',
                'return_type' => 'int',
                'parameters' => [],
            ]],
            'properties' => [],
            'enum_names' => [],
            'flag_aliases' => [],
        ],
    ];
}

function qt_generation_cache_enum_registry(): EnumHolderRegistry
{
    return new EnumHolderRegistry([
        'Qt::Orientation' => new EnumHolderDefinition(
            module: 'QtCore',
            cppType: 'Qt::Orientation',
            phpNamespace: 'Qt\\Core',
            phpClassName: 'Orientation',
            constants: [new EnumHolderConstant('Horizontal', 1)],
            headerPath: '/tmp/qnamespace.h',
        ),
    ]);
}

/**
 * @param list<HeaderCandidate> $acceptedCandidates
 * @return array<string, mixed>
 */
function qt_generation_cache_generation(array $acceptedCandidates): array
{
    $pointMethod = new PhpMethod(
        name: 'x',
        access: 'public',
        isStatic: false,
        isSignal: false,
        isSlot: false,
        isAbstractMethod: false,
        returnType: 'int',
        parameters: [new PhpParameter('scale', 'int', false, 0)],
        overloads: [new MethodOverload(
            declaringClass: 'QPoint',
            returnType: 'int',
            smartPointerReturnTargetCppType: null,
            parameters: [new OverloadParameter('scale', 'int', false)],
            access: 'public',
            isConst: true,
            isStatic: false,
            isVirtual: false,
            isPureVirtual: false,
        )],
        cppName: 'x',
    );

    $signalMethod = new PhpMethod(
        name: 'changed',
        access: 'public',
        isStatic: false,
        isSignal: true,
        isSlot: false,
        isAbstractMethod: false,
        returnType: 'void',
        parameters: [new PhpParameter('value', 'int', false, 0)],
        overloads: [new MethodOverload(
            declaringClass: 'QPoint',
            returnType: 'void',
            smartPointerReturnTargetCppType: null,
            parameters: [new OverloadParameter('value', 'int', false)],
            access: 'public',
            isConst: false,
            isStatic: false,
            isVirtual: false,
            isPureVirtual: false,
        )],
        cppName: 'changed',
    );

    return [
        'accepted_candidates' => $acceptedCandidates,
        'generated_classes' => ['QPoint', 'QPointList'],
        'generated_php_classes' => [
            'QPoint' => new PhpClass(
                name: 'QPoint',
                parent: null,
                isAbstract: false,
                isCopyConstructible: true,
                hasPublicConstructor: true,
                hasPublicDestructor: true,
                properties: [new PhpProperty('x', 'int', 'int', 'public', false)],
                methods: [$pointMethod],
                signals: [$signalMethod],
                isQObjectDerived: true,
                classConstants: [new PhpClassConstant('Origin', 0, 'PointKind')],
                nativeIncludes: ['QtCore/QPoint'],
                nativeAliasOf: null,
                nativeCppType: 'QPoint',
                generationId: 'QPoint__gen',
                smartPointerAliases: ['QSharedPointer<QPoint>' => 'QPoint'],
            ),
            'QPointList' => new PhpClass(
                name: 'QPointList',
                parent: null,
                isAbstract: false,
                isCopyConstructible: true,
                hasPublicConstructor: true,
                hasPublicDestructor: true,
                properties: [],
                methods: [new PhpMethod(
                    name: 'append',
                    access: 'public',
                    isStatic: false,
                    isSignal: false,
                    isSlot: false,
                    isAbstractMethod: false,
                    returnType: 'void',
                    parameters: [new PhpParameter('value', 'QPoint', false, 0)],
                    overloads: [new MethodOverload(
                        declaringClass: 'QPointList',
                        returnType: 'void',
                        smartPointerReturnTargetCppType: null,
                        parameters: [new OverloadParameter('value', 'QPoint', false)],
                        access: 'public',
                        isConst: false,
                        isStatic: false,
                        isVirtual: false,
                        isPureVirtual: false,
                    )],
                )],
                signals: [],
                isQObjectDerived: false,
                classConstants: [],
                nativeIncludes: ['QtCore/QList'],
                nativeAliasOf: null,
                nativeCppType: 'QList<QPoint>',
                generationId: 'QPointList__gen',
                smartPointerAliases: [],
            ),
        ],
        'module_generated_method_totals' => ['QtCore' => 2],
        'generated_class_parents' => ['QPoint' => null, 'QPointList' => null],
        'generated_class_dependencies' => ['QPoint' => [], 'QPointList' => ['QPoint']],
        'generated_class_headers' => ['QPoint' => '/tmp/qt/include/QtCore/QPoint', 'QPointList' => ''],
        'generated_class_modules' => ['QPoint' => 'QtCore', 'QPointList' => 'QtCore'],
        'synthetic_class_namespaces' => ['QPointList' => 'Qt\\Core'],
        'skipped_classes' => [],
        'skipped_methods' => [[
            'module' => 'QtCore',
            'class' => 'QPoint',
            'name' => 'setX',
            'reason_code' => 'unsupported_parameter_type',
            'reason_message' => 'Parameter type Foo is not supported.',
        ]],
        'errors' => [],
        'passes' => 1,
        'requires_signal_connection_support' => true,
    ];
}

function qt_generation_cache_imported_abi(): ImportedModuleAbi
{
    $manifest = new ModuleAbiManifest(
        module: 'QtGui',
        extensionName: 'qtgui',
        buildRootDir: '/tmp/qtgui-build',
        outputDir: '/tmp/qtgui-build/ext',
        metadataDir: '/tmp/qtgui-build/generated',
        acceptedCandidatesPath: '/tmp/qtgui-build/generated/accepted_candidates.json',
        classCacheDir: '/tmp/qtgui-build/classes',
        includeDirs: ['/tmp/qt/include/QtGui'],
        sharedIncludeDirs: [],
        dependencyModules: ['QtCore'],
        classes: ['QColor'],
        classNamespaces: ['QColor' => 'Qt\\Gui'],
        includesSignalConnectionSupport: false,
    );

    return new ImportedModuleAbi(
        manifest: $manifest,
        acceptedCandidates: [new HeaderCandidate('QtGui', 'QColor', '/tmp/qt/include/QtGui/QColor', '/tmp/qt/include/QtGui/QColor')],
        availableClasses: ['QColor'],
        preparedClassDataByClass: ['QColor' => ['name' => 'QColor', 'module' => 'QtGui']],
    );
}
