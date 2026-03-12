<?php

declare(strict_types=1);

function qt_worker_blocking_sleep_job(string $name, int $seconds): array
{
    $seconds = max(1, $seconds);
    $ticks = $seconds * 10;
    $lastPublished = -1;

    \Qt\Core\QThread::publish('progress', [
        'name' => $name,
        'value' => 0,
        'seconds' => $seconds,
    ]);

    for ($i = 1; $i <= $ticks; $i++) {
        usleep(100000);
        $percent = (int) floor(($i * 100) / $ticks);
        if ($percent === 100 || ($percent % 10 === 0 && $percent !== $lastPublished)) {
            \Qt\Core\QThread::publish('progress', [
                'name' => $name,
                'value' => $percent,
                'seconds' => $seconds,
            ]);
            $lastPublished = $percent;
        }

        $message = \Qt\Core\QThread::receive(1);
        if (is_array($message) && ($message['event'] ?? null) === 'cancel') {
            break;
        }
    }

    $result = [
        'name' => $name,
        'status' => 'done',
        'seconds' => $seconds,
    ];

    \Qt\Core\QThread::publish('result', $result);

    return $result;
}
