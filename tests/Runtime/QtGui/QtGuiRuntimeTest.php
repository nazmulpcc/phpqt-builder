<?php

declare(strict_types=1);

it('covers QFileSystemModel root loading without a visible window', function (): void {
    $payload = qt_runtime_payload('QtGui/filesystem_model.php');

    expect($payload['loaded_path'])->not->toBe('')
        ->and($payload['row_count'])->toBeGreaterThanOrEqual(1)
        ->and($payload['root_index_valid'])->toBeTrue()
        ->and($payload['ticks'])->toBeGreaterThanOrEqual(1);
});
