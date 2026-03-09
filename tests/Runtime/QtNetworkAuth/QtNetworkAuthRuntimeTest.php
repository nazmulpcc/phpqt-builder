<?php

declare(strict_types=1);

it('covers oauth2 flow construction client identity and authorization url', function (): void {
    $payload = qt_runtime_payload('QtNetworkAuth/oauth_smoke.php');

    expect($payload['client_id'])->toBe('my-client-id')
        ->and($payload['auth_url'])->toBe('https://example.com/oauth/authorize')
        ->and($payload['status_is_not_granted'])->toBeTrue();

});
