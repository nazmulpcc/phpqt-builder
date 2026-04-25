<?php

declare(strict_types=1);

final class RuntimePhpSignalMovedSenderWorker extends \Qt\Core\QObject
{
    #[\Qt\Core\Attributes\Signal(['string'])]
    protected function dataChanged(): void {}

    #[\Qt\Core\Attributes\Signal]
    protected function finished(): void {}

    public function doWork(): void
    {
        $threadId = (string) \Qt\Core\QThread::currentThreadId();
        $this->setProperty('worker_thread_id', $threadId);
        $this->emit('dataChanged', ['php-signal:' . $threadId]);
        $this->emit('finished');
    }
}
