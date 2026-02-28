<?php

namespace QtBuilder\System;

final readonly class CommandResult
{
    public function __construct(
        private int $exitCode,
        private string $stdout,
        private string $stderr,
    ) {
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getStdout(): string
    {
        return $this->stdout;
    }

    public function getStderr(): string
    {
        return $this->stderr;
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
