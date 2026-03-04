<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final class BuildDirectoryCleaner
{
    public function clear(string $buildRootDir): void
    {
        $normalized = rtrim($buildRootDir, '/');
        if ($normalized === '' || $normalized === '/') {
            throw new \InvalidArgumentException('Refusing to clear an empty path or filesystem root.');
        }

        $resolvedRoot = realpath($normalized);
        $resolvedCwd = realpath((string) getcwd());
        if ($resolvedRoot !== false && $resolvedCwd !== false && $resolvedRoot === $resolvedCwd) {
            throw new \InvalidArgumentException('Refusing to clear the current working directory. Choose a dedicated build root.');
        }

        if (!file_exists($normalized)) {
            return;
        }

        $this->removePath($normalized);
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path) && file_exists($path)) {
                throw new \RuntimeException(sprintf('Could not remove file: %s', $path));
            }
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            throw new \RuntimeException(sprintf('Could not scan directory: %s', $path));
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removePath($path . '/' . $entry);
        }

        if (!@rmdir($path) && is_dir($path)) {
            throw new \RuntimeException(sprintf('Could not remove directory: %s', $path));
        }
    }
}
