<?php

declare(strict_types=1);

final class RuntimeMoveToThreadPhase2Worker extends \Qt\Core\QObject
{
    public $state = 'source-php';
    public $meta = ['mode' => 'source'];
    public $lastThreadId = 0;

    public function event(\Qt\Core\QEvent $event): bool
    {
        if ($event->type() === 1001) {
            $this->state = 'worker-php';
            $this->meta = ['mode' => 'worker'];
            $this->lastThreadId = \Qt\Core\QThread::currentThreadId();
            $this->setProperty('data', 'worker-native');
            $this->setObjectName('phase2:' . (string) $this->lastThreadId);
            $this->thread()->quit();

            return true;
        }

        if ($event->type() === 1002) {
            $this->setObjectName('phase2:deleting');
            $this->deleteLater();
            $this->thread()->quit();

            return true;
        }

        return parent::event($event);
    }
}
