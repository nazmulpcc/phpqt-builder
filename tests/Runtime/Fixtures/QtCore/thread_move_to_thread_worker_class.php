<?php

declare(strict_types=1);

final class RuntimeMoveToThreadWorker extends \Qt\Core\QObject
{
    public $prefix = 'unset';
    public $payload = [];
    public $hits = 0;
    public $lastThreadId = 0;

    public function event(\Qt\Core\QEvent $event): bool
    {
        if ($event->type() !== 1001) {
            return parent::event($event);
        }

        $this->hits++;
        $this->lastThreadId = \Qt\Core\QThread::currentThreadId();
        $alpha = (int) ($this->payload['alpha'] ?? 0);
        $label = (string) ($this->payload['label'] ?? '');

        $this->setObjectName($this->prefix . ':' . $label . ':' . $alpha . ':' . (string) $this->lastThreadId);
        $this->thread()->quit();

        return true;
    }
}
