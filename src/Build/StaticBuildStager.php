<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\IO\FileWriteStats;
use QtBuilder\IO\SmartFileWriter;

final class StaticBuildStager
{
    private const MANIFEST_FILENAME = '.qtb-stage-manifest.json';

    private readonly SmartFileWriter $fileWriter;

    public function __construct(?SmartFileWriter $fileWriter = null)
    {
        $this->fileWriter = $fileWriter ?? new SmartFileWriter();
    }

    public function stage(string $sourceDir, string $targetDir): StaticStageResult
    {
        $sourceDir = $this->normalizePath($sourceDir);
        $targetDir = $this->normalizePath($targetDir);

        if (!is_dir($sourceDir)) {
            throw new \RuntimeException(sprintf('Static staging source directory not found: %s', $sourceDir));
        }

        $this->ensureDirectory($targetDir);

        $trackedFiles = $this->collectTrackedFiles($sourceDir);
        $writeStats = new FileWriteStats();

        foreach ($trackedFiles as $relativePath => $sourcePath) {
            $content = file_get_contents($sourcePath);
            if (!is_string($content)) {
                throw new \RuntimeException(sprintf('Could not read staged source file: %s', $sourcePath));
            }

            $result = $this->fileWriter->write($targetDir . '/' . $relativePath, $content);
            $writeStats->record($result);
        }

        $previousManifest = $this->loadManifest($targetDir);
        $previousFiles = $previousManifest['files'] ?? [];
        $prunedFiles = [];

        foreach ($previousFiles as $relativePath) {
            if (!is_string($relativePath) || isset($trackedFiles[$relativePath])) {
                continue;
            }

            $targetPath = $targetDir . '/' . $relativePath;
            if (is_file($targetPath) && !@unlink($targetPath) && file_exists($targetPath)) {
                throw new \RuntimeException(sprintf('Could not remove stale staged file: %s', $targetPath));
            }

            if (file_exists($targetPath)) {
                throw new \RuntimeException(sprintf('Stale staged path is not a file and cannot be removed safely: %s', $targetPath));
            }

            $prunedFiles[] = $targetPath;
            $this->removeEmptyParentDirectories(dirname($targetPath), $targetDir);
        }

        $manifestPath = $targetDir . '/' . self::MANIFEST_FILENAME;
        $manifestPayload = [
            'schema_version' => 1,
            'source_dir' => $sourceDir,
            'target_dir' => $targetDir,
            'files' => array_values(array_keys($trackedFiles)),
        ];
        $manifestJson = json_encode($manifestPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($manifestJson)) {
            throw new \RuntimeException('Could not encode static stage manifest.');
        }

        $manifestResult = $this->fileWriter->write($manifestPath, $manifestJson . "\n");
        $writeStats->record($manifestResult);

        return new StaticStageResult(
            sourceDir: $sourceDir,
            targetDir: $targetDir,
            manifestPath: $manifestPath,
            stagedFiles: array_values(array_keys($trackedFiles)),
            prunedFiles: $prunedFiles,
            writeStats: $writeStats,
        );
    }

    /**
     * @return array<string, string>
     */
    private function collectTrackedFiles(string $sourceDir): array
    {
        $trackedFiles = [];

        foreach (scandir($sourceDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $sourceDir . '/' . $entry;
            if (is_file($path) && $this->isTrackedRootFile($entry)) {
                $trackedFiles[$entry] = $path;
            }
        }

        $classesDir = $sourceDir . '/classes';
        if (is_dir($classesDir)) {
            foreach ($this->discoverFiles($classesDir, 'classes') as $relativePath => $path) {
                if ($this->isTrackedClassFile($relativePath)) {
                    $trackedFiles[$relativePath] = $path;
                }
            }
        }

        foreach (glob($sourceDir . '/src_*', GLOB_ONLYDIR) ?: [] as $bucketDir) {
            $bucketName = basename($bucketDir);
            foreach ($this->discoverFiles($bucketDir, $bucketName) as $relativePath => $path) {
                if (str_ends_with($relativePath, '.cpp')) {
                    $trackedFiles[$relativePath] = $path;
                }
            }
        }

        ksort($trackedFiles);

        return $trackedFiles;
    }

    private function isTrackedRootFile(string $filename): bool
    {
        return $filename === 'config.m4'
            || $filename === 'config.w32'
            || str_ends_with($filename, '.h')
            || str_ends_with($filename, '.cpp');
    }

    private function isTrackedClassFile(string $relativePath): bool
    {
        if (str_contains($relativePath, '/.libs/')) {
            return false;
        }

        return (str_ends_with($relativePath, '.h') && !str_ends_with($relativePath, '_arginfo.h'))
            || str_ends_with($relativePath, '.cpp')
            || str_ends_with($relativePath, '.stub.php');
    }

    /**
     * @return array<string, string>
     */
    private function discoverFiles(string $directory, string $prefix): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $fileInfo->getPathname());
            $baseDir = str_replace('\\', '/', rtrim($directory, '/\\'));
            $relativeSuffix = ltrim(substr($path, strlen($baseDir)), '/');
            $files[$prefix . '/' . $relativeSuffix] = $fileInfo->getPathname();
        }

        return $files;
    }

    /**
     * @return array{schema_version?: int, source_dir?: string, target_dir?: string, files?: list<string>}
     */
    private function loadManifest(string $targetDir): array
    {
        $path = $targetDir . '/' . self::MANIFEST_FILENAME;
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function removeEmptyParentDirectories(string $directory, string $rootDir): void
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $rootDir), '/');
        $current = rtrim(str_replace('\\', '/', $directory), '/');

        while ($current !== '' && $current !== $normalizedRoot) {
            if (!is_dir($current)) {
                $current = dirname($current);
                continue;
            }

            $entries = scandir($current);
            if ($entries === false || count($entries) > 2) {
                return;
            }

            if (!@rmdir($current) && is_dir($current)) {
                return;
            }

            $current = dirname($current);
        }
    }

    private function normalizePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Static stage path cannot be empty.');
        }

        $real = realpath($trimmed);
        if ($real !== false) {
            return rtrim(str_replace('\\', '/', $real), '/');
        }

        if ($this->isAbsolutePath($trimmed)) {
            return rtrim(str_replace('\\', '/', $trimmed), '/');
        }

        $cwd = getcwd();
        if (!is_string($cwd) || $cwd === '') {
            return rtrim(str_replace('\\', '/', $trimmed), '/');
        }

        return rtrim(str_replace('\\', '/', $cwd . '/' . ltrim($trimmed, '/\\')), '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
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
