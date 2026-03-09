<?php

declare(strict_types=1);

it('covers video widget creation resize and aspect ratio defaults', function (): void {
    $payload = qt_runtime_payload('QtMultimediaWidgets/video_widget_smoke.php');

    expect($payload['is_widget'])->toBeTrue()
        ->and($payload['width'])->toBe(640)
        ->and($payload['height'])->toBe(360)
        ->and($payload['is_fullscreen'])->toBeFalse();
});
