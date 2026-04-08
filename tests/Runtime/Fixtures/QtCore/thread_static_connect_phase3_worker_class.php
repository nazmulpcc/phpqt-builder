<?php

declare(strict_types=1);

final class RuntimeStaticConnectPhase3Worker extends \Qt\Core\QObject
{
    public string $label = 'phase3';
    public int $hits = 0;
    public int $senderSignal = -1;
    public string $senderClass = '';
    public string $lastThreadId = '';

    public function doWork(): void
    {
        $this->hits++;
        $this->lastThreadId = (string) \Qt\Core\QThread::currentThreadId();

        $sender = $this->sender();
        $this->senderClass = is_object($sender) ? get_class($sender) : '';
        $this->senderSignal = $this->senderSignalIndex();

        $this->setProperty('phase3_hits', $this->hits);
        $this->setProperty('phase3_sender_class', $this->senderClass);
        $this->setProperty('phase3_sender_signal', $this->senderSignal);
        $this->setProperty('phase3_worker_thread', $this->lastThreadId);
        $this->setObjectName($this->label . ':' . $this->lastThreadId);
    }
}
