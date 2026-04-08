<?php

declare(strict_types=1);

final class RuntimeStaticConnectPhase3Worker extends \Qt\Core\QObject
{
    public string $label = 'phase3';
    public int $hits = 0;
    public int $senderSignal = -1;
    public string $senderClass = '';
    public string $lastThreadId = '';
    public int $captureHits = 0;
    public string $capturedName = '';
    public string $captureThreadId = '';
    public string $captureSenderClass = '';
    public int $captureSenderSignal = -1;
    public string $optionalValue = '';
    public int $exitCodeOnly = -1;
    public int $finishedExitCode = -1;
    public int $finishedExitStatus = -1;
    public bool $destroyedObjectWasWrapped = false;
    public string $destroyedObjectClass = '';

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

    public function captureName(string $name): void
    {
        $this->captureHits++;
        $this->capturedName = $name;
        $this->captureThreadId = (string) \Qt\Core\QThread::currentThreadId();

        $sender = $this->sender();
        $this->captureSenderClass = is_object($sender) ? get_class($sender) : '';
        $this->captureSenderSignal = $this->senderSignalIndex();

        $this->setProperty('phase31_capture_hits', $this->captureHits);
        $this->setProperty('phase31_captured_name', $this->capturedName);
        $this->setProperty('phase31_capture_thread', $this->captureThreadId);
        $this->setProperty('phase31_capture_sender_class', $this->captureSenderClass);
        $this->setProperty('phase31_capture_sender_signal', $this->captureSenderSignal);
    }

    public function captureOptional(string $name, string $suffix = 'tail'): void
    {
        $this->optionalValue = $name . '|' . $suffix;
        $this->setProperty('phase31_optional_value', $this->optionalValue);
    }

    public function captureExitCodeOnly(int $exitCode): void
    {
        $this->exitCodeOnly = $exitCode;
        $this->setProperty('phase31_exit_code_only', $exitCode);
    }

    public function captureProcessFinished(int $exitCode, int $exitStatus): void
    {
        $this->finishedExitCode = $exitCode;
        $this->finishedExitStatus = $exitStatus;
        $this->setProperty('phase31_finished_exit_code', $exitCode);
        $this->setProperty('phase31_finished_exit_status', $exitStatus);
    }

    public function captureDestroyed(\Qt\Core\QObject $object): void
    {
        $this->destroyedObjectWasWrapped = is_object($object);
        $this->destroyedObjectClass = is_object($object) ? get_class($object) : '';
        $this->setProperty('phase31_destroyed_wrapped', $this->destroyedObjectWasWrapped);
        $this->setProperty('phase31_destroyed_class', $this->destroyedObjectClass);
    }

    public function needsTwoStrings(string $left, string $right): void
    {
    }
}
