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
