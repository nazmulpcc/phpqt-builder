<?php

declare(strict_types=1);

use QtBuilder\Build\BuildPipeline;
use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Qt\QtInstallation;

it('preserves module-qualified qt header paths for generated includes', function (): void {
    $reflection = new ReflectionClass(BuildPipeline::class);
    $pipeline = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('relativeQtHeaderPath');
    $method->setAccessible(true);

    expect($method->invoke($pipeline, '/opt/qt/include/Qt3DCore/QNode'))->toBe('Qt3DCore/QNode')
        ->and($method->invoke($pipeline, '/opt/qt/include/Qt3DCore/qnode.h'))->toBe('Qt3DCore/qnode.h')
        ->and($method->invoke($pipeline, '/opt/qt/include/QtCore/QObject'))->toBe('QtCore/QObject')
        ->and($method->invoke($pipeline, '/opt/qt/lib/Qt3DRender.framework/Headers/QRenderPass'))->toBe('Qt3DRender/QRenderPass');
});

it('tracks return_type dependencies for re-analysis when nested candidates are removed', function (): void {
    $reflection = new ReflectionClass(BuildPipeline::class);
    $pipeline = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('generatedExtractCandidateDependencies');
    $method->setAccessible(true);

    $classData = [
        'methods' => [[
            'name' => 'end',
            'return_type' => 'sentinel',
            'parameters' => [],
        ]],
        'properties' => [],
        'bases' => [],
    ];
    $candidateSet = [
        'QDirListing' => true,
        'QDirListing::sentinel' => true,
    ];
    $shortNameIndex = [
        'QDirListing' => ['QDirListing'],
        'sentinel' => ['QDirListing::sentinel'],
    ];

    /** @var list<string> $dependencies */
    $dependencies = $method->invoke(
        $pipeline,
        'QDirListing',
        $classData,
        $candidateSet,
        $shortNameIndex,
    );
    sort($dependencies);

    expect($dependencies)->toBe(['QDirListing::sentinel']);
});

it('emits windows unity bucket sources that include the generated class sources', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-win-buckets-') . '/ext';
    mkdir($outputDir . '/classes', 0755, true);
    file_put_contents($outputDir . '/classes/qt_alpha.cpp', "// alpha\n");
    file_put_contents($outputDir . '/classes/qt_beta.cpp', "// beta\n");

    $installation = new QtInstallation(
        rootPath: 'Z:\\6.8.3\\msvc2022_64',
        osFamily: 'Windows',
        includeRoots: ['Z:\\6.8.3\\msvc2022_64\\include'],
        libraryRoots: ['Z:\\6.8.3\\msvc2022_64\\lib'],
        moduleHeaderRoots: ['QtCore' => 'Z:\\6.8.3\\msvc2022_64\\include\\QtCore'],
        tools: [],
    );
    $context = new ExtensionBuildContext('qt', '0.1.0', dirname($outputDir), $outputDir, $installation, ['QtCore'], ['Alpha', 'Beta']);

    $reflection = new ReflectionClass(BuildPipeline::class);
    $pipeline = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('relocateWindowsSourceBuckets');
    $method->setAccessible(true);
    $method->invoke($pipeline, $context);

    $bucket = $outputDir . '/src_00/qt_bucket_00.cpp';
    expect(is_file($bucket))->toBeTrue();

    $contents = (string) file_get_contents($bucket);
    expect($contents)->toContain(
        '#include "../classes/qt_alpha.cpp"',
        '#include "../classes/qt_beta.cpp"',
    );
});
