<?php

namespace QtBuilder\Contracts;

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

    public function detectQt(): QtDetectionResult;
}
