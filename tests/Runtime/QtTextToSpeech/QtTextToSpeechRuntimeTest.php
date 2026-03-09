<?php

declare(strict_types=1);

it('covers text to speech engine initialization and property types', function (): void {
    $payload = qt_runtime_payload('QtTextToSpeech/tts_smoke.php');

    expect($payload['engines_is_array'])->toBeTrue()
        ->and($payload['state_is_int'])->toBeTrue()
        ->and($payload['rate_is_numeric'])->toBeTrue()
        ->and($payload['pitch_is_numeric'])->toBeTrue()
        ->and($payload['volume_in_range'])->toBeTrue();
});
