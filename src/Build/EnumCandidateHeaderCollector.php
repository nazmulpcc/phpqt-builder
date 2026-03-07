<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Scanning\HeaderCandidate;

class EnumCandidateHeaderCollector
{
    /** @var array<string, list<string>> */
    private array $resolvedIncludeCache = [];

    /**
     * @param list<string> $includePaths
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @return list<EnumCandidateHeader>
     */
    public function collect(
        array $includePaths,
        array $acceptedCandidates,
        array $preparedClassDataByClass,
    ): array {
        /** @var array<string, HeaderCandidate> $candidatesByClass */
        $candidatesByClass = [];
        foreach ($acceptedCandidates as $candidate) {
            $candidatesByClass[$candidate->className] = $candidate;
        }

        /** @var array<string, array{module: string, types: array<string, bool>}> $queued */
        $queued = [];
        foreach ($acceptedCandidates as $candidate) {
            $classData = $preparedClassDataByClass[$candidate->className] ?? null;
            if (!is_array($classData)) {
                continue;
            }

            $types = $this->enumLikeTypesForClassData($candidate->className, $classData);
            foreach ($types as $type) {
                $header = $this->resolveBestHeader(
                    $type,
                    $candidate,
                    $includePaths,
                    $candidatesByClass,
                );

                $queued[$header]['module'] = $this->moduleForHeaderPath($header, $candidate->module);
                $queued[$header]['types'][$type] = true;
            }
        }

        ksort($queued);

        $entries = [];
        foreach ($queued as $header => $entry) {
            $types = array_keys($entry['types']);
            sort($types);
            $entries[] = new EnumCandidateHeader(
                header: $header,
                module: $entry['module'],
                types: $types,
            );
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $classData
     * @return list<string>
     */
    private function enumLikeTypesForClassData(string $className, array $classData): array
    {
        /** @var array<string, bool> $types */
        $types = [];

        foreach ((array) ($classData['properties'] ?? []) as $property) {
            if (!is_array($property)) {
                continue;
            }

            foreach ($this->enumLikeTypesFromTypeString(
                (string) ($property['type'] ?? ''),
                $className,
                $classData,
            ) as $type) {
                $types[$type] = true;
            }
        }

        foreach ((array) ($classData['methods'] ?? []) as $method) {
            if (!is_array($method)) {
                continue;
            }

            foreach ($this->enumLikeTypesFromTypeString(
                (string) ($method['return_type'] ?? ''),
                $className,
                $classData,
            ) as $type) {
                $types[$type] = true;
            }

            foreach ((array) ($method['parameters'] ?? []) as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }

                foreach ($this->enumLikeTypesFromTypeString(
                    (string) ($parameter['type'] ?? ''),
                    $className,
                    $classData,
                ) as $type) {
                    $types[$type] = true;
                }
            }
        }

        $resolved = array_keys($types);
        sort($resolved);

        return $resolved;
    }

    /**
     * @param array<string, mixed> $classData
     * @return list<string>
     */
    private function enumLikeTypesFromTypeString(string $type, string $className, array $classData): array
    {
        $normalized = $this->normalizeType($type);
        if ($normalized === '' || $normalized === 'void') {
            return [];
        }

        /** @var array<string, bool> $matches */
        $matches = [];

        foreach ($this->extractQFlagsInnerTypes($normalized) as $innerType) {
            $matches[$innerType] = true;
        }

        if (preg_match_all('/\b(?:Qt|[A-Z][A-Za-z0-9_]*)(?:::[A-Za-z_][A-Za-z0-9_]*)+/', $normalized, $cppMatches) === 1) {
            foreach ($cppMatches[0] as $cppType) {
                $matches[$this->normalizeType($cppType)] = true;
            }
        }

        $baseType = $this->baseTypeName($normalized);
        if ($baseType !== '') {
            $enumNames = is_array($classData['enum_names'] ?? null) ? $classData['enum_names'] : [];
            foreach ($enumNames as $enumName) {
                if (is_string($enumName) && trim($enumName) === $baseType) {
                    $matches[$className . '::' . $baseType] = true;
                }
            }

            $flagAliases = is_array($classData['flag_aliases'] ?? null) ? $classData['flag_aliases'] : [];
            foreach ($flagAliases as $alias => $sourceEnum) {
                if (!is_string($alias) || trim($alias) !== $baseType) {
                    continue;
                }

                $matches[$className . '::' . $baseType] = true;
                if (is_string($sourceEnum) && trim($sourceEnum) !== '') {
                    $matches[$className . '::' . trim($sourceEnum)] = true;
                }
            }
        }

        $resolved = array_values(array_filter(array_keys($matches), static fn(string $value): bool => $value !== ''));
        sort($resolved);

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function extractQFlagsInnerTypes(string $type): array
    {
        $matches = [];
        preg_match_all('/QFlags\s*<\s*([^>]+)\s*>/', $type, $matches);

        $resolved = [];
        foreach ($matches[1] ?? [] as $innerType) {
            if (!is_string($innerType)) {
                continue;
            }

            $normalized = $this->normalizeType($innerType);
            if ($normalized !== '') {
                $resolved[] = $normalized;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param array<string, HeaderCandidate> $candidatesByClass
     */
    private function resolveBestHeader(
        string $enumType,
        HeaderCandidate $candidate,
        array $includePaths,
        array $candidatesByClass,
    ): string {
        $normalizedType = $this->normalizeType($enumType);
        if ($normalizedType === '') {
            return $candidate->parseHeader;
        }

        $baseType = $this->baseTypeName($normalizedType);
        if ($baseType === '') {
            return $candidate->parseHeader;
        }

        $owner = explode('::', $baseType)[0] ?? '';
        if ($owner === '' || $owner === $candidate->className) {
            return $candidate->parseHeader;
        }

        if (isset($candidatesByClass[$owner])) {
            return $candidatesByClass[$owner]->parseHeader;
        }

        $transitiveIncludes = $this->transitiveIncludes($candidate->parseHeader, $includePaths);
        if ($owner === 'Qt') {
            foreach ($transitiveIncludes as $includePath) {
                if (basename($includePath) === 'qnamespace.h') {
                    return $includePath;
                }
            }

            return $candidate->parseHeader;
        }

        $ownerLower = strtolower($owner);
        $preferredBasenames = [
            $ownerLower . '.h',
            $ownerLower . 'global.h',
        ];
        foreach ($transitiveIncludes as $includePath) {
            $basename = strtolower(basename($includePath));
            if (in_array($basename, $preferredBasenames, true)) {
                return $includePath;
            }
        }

        foreach ($transitiveIncludes as $includePath) {
            $basename = strtolower(basename($includePath));
            if (str_contains($basename, $ownerLower)) {
                return $includePath;
            }
        }

        return $candidate->parseHeader;
    }

    /**
     * @param list<string> $includePaths
     * @return list<string>
     */
    private function transitiveIncludes(string $headerPath, array $includePaths): array
    {
        if (isset($this->resolvedIncludeCache[$headerPath])) {
            return $this->resolvedIncludeCache[$headerPath];
        }

        /** @var array<string, bool> $visited */
        $visited = [];
        /** @var list<string> $queue */
        $queue = [$headerPath];
        /** @var array<string, bool> $resolved */
        $resolved = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_string($current) || $current === '' || isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;
            if (!is_file($current)) {
                continue;
            }

            $contents = (string) file_get_contents($current);
            if (preg_match_all('/^\s*#\s*include\s*[<"]([^">]+)[">]/m', $contents, $matches) !== 1) {
                continue;
            }

            foreach ($matches[1] as $include) {
                if (!is_string($include)) {
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

        $paths = array_keys($resolved);
        sort($paths);
        $this->resolvedIncludeCache[$headerPath] = $paths;

        return $paths;
    }

    /**
     * @param list<string> $includePaths
     */
    private function resolveIncludePath(string $include, string $sourceHeader, array $includePaths): ?string
    {
        $candidates = [];

        $localCandidate = dirname($sourceHeader) . '/' . $include;
        $candidates[] = $localCandidate;
        foreach ($includePaths as $includePath) {
            if (!is_string($includePath) || $includePath === '') {
                continue;
            }

            $candidates[] = rtrim($includePath, '/') . '/' . ltrim($include, '/');
            $candidates[] = rtrim(dirname($includePath), '/') . '/' . ltrim($include, '/');
        }

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }

    private function normalizeType(string $type): string
    {
        $normalized = trim($type);
        $normalized = preg_replace('/\bconst\b/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bvolatile\b/', '', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
        $normalized = preg_replace('/\s*([<>,:&*])\s*/', '$1', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function baseTypeName(string $type): string
    {
        $normalized = $this->normalizeType($type);
        if ($normalized === '') {
            return '';
        }

        while (str_ends_with($normalized, '*') || str_ends_with($normalized, '&')) {
            $normalized = substr($normalized, 0, -1);
        }

        return trim($normalized);
    }

    private function moduleForHeaderPath(string $headerPath, string $fallbackModule): string
    {
        if (preg_match('/\/(Qt[A-Za-z0-9_]+)(?:\.framework(?:\/Versions\/[^\/]+)?\/Headers|\/)/', $headerPath, $matches) === 1) {
            return $matches[1];
        }

        return $fallbackModule;
    }
}
