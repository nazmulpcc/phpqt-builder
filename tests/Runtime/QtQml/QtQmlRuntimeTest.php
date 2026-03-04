<?php

declare(strict_types=1);

it('covers qml context property binding updates', function (): void {
    $payload = qt_runtime_payload('QtQml/context_property_binding.php');

    expect($payload['initial_seen_name'])->toBe('sales-1')
        ->and($payload['updated_seen_name'])->toBe('sales-2')
        ->and($payload['notify_hits'])->toBe(1)
        ->and($payload['connection_is_object'])->toBeTrue();
});
