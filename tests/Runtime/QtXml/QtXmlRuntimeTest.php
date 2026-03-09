<?php

declare(strict_types=1);

it('covers dom document creation parsing and serialization', function (): void {
    $payload = qt_runtime_payload('QtXml/dom_smoke.php');

    expect($payload['root_tag'])->toBe('inventory')
        ->and($payload['root_has_child'])->toBeTrue()
        ->and($payload['item_tag'])->toBe('item')
        ->and($payload['item_is_element'])->toBeTrue()
        ->and($payload['name_tag'])->toBe('name')
        ->and($payload['name_text'])->toBe('Blue Widget')
        ->and($payload['xml_has_inventory'])->toBeTrue()
        ->and($payload['xml_has_widget'])->toBeTrue();
});
