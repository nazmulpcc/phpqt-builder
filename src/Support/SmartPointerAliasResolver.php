<?php

declare(strict_types=1);

namespace QtBuilder\Support;

final class SmartPointerAliasResolver
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    /**
     * @return array<string, string>
     */
    public function discover(string $headerPath): array
    {
        if (isset($this->cache[$headerPath])) {
            return $this->cache[$headerPath];
        }

        $seen = [];

        return $this->cache[$headerPath] = $this->discoverRecursive($headerPath, $seen);
    }

    public function resolve(string $headerPath, string $alias): ?string
    {
        $aliases = $this->discover($headerPath);

        return $aliases[trim($alias)] ?? null;
    }

    /**
     * @param array<string, bool> $seen
     * @return array<string, string>
     */
    private function discoverRecursive(string $headerPath, array &$seen): array
    {
        $realPath = realpath($headerPath) ?: $headerPath;
        if (isset($seen[$realPath])) {
            return [];
        }
        $seen[$realPath] = true;

        $contents = @file_get_contents($realPath);
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $aliases = $this->discoverFromContents($contents);
        foreach ($this->includedHeaders($realPath, $contents) as $includedHeader) {
            foreach ($this->discoverRecursive($includedHeader, $seen) as $alias => $target) {
                $aliases[$alias] ??= $target;
            }
        }

        return $aliases;
    }

    /**
     * @return array<string, string>
     */
    private function discoverFromContents(string $contents): array
    {
        $aliases = [];

        if (preg_match_all('/typedef\s+QSharedPointer<\s*([^>]+?)\s*>\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/', $contents, $matches, \PREG_SET_ORDER) === false) {
            return $aliases;
        }

        foreach ($matches as $match) {
            $target = trim((string) ($match[1] ?? ''));
            $alias = trim((string) ($match[2] ?? ''));
            if ($target === '' || $alias === '') {
                continue;
            }
            $aliases[$alias] = $target;
        }

        if (preg_match_all('/using\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*QSharedPointer<\s*([^>]+?)\s*>/', $contents, $usingMatches, \PREG_SET_ORDER) !== false) {
            foreach ($usingMatches as $match) {
                $alias = trim((string) ($match[1] ?? ''));
                $target = trim((string) ($match[2] ?? ''));
                if ($target === '' || $alias === '') {
                    continue;
                }
                $aliases[$alias] = $target;
            }
        }

        return $aliases;
    }

    /**
     * @return list<string>
     */
    private function includedHeaders(string $headerPath, string $contents): array
    {
        $candidates = [];
        if (preg_match_all('/#include\s+"([^"]+)"/', $contents, $quotedMatches) !== false) {
            foreach ($quotedMatches[1] as $include) {
                $candidate = dirname($headerPath) . '/' . $include;
                if (is_file($candidate)) {
                    $candidates[] = $candidate;
                }
            }
        }

        return array_values(array_unique($candidates));
    }
}
