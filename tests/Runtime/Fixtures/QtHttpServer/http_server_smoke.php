<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\HttpServer\QHttpServer;
use Qt\Network\QHttp2Configuration;

qt_runtime_require_class(QHttpServer::class, 'QtHttpServer classes are unavailable in this build.');

$server = new QHttpServer();

$config = new QHttp2Configuration();
$config->setServerPushEnabled(false);
$config->setSessionReceiveWindowSize(32768);
$config->setMaxFrameSize(16384);

$server->setHttp2Configuration($config);

$applied = $server->http2Configuration();

qt_runtime_result([
    'server_is_object' => is_object($server),
    'server_push' => $applied->serverPushEnabled(),
    'session_receive_window_size' => $applied->sessionReceiveWindowSize(),
    'max_frame_size' => $applied->maxFrameSize(),
    'server_ports_empty' => count($server->serverPorts()) === 0,
]);
