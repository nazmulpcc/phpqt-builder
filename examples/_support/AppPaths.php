<?php

declare(strict_types=1);

namespace Examples\Support;

final readonly class AppPaths
{
    public function __construct(
        private string $exampleRoot,
    ) {
    }

    public static function fromExampleRoot(string $exampleRoot): self
    {
        return new self(rtrim($exampleRoot, '/'));
    }

    public function exampleRoot(): string
    {
        return $this->exampleRoot;
    }

    public function dataDir(): string
    {
        return $this->ensureDir($this->exampleRoot . '/data');
    }

    public function exportsDir(): string
    {
        return $this->ensureDir($this->exampleRoot . '/exports');
    }

    public function runtimeDir(): string
    {
        return $this->ensureDir($this->exampleRoot . '/runtime');
    }

    public function dataFile(string $name): string
    {
        return $this->dataDir() . '/' . ltrim($name, '/');
    }

    public function exportFile(string $name): string
    {
        return $this->exportsDir() . '/' . ltrim($name, '/');
    }

    public function runtimeFile(string $name): string
    {
        return $this->runtimeDir() . '/' . ltrim($name, '/');
    }

    private function ensureDir(string $path): string
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }
}
