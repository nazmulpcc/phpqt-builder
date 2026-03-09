<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\HttpServer\QHttpServer;
use Qt\HttpServer\QHttpServerConfiguration;

qt_runtime_require_class(QHttpServer::class, 'QtHttpServer classes are unavailable in this build.');

$server = new QHttpServer();

$config = new QHttpServerConfiguration();
$config->setRateLimitPerSecond(100);
$config->setKeepAliveTimeout(30);

$server->setConfiguration($config);

$applied = $server->configuration();

qt_runtime_result([
    'server_is_object'    => is_object($server),
    'rate_limit'          => $applied->rateLimitPerSecond(),
    'keep_alive_timeout'  => $applied->keepAliveTimeout(),
    'server_ports_empty'  => count($server->serverPorts()) === 0,
]);
