<?php

declare(strict_types=1);

it('covers pdf view widget creation document attachment and resize', function (): void {
    $payload = qt_runtime_payload('QtPdfWidgets/pdf_view_smoke.php');

    expect($payload['is_widget'])->toBeTrue()
        ->and($payload['width'])->toBe(800)
        ->and($payload['height'])->toBe(600);
});
