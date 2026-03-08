<?php

declare(strict_types=1);

use QtBuilder\Tests\Runtime\Support\QtRuntimeProcessRunner;

it('covers core/render/extras/input class graph assembly', function (): void {
    $payload = qt_runtime_payload('Qt3D/modules_surface.php');

    expect($payload['run_mode'])->toBe(1)
        ->and($payload['aspect_count'])->toBeGreaterThanOrEqual(1)
        ->and($payload['root_is_entity'])->toBeTrue()
        ->and((float) $payload['camera_fov'])->toEqualWithDelta(45.0, 0.0001)
        ->and((float) $payload['camera_aspect'])->toEqualWithDelta(16.0 / 9.0, 0.0001)
        ->and($payload['clear_buffers'])->toBe(3)
        ->and($payload['clear_color_blue'])->toBeGreaterThan(0.15)
        ->and($payload['input_event_source_is_object'])->toBeTrue()
        ->and($payload['input_event_source_class'])->toBe('Qt\\Core\\QObject')
        ->and((float) $payload['orbit_linear_speed'])->toEqualWithDelta(20.0, 0.0001)
        ->and((float) $payload['orbit_look_speed'])->toEqualWithDelta(180.0, 0.0001)
        ->and((float) $payload['orbit_zoom_limit'])->toEqualWithDelta(2.5, 0.0001)
        ->and($payload['logical_actions_count'])->toBe(1)
        ->and($payload['logical_axes_count'])->toBe(1);
});

it('loads split qt3d module dependencies for qt3dextras fixtures', function (): void {
    $result = QtRuntimeProcessRunner::runFixtureWithModules(
        'Qt3D/modules_surface.php',
        ['Qt3DExtras'],
        [],
        8,
    );

    if ($result->isSkipped()) {
        test()->markTestSkipped($result->skipReason());
    }

    expect($result->exitCode())->toBe(
        0,
        sprintf(
            "Split Qt3D runtime fixture failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
            $result->stdout(),
            $result->stderr(),
        ),
    );

    $payload = $result->payload();
    expect($payload['root_is_entity'] ?? false)->toBeTrue()
        ->and($payload['logical_actions_count'] ?? 0)->toBe(1)
        ->and($payload['logical_axes_count'] ?? 0)->toBe(1);
});
