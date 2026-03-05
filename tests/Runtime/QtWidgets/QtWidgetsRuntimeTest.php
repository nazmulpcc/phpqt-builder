<?php

declare(strict_types=1);

it('covers layout composition actions and button signal behavior', function (): void {
    $payload = qt_runtime_payload('QtWidgets/layouts_and_actions.php');

    expect($payload['window_title'])->toBe('Runtime Layouts')
        ->and($payload['root_count'])->toBe(2)
        ->and($payload['grid_rows'])->toBe(2)
        ->and($payload['grid_columns'])->toBe(2)
        ->and($payload['actions_layout_count'])->toBe(2)
        ->and($payload['action_count'])->toBe(2)
        ->and($payload['action_texts'])->toBe(['Refresh Sync', 'Export CSV'])
        ->and($payload['sugar_count'])->toBe(2)
        ->and($payload['generic_count'])->toBe(1);
});

it('covers proxy models and widget-driven model mutation', function (): void {
    $payload = qt_runtime_payload('QtWidgets/model_view_integration.php');

    expect($payload['source_rows'])->toBe(4)
        ->and($payload['identity_rows'])->toBe(4)
        ->and($payload['filtered_rows'])->toBe(2)
        ->and($payload['cleared_rows'])->toBe(4)
        ->and($payload['button_clicks'])->toBe(1);
});

it('covers log-viewer tail filtering and pause/resume semantics', function (): void {
    $payload = qt_runtime_payload('QtWidgets/log_viewer_behaviors.php');

    expect($payload['initial_added'])->toBe(1)
        ->and($payload['added_while_running'])->toBe(1)
        ->and($payload['running_rows'])->toBe(2)
        ->and($payload['paused_rows'])->toBe(2)
        ->and($payload['added_while_paused'])->toBe(0)
        ->and($payload['added_after_resume'])->toBe(1)
        ->and($payload['rows_after_resume'])->toBe(3)
        ->and($payload['error_rows'])->toBe(1)
        ->and($payload['queue_rows'])->toBe(1)
        ->and($payload['watcher_files_count'])->toBeGreaterThanOrEqual(1);
});

it('covers file-organizer model filtering preview and bulk rename modes', function (): void {
    $payload = qt_runtime_payload('QtWidgets/file_organizer_behaviors.php');

    expect($payload['loaded_path'])->not->toBe('')
        ->and($payload['root_index_valid'])->toBeTrue()
        ->and($payload['rows_before_filter'])->toBeGreaterThanOrEqual(1)
        ->and($payload['rows_after_filter'])->toBeGreaterThanOrEqual(0)
        ->and($payload['favorites_count'])->toBe(2)
        ->and($payload['selected_renamed'])->toBe(1)
        ->and($payload['folder_renamed'])->toBe(1)
        ->and($payload['renamed_orders_exists'])->toBeTrue()
        ->and($payload['renamed_service_exists'])->toBeTrue()
        ->and($payload['preview_first_line'])->toBe('hello')
        ->and($payload['preview_has_world'])->toBeTrue();
});
