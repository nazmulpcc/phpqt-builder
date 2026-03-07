<?php

declare(strict_types=1);

it('loads and initializes the bundled obj browser scene', function (): void {
    $payload = qt_runtime_payload('QtOpenGLWidgets/obj_browser_smoke.php', ['QT_QPA_PLATFORM' => 'offscreen'], 10);

    expect($payload['loaded'])->toBeTrue()
        ->and($payload['scene_ready'])->toBeTrue()
        ->and($payload['triangle_count'])->toBeGreaterThan(0)
        ->and($payload['material_count'])->toBeGreaterThan(0);
});
