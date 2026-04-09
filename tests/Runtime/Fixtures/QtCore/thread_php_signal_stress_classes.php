<?php

declare(strict_types=1);

function qt_runtime_php_signal_env_int(string $name, int $default, int $minimum = 1): int
{
    $raw = getenv($name);
    if (!is_string($raw) || $raw === '') {
        return $default;
    }

    $value = (int) $raw;

    return $value >= $minimum ? $value : $default;
}

function qt_runtime_php_signal_env_enabled(string $name): bool
{
    $raw = getenv($name);

    return is_string($raw) && $raw !== '' && $raw !== '0';
}

final class RuntimePhpSignalStressEmitter extends \Qt\Core\QObject
{
    #[\Qt\Core\Attributes\Signal(['string'])]
    protected function dataChanged(): void {}

    public function fire(string $message): void
    {
        $this->emit('dataChanged', [$message]);
    }
}

final class RuntimePhpSignalStressReceiver extends \Qt\Core\QObject
{
    public function capture(string $message): void
    {
        $hits = ((int) $this->property('hits')) + 1;
        $this->setProperty('hits', $hits);

        $messages = (string) $this->property('messages_csv');
        $this->setProperty('messages_csv', $messages === '' ? $message : $messages . ',' . $message);

        $currentThreadId = (string) \Qt\Core\QThread::currentThreadId();
        $receiverThreadId = (string) $this->property('receiver_thread_id');
        if ($receiverThreadId === '') {
            $this->setProperty('receiver_thread_id', $currentThreadId);
        } elseif ($receiverThreadId !== $currentThreadId) {
            $this->setProperty(
                'thread_mismatch_hits',
                ((int) $this->property('thread_mismatch_hits')) + 1
            );
        }

        $sender = $this->sender();
        if (!is_object($sender)) {
            $this->setProperty('sender_null_hits', ((int) $this->property('sender_null_hits')) + 1);
        } else {
            $senderClass = get_class($sender);
            if ((string) $this->property('sender_class') === '') {
                $this->setProperty('sender_class', $senderClass);
            }
            if ($senderClass !== \Qt\Core\QObject::class) {
                $this->setProperty(
                    'sender_class_mismatch_hits',
                    ((int) $this->property('sender_class_mismatch_hits')) + 1
                );
            }
        }

        $senderSignal = (int) $this->senderSignalIndex();
        $this->setProperty('sender_signal', $senderSignal);
        if ($senderSignal !== -1) {
            $this->setProperty(
                'sender_signal_mismatch_hits',
                ((int) $this->property('sender_signal_mismatch_hits')) + 1
            );
        }

        $expectedHits = (int) $this->property('expected_hits');
        if ($expectedHits > 0 && $hits >= $expectedHits) {
            $thread = $this->thread();
            if ($thread instanceof \Qt\Core\QThread && $thread->isRunning()) {
                $thread->quit();
            }
        }
    }
}
