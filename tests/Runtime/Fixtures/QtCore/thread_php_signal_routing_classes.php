<?php

declare(strict_types=1);

final class RuntimePhpSignalRoutingEmitter extends \Qt\Core\QObject
{
    #[\Qt\Core\Attributes\Signal(['string'])]
    protected function dataChanged(): void {}

    public function fire(string $message): void
    {
        $this->emit('dataChanged', [$message]);
    }
}

final class RuntimePhpSignalRoutingReceiver extends \Qt\Core\QObject
{
    public function captureBefore(string $message): void
    {
        $this->setProperty('before_hits', ((int) $this->property('before_hits')) + 1);
        $this->setProperty('before_message', $message);
        $this->setProperty('before_thread_id', (string) \Qt\Core\QThread::currentThreadId());
        $sender = $this->sender();
        $this->setProperty('sender_class', is_object($sender) ? get_class($sender) : gettype($sender));
        $this->setProperty('sender_signal', (int) $this->senderSignalIndex());
    }

    public function captureAfter(string $message): void
    {
        $this->setProperty('after_hits', ((int) $this->property('after_hits')) + 1);
        $this->setProperty('after_message', $message);
        $this->setProperty('after_thread_id', (string) \Qt\Core\QThread::currentThreadId());
        $this->thread()->quit();
    }
}
