<?php

declare(strict_types=1);

namespace QtBuilder\IO;

final class SmartFileWriter
{
    private readonly ContentComparator $comparator;

    public function __construct(?ContentComparator $comparator = null)
    {
        $this->comparator = $comparator ?? ContentComparatorFactory::createDefault();
    }

    public function comparatorName(): string
    {
        return $this->comparator->name();
    }

    public function write(string $path, string $content): FileWriteResult
    {
        $this->ensureDirectory(dirname($path));
        $bytes = strlen($content);

        if (!is_file($path)) {
            $this->atomicWrite($path, $content);

            return new FileWriteResult($path, FileWriteStatus::Created, $bytes, 'created');
        }

        $existingSize = @filesize($path);
        if (!is_int($existingSize) || $existingSize !== $bytes) {
            $this->atomicWrite($path, $content, $this->existingPermissions($path));

            return new FileWriteResult($path, FileWriteStatus::Updated, $bytes, 'size_mismatch');
        }

        $existing = @file_get_contents($path);
        if (!is_string($existing)) {
            $this->atomicWrite($path, $content, $this->existingPermissions($path));

            return new FileWriteResult($path, FileWriteStatus::Updated, $bytes, 'read_failed_fallback');
        }

        if ($this->comparator->equals($existing, $content)) {
            return new FileWriteResult($path, FileWriteStatus::Unchanged, $bytes, 'unchanged');
        }

        $this->atomicWrite($path, $content, $this->existingPermissions($path));

        return new FileWriteResult($path, FileWriteStatus::Updated, $bytes, 'content_diff');
    }

    private function atomicWrite(string $path, string $content, ?int $permissions = null): void
    {
        $directory = dirname($path);
        $tempPath = tempnam($directory, 'tmp-');
        if ($tempPath === false) {
            file_put_contents($path, $content);
            if (is_int($permissions)) {
                @chmod($path, $permissions);
            }

            return;
        }

        file_put_contents($tempPath, $content);

        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            file_put_contents($path, $content);
        }

        if (is_int($permissions)) {
            @chmod($path, $permissions);
        }
    }

    private function existingPermissions(string $path): ?int
    {
        $perms = @fileperms($path);
        if (!is_int($perms)) {
            return null;
        }

        return $perms & 0777;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create directory: %s', $directory));
        }
    }
}
