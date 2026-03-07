<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class BootstrapStep
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        public string $name,
        public array $command,
        public string $workingDirectory,
        public string $stdoutLogPath,
        public string $stderrLogPath,
        public float $durationSeconds,
    ) {}

    /**
     * @return array{
     *   name: string,
     *   command: list<string>,
     *   working_directory: string,
     *   stdout_log: string,
     *   stderr_log: string,
     *   duration_seconds: float
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'command' => $this->command,
            'working_directory' => $this->workingDirectory,
            'stdout_log' => $this->stdoutLogPath,
            'stderr_log' => $this->stderrLogPath,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
