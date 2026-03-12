<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QThreadRuntime::class, 'QtCore worker runtime support is unavailable in this build.');

$runtime = new \Qt\Core\QThreadRuntime();

$bootstrapFile = sys_get_temp_dir() . '/phpqt_worker_events_receive_' . bin2hex(random_bytes(4)) . '.php';
$bootstrapCode = <<<'PHP'
<?php
function qt_worker_receive_commands_loop(): array
{
    $received = [];
    $deadline = microtime(true) + 3.0;

    while (microtime(true) < $deadline) {
        $message = \Qt\Core\QThreadRuntime::receive(200);
        if ($message === null) {
            continue;
        }

        $event = (string) ($message['event'] ?? '');
        $received[] = $event;
        \Qt\Core\QThreadRuntime::publish('ack', ['event' => $event]);

        if ($event === 'stop') {
            break;
        }
    }

    return $received;
}
PHP;
file_put_contents($bootstrapFile, $bootstrapCode);

$runtime->setBootstrapScript($bootstrapFile);
$runtime->start();

$acks = [];
$ackListener = $runtime->on('ack', static function (array $event) use (&$acks): void {
    $acks[] = (string) ($event['payload']['event'] ?? '');
});

$jobId = $runtime->submit('qt_worker_receive_commands_loop', []);
$sendMissing = $runtime->send(9999999, 'noop', []);
$sendPause = $runtime->send($jobId, 'pause', ['at' => 1]);
$sendResume = $runtime->send($jobId, 'resume', ['at' => 2]);
$sendStop = $runtime->send($jobId, 'stop', ['at' => 3]);

$result = null;
$drained = 0;
while (true) {
    $drained += $runtime->drainEvents(32);
    $result = $runtime->await($jobId, 50);
    if ($result !== null) {
        break;
    }
}
$drained += $runtime->drainEvents(-1);

$runtime->off($ackListener);
$stats = $runtime->stats();
$stopped = $runtime->stop(2000);
@unlink($bootstrapFile);

qt_runtime_result([
    'send_missing' => $sendMissing,
    'send_pause' => $sendPause,
    'send_resume' => $sendResume,
    'send_stop' => $sendStop,
    'result' => $result,
    'acks' => $acks,
    'ack_count' => count($acks),
    'drained' => $drained,
    'events_in_enqueued' => (int) ($stats['events_in_enqueued'] ?? 0),
    'events_in_drained' => (int) ($stats['events_in_drained'] ?? 0),
    'stopped' => $stopped,
]);
