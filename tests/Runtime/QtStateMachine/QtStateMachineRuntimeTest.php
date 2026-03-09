<?php

declare(strict_types=1);

it('covers state machine construction with states transitions and startup', function (): void {
    $payload = qt_runtime_payload('QtStateMachine/state_machine_smoke.php');

    expect($payload['is_running'])->toBeTrue()
        ->and($payload['initial_state_name'])->toBe('StateA')
        ->and($payload['s1_name'])->toBe('StateA')
        ->and($payload['s2_name'])->toBe('StateB')
        ->and($payload['final_name'])->toBe('Final');

});
