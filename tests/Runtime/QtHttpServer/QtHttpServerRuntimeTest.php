<?php

declare(strict_types=1);

it('covers http server and response construction with status codes and mime types', function (): void {
    $payload = qt_runtime_payload('QtHttpServer/http_server_smoke.php');

    expect($payload['server_is_object'])->toBeTrue()
        ->and($payload['server_push'])->toBeFalse()
        ->and($payload['session_receive_window_size'])->toBe(32768)
        ->and($payload['max_frame_size'])->toBe(16384)
        ->and($payload['server_ports_empty'])->toBeTrue();
});
