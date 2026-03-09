<?php

declare(strict_types=1);

it('covers pdf document initial state and error on missing file', function (): void {
    $payload = qt_runtime_payload('QtPdf/pdf_document_smoke.php');

    expect($payload['initial_pages'])->toBe(0)
        ->and($payload['load_error_is_int'])->toBeTrue()
        ->and($payload['load_failed'])->toBeTrue();
});
