<?php

namespace QtBuilder\Contracts;

use QtBuilder\System\CommandResult;
use QtBuilder\System\QtDetectionResult;

interface SystemInformation
{
    public function getOsFamily(): string;

    public function getOsName(): string;

    public function getKernelVersion(): string;

    public function getArchitecture(): string;

    public function getPhpVersion(): string;

    public function hasExtension(string $extension): bool;

    public function findExecutable(string $name): ?string;

    /**
     * @param list<string> $command
     */
    public function runCommand(array $command, float $timeoutSeconds = 5.0): CommandResult;

    public function detectQt(): QtDetectionResult;
}
