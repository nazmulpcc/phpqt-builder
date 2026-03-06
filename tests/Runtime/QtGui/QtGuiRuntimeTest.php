<?php

declare(strict_types=1);

it('covers QFileSystemModel root loading without a visible window', function (): void {
    $payload = qt_runtime_payload('QtGui/filesystem_model.php');

    expect($payload['loaded_path'])->not->toBe('')
        ->and($payload['row_count'])->toBeGreaterThanOrEqual(1)
        ->and($payload['root_index_valid'])->toBeTrue()
        ->and($payload['ticks'])->toBeGreaterThanOrEqual(1);
});

it('supports QList-derived value classes through synthetic list parents', function (): void {
    $payload = qt_runtime_payload('QtGui/qpolygon_list_parent.php');

    expect($payload['inherits_list'])->toBeTrue()
        ->and($payload['count'])->toBe(1)
        ->and($payload['item_class'])->toBe('Qt\\Core\\QPoint')
        ->and($payload['x'])->toBe(7)
        ->and($payload['y'])->toBe(9);
});

it('supports QList-derived floating point polygons through synthetic list parents', function (): void {
    $payload = qt_runtime_payload('QtGui/qpolygonf_list_parent.php');

    expect($payload['inherits_list'])->toBeTrue()
        ->and($payload['count'])->toBe(1)
        ->and($payload['item_class'])->toBe('Qt\\Core\\QPointF')
        ->and($payload['x'])->toBe(1.5)
        ->and($payload['y'])->toBe(2.5);
});
