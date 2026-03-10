<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use CParser\TranslationUnit;
use CParser\TranslationUnitFlags;
use QtBuilder\Parsing\ClangArgumentBuilder;

class IncludeGraphResolver
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    /**
     * @param list<string> $includePaths
     * @return list<string>
     */
    public function transitiveIncludes(string $headerPath, array $includePaths): array
    {
        $normalizedHeader = $this->normalizePath($headerPath);
        if (!is_file($normalizedHeader)) {
            return [];
        }

        $normalizedIncludePaths = $this->normalizeIncludePaths($includePaths);
        $cacheKey = $this->cacheKey($normalizedHeader, $normalizedIncludePaths);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $resolved = $this->fromTranslationUnit($normalizedHeader, $normalizedIncludePaths);
        if ($resolved === null) {
            $resolved = $this->fromSourceGraph($normalizedHeader, $normalizedIncludePaths);
        }

        $resolved = array_values(array_unique(array_map(
            fn(string $path): string => $this->normalizePath($path),
            $resolved,
        )));
        $resolved = array_values(array_filter(
            $resolved,
            static fn(string $path): bool => $path !== '' && $path !== $normalizedHeader,
        ));
        sort($resolved);

        $this->cache[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * @param list<string> $includePaths
     * @return list<string>|null
     */
    private function fromTranslationUnit(string $headerPath, array $includePaths): ?array
    {
        if (!method_exists(TranslationUnit::class, 'includes')) {
            return null;
        }

        try {
            $tu = TranslationUnit::fromFile(
                $headerPath,
                (new ClangArgumentBuilder($includePaths))->build(),
                TranslationUnitFlags::KeepGoing
                    | TranslationUnitFlags::SkipFunctionBodies
                    | TranslationUnitFlags::DetailedPreprocessingRecord,
            );
        } catch (\Throwable) {
            return null;
        }

        $resolved = [];
        foreach ($tu->includes() as $entry) {
            $included = $entry->getIncludedFile();
            if (!is_string($included) || $included === '') {
                continue;
            }

            $normalized = $this->normalizePath($included);
            if ($normalized === '' || !is_file($normalized)) {
                continue;
            }

            $resolved[] = $normalized;
        }

        return $resolved;
    }

    /**
     * @param list<string> $includePaths
     * @return list<string>
     */
    private function fromSourceGraph(string $headerPath, array $includePaths): array
    {
        /** @var array<string, bool> $visited */
        $visited = [];
        /** @var array<string, bool> $resolved */
        $resolved = [];
        /** @var list<string> $queue */
        $queue = [$headerPath];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_string($current) || $current === '') {
                continue;
            }

            $current = $this->normalizePath($current);
            if ($current === '' || isset($visited[$current]) || !is_file($current)) {
                continue;
            }
            $visited[$current] = true;

            $contents = (string) file_get_contents($current);
            if (preg_match_all('/^\s*#\s*include\s*[<"]([^">]+)[">]/m', $contents, $matches) !== 1) {
                continue;
            }

            foreach ($matches[1] as $include) {
                if (!is_string($include) || $include === '') {
                    continue;
                }

                $resolvedPath = $this->resolveIncludePath($include, $current, $includePaths);
                if ($resolvedPath === null) {
                    continue;
                }

                $resolved[$resolvedPath] = true;
                if (!isset($visited[$resolvedPath])) {
                    $queue[] = $resolvedPath;
                }
            }
        }

        return array_keys($resolved);
    }

    /**
     * @param list<string> $includePaths
     */
    private function resolveIncludePath(string $include, string $sourceHeader, array $includePaths): ?string
    {
        $candidates = [dirname($sourceHeader) . '/' . $include];

        foreach ($includePaths as $includePath) {
            $candidates[] = rtrim($includePath, '/') . '/' . ltrim($include, '/');
            $candidates[] = rtrim(dirname($includePath), '/') . '/' . ltrim($include, '/');
        }

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizePath($candidate);
            if ($normalized !== '' && is_file($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param list<string> $includePaths
     * @return list<string>
     */
    private function normalizeIncludePaths(array $includePaths): array
    {
        $normalized = [];
        foreach ($includePaths as $includePath) {
            if (!is_string($includePath) || $includePath === '' || str_starts_with($includePath, '-')) {
                continue;
            }

            $normalized[] = rtrim($this->normalizePath($includePath), '/');
        }

        $normalized = array_values(array_unique(array_filter(
            $normalized,
            static fn(string $path): bool => $path !== '',
        )));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param list<string> $includePaths
     */
    private function cacheKey(string $headerPath, array $includePaths): string
    {
        return hash('sha256', json_encode([
            'header' => $headerPath,
            'include_paths' => $includePaths,
        ], JSON_UNESCAPED_SLASHES) ?: $headerPath);
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }

        return str_replace('\\', '/', $path);
    }
}
