<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class BuildLayout
{
    public string $buildRootDir;

    public function __construct(string $buildRootDir)
    {
        $this->buildRootDir = self::absolutePath(rtrim($buildRootDir, '/'));
    }

    public static function fromCliOutput(string $output): self
    {
        $normalized = rtrim(trim($output), '/');
        if ($normalized === '') {
            throw new \InvalidArgumentException('The build root directory cannot be empty.');
        }

        if (basename($normalized) === 'ext') {
            throw new \InvalidArgumentException(sprintf(
                '--output must be a build root directory, not an extension directory. Use %s instead of %s.',
                dirname($normalized),
                $normalized,
            ));
        }

        return new self($normalized);
    }

    private static function absolutePath(string $path): string
    {
        if ($path === '' || self::isAbsolutePath($path)) {
            return $path;
        }

        $cwd = getcwd();
        if (!is_string($cwd) || $cwd === '') {
            return $path;
        }

        return rtrim($cwd, '/\\') . '/' . ltrim($path, '/\\');
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    public function extensionDir(): string
    {
        return $this->buildRootDir . '/ext';
    }

    public function metadataDir(): string
    {
        return $this->buildRootDir . '/generated';
    }

    public function classCacheDir(): string
    {
        return $this->buildRootDir . '/classes';
    }
}
