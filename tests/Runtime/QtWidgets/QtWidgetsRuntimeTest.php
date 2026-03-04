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
