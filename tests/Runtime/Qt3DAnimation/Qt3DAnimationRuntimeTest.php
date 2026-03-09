<?php

declare(strict_types=1);

it('covers animation aspect registration with aspect engine and root entity', function (): void {
    $payload = qt_runtime_payload('Qt3DAnimation/animation_aspect_smoke.php');

    expect($payload['has_animation'])->toBeTrue()
        ->and($payload['aspect_count'])->toBeGreaterThanOrEqual(1)
        ->and($payload['root_is_entity'])->toBeTrue();
});
