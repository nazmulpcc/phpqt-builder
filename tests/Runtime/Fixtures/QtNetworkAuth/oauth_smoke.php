<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Core\QUrl;
use Qt\NetworkAuth\QAbstractOAuth;
use Qt\NetworkAuth\QOAuth2AuthorizationCodeFlow;

qt_runtime_require_class(QOAuth2AuthorizationCodeFlow::class, 'QtNetworkAuth classes are unavailable in this build.');

$flow = new QOAuth2AuthorizationCodeFlow();
$flow->setClientIdentifier('my-client-id');
$flow->setClientIdentifierSharedKey('my-client-secret');
$flow->setAuthorizationUrl(new QUrl('https://example.com/oauth/authorize'));
$flow->setAccessTokenUrl(new QUrl('https://example.com/oauth/token'));

qt_runtime_result([
    'client_id' => $flow->clientIdentifier(),
    'auth_scheme' => $flow->authorizationUrl()->scheme(),
    'auth_host' => $flow->authorizationUrl()->host(),
    'auth_path' => $flow->authorizationUrl()->path(),
    'status_is_not_granted' => $flow->status() !== QAbstractOAuth::Granted,
]);
