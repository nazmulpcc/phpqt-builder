<?php

declare(strict_types=1);

it('covers qobject lifecycle and property notifications', function (): void {
    $payload = qt_runtime_payload('QtCore/qobject_properties.php');

    expect($payload['name'])->toBe('Runtime Child')
        ->and($payload['has_parent'])->toBeTrue()
        ->and($payload['inherits_qobject'])->toBeTrue()
        ->and($payload['notify_hits'])->toBe(1)
        ->and($payload['connection_is_object'])->toBeTrue();
});

it('covers timers and event delivery with short ticks', function (): void {
    $payload = qt_runtime_payload('QtCore/timers_and_events.php');

    expect($payload['custom_type'])->toBeGreaterThan(0)
        ->and($payload['filter_count'])->toBeGreaterThanOrEqual(2)
        ->and($payload['event_count'])->toBeGreaterThanOrEqual(2)
        ->and($payload['custom_count'])->toBe(2)
        ->and($payload['timer_ticks'])->toBe(3);
});

it('covers container round-trips and stream state', function (): void {
    $payload = qt_runtime_payload('QtCore/containers_and_streams.php');

    expect($payload['watch_file_count_before'])->toBe(1)
        ->and($payload['watch_dir_count_before'])->toBe(1)
        ->and($payload['watch_file_count_after'])->toBe(0)
        ->and($payload['program'])->toBe('/bin/echo')
        ->and($payload['argument_count'])->toBe(3)
        ->and($payload['env_has_demo_mode'])->toBeTrue()
        ->and($payload['env_key_count'])->toBeGreaterThan(0)
        ->and($payload['first_line'])->toBe('sales-eu,1280')
        ->and($payload['rest'])->toBe('sales-us,1540')
        ->and($payload['debug_verbosity'])->toBe(5)
        ->and($payload['debug_auto_insert_spaces'])->toBeFalse()
        ->and($payload['debug_quote_strings'])->toBeFalse();
});

it('covers abstract item model row insertion and data access', function (): void {
    $payload = qt_runtime_payload('QtCore/abstract_item_models.php');

    expect($payload['table_rows'])->toBe(3)
        ->and($payload['table_columns'])->toBe(2)
        ->and($payload['table_cell'])->toBe('SO-002')
        ->and($payload['list_rows'])->toBe(3)
        ->and($payload['list_item'])->toBe('Order synced');
});

it('writes back by-reference scalar out parameters', function (): void {
    $payload = qt_runtime_payload('QtCore/byref_writeback.php');

    expect($payload['ok_is_bool'])->toBeTrue()
        ->and($payload['ok_type'])->toBe('bool')
        ->and($payload['ok_value'])->toBeTrue()
        ->and($payload['value'])->toBeInt();
});
