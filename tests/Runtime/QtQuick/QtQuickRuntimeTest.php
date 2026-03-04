<?php

declare(strict_types=1);

it('covers qtquick window bindings headlessly', function (): void {
    $payload = qt_runtime_payload('QtQuick/window_binding.php', ['QT_QPA_PLATFORM' => 'offscreen']);

    expect($payload['root_count'])->toBe(1)
        ->and($payload['initial_title'])->toBe('sales-1')
        ->and($payload['updated_title'])->toBe('sales-2')
        ->and($payload['updated_seen_name'])->toBe('sales-2')
        ->and($payload['notify_hits'])->toBe(1);
});

it('covers qtquick list view model updates with short event loops', function (): void {
    $payload = qt_runtime_payload('QtQuick/listview_model_updates.php', ['QT_QPA_PLATFORM' => 'offscreen']);

    expect($payload['initial_count'])->toBe(2)
        ->and($payload['updated_count'])->toBe(3)
        ->and($payload['initial_latest'])->toBe('#SO-0321  APAC  $935')
        ->and($payload['updated_latest'])->toBe('#SO-0322  AMER  $970')
        ->and($payload['model_latest'])->toBe('#SO-0322  AMER  $970');
});

it('covers qtquick list view named role bindings from php models', function (): void {
    $payload = qt_runtime_payload('QtQuick/listview_named_roles.php', ['QT_QPA_PLATFORM' => 'offscreen']);

    expect($payload['initial_count'])->toBe(2)
        ->and($payload['updated_count'])->toBe(3)
        ->and($payload['initial_latest_label'])->toBe('#SO-0402')
        ->and($payload['updated_latest_label'])->toBe('#SO-0403')
        ->and($payload['initial_latest_summary'])->toBe('APAC $945')
        ->and($payload['updated_latest_summary'])->toBe('AMER $980')
        ->and($payload['model_latest_label'])->toBe('#SO-0403');
});
