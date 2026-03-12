<?php

declare(strict_types=1);

use QtBuilder\Build\BuildPipeline;

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
