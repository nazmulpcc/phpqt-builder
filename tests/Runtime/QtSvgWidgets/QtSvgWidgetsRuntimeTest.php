<?php

declare(strict_types=1);

it('covers svg widget creation renderer attachment and resize', function (): void {
    $payload = qt_runtime_payload('QtSvgWidgets/svg_widget_smoke.php');

    expect($payload['is_widget'])->toBeTrue()
        ->and($payload['renderer_valid'])->toBeTrue()
        ->and($payload['width'])->toBe(300)
        ->and($payload['height'])->toBe(150);
});
