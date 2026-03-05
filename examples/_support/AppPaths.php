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
        return $this->ensureDir($this->runtimeDir() . '/data');
    }

    public function exportsDir(): string
    {
        return $this->ensureDir($this->runtimeDir() . '/exports');
    }

    public function runtimeDir(): string
    {
        return $this->ensureDir($this->exampleRoot . '/runtime');
    }

    public function dataFile(string $name): string
    {
        $relative = ltrim($name, '/');
        $target = $this->dataDir() . '/' . $relative;
        if (file_exists($target)) {
            return $target;
        }

        $seed = $this->exampleRoot . '/data/' . $relative;
        if (is_file($seed)) {
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            copy($seed, $target);
            return $target;
        }

        if (is_dir($seed)) {
            $this->copyDir($seed, $target);
        } else {
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        return $target;
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

    private function copyDir(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $entries = scandir($source);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $src = $source . '/' . $entry;
            $dst = $target . '/' . $entry;
            if (is_dir($src)) {
                $this->copyDir($src, $dst);
                continue;
            }

            copy($src, $dst);
        }
    }
}
