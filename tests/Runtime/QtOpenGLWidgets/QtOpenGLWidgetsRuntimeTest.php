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

it('dispatches inherited widget input handlers on qopenglwidget subclasses', function (): void {
    $payload = qt_runtime_payload('QtOpenGLWidgets/opengl_widget_input_dispatch.php', ['QT_QPA_PLATFORM' => 'offscreen']);

    expect($payload['presses'])->toBe(1)
        ->and($payload['moves'])->toBe(1)
        ->and($payload['wheels'])->toBe(1);
});
