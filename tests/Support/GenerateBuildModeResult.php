<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Support;

final class GenerateBuildModeResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $display,
        public readonly array $payload,
        public readonly string $outputDir,
        public readonly string $fixtureRoot,
    ) {}

    public function path(string $class, string $extension): string
    {
        return $this->outputDir . '/classes/qt_' . strtolower($class) . '.' . ltrim($extension, '.');
    }

    public function cpp(string $class): string
    {
        return (string) file_get_contents($this->path($class, 'cpp'));
    }

    public function header(string $class): string
    {
        return (string) file_get_contents($this->path($class, 'h'));
    }

    public function stub(string $class): string
    {
        return (string) file_get_contents($this->path($class, 'stub.php'));
    }
}
