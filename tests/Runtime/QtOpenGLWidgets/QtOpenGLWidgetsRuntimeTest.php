<?php

declare(strict_types=1);

it('initializes and paints a qopenglwidget shader scene', function (): void {
    $payload = qt_runtime_payload('QtOpenGLWidgets/opengl_widget_smoke.php', ['QT_QPA_PLATFORM' => 'offscreen'], 10);

    expect($payload['is_valid'])->toBeTrue()
        ->and($payload['initialize_hits'])->toBeGreaterThanOrEqual(1)
        ->and($payload['paint_hits'])->toBeGreaterThanOrEqual(1)
        ->and($payload['shader_ready'])->toBeTrue()
        ->and($payload['error'])->toBe('');
});
