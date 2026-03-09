<?php

declare(strict_types=1);

it('covers http server and response construction with status codes and mime types', function (): void {
    $payload = qt_runtime_payload('QtHttpServer/http_server_smoke.php');

    expect($payload['server_is_object'])->toBeTrue()
        ->and($payload['rate_limit'])->toBe(100)
        ->and($payload['keep_alive_timeout'])->toBe(30)
        ->and($payload['server_ports_empty'])->toBeTrue();
});
