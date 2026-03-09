<?php

declare(strict_types=1);

it('covers svg renderer validity and default size after loading svg content', function (): void {
    $payload = qt_runtime_payload('QtSvg/svg_renderer_smoke.php');

    expect($payload['empty_is_valid'])->toBeFalse()
        ->and($payload['loaded_is_valid'])->toBeTrue()
        ->and($payload['default_width'])->toBe(200)
        ->and($payload['default_height'])->toBe(100);
});
