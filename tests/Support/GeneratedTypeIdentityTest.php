<?php

declare(strict_types=1);

use QtBuilder\Support\GeneratedTypeIdentity;

it('derives distinct generation ids for colliding short names', function (): void {
    $qtGui = GeneratedTypeIdentity::fromNames('QTransform', 'QTransform');
    $qt3D = GeneratedTypeIdentity::fromNames('QTransform', 'Qt3DCore::QTransform');

    expect($qtGui->generationId)->toBe('qtransform')
        ->and($qt3D->generationId)->toBe('qtransform__qt3dcore')
        ->and($qtGui->generationId)->not->toBe($qt3D->generationId);
});
