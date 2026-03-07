<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Scanning\HeaderCandidate;

class EnumHolderCache
{
    private const SCHEMA_VERSION = 2;

    /**
     * @param list<string> $includePaths
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $skippedClasses
     * @param array<string, string> $classNamespaces
     * @param list<EnumCandidateHeader> $queuedHeaders
     */
    public function load(
        string $metadataDir,
        array $includePaths,
        array $acceptedCandidates,
        array $skippedClasses,
        array $classNamespaces,
        array $queuedHeaders = [],
    ): ?EnumHolderRegistry {
        $path = $this->path($metadataDir);
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }

        if ((int) ($decoded['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return null;
        }

        $cacheKey = (string) ($decoded['cache_key'] ?? '');
        if ($cacheKey === '' || $cacheKey !== $this->cacheKey($includePaths, $acceptedCandidates, $skippedClasses, $classNamespaces, $queuedHeaders)) {
            return null;
        }

        $holdersPayload = $decoded['holders'] ?? null;
        if (!is_array($holdersPayload)) {
            return null;
        }

        /** @var array<string, EnumHolderDefinition> $holders */
        $holders = [];
        foreach ($holdersPayload as $holderPayload) {
            if (!is_array($holderPayload)) {
                continue;
            }

            $module = is_string($holderPayload['module'] ?? null) ? $holderPayload['module'] : '';
            $cppType = is_string($holderPayload['cpp_type'] ?? null) ? $holderPayload['cpp_type'] : '';
            $phpNamespace = is_string($holderPayload['php_namespace'] ?? null) ? $holderPayload['php_namespace'] : '';
            $phpClassName = is_string($holderPayload['php_class_name'] ?? null) ? $holderPayload['php_class_name'] : '';
            if ($module === '' || $cppType === '' || $phpNamespace === '' || $phpClassName === '') {
                continue;
            }

            $constants = [];
            foreach (($holderPayload['constants'] ?? []) as $constantPayload) {
                if (!is_array($constantPayload)) {
                    continue;
                }

                $name = is_string($constantPayload['name'] ?? null) ? $constantPayload['name'] : '';
                $value = $constantPayload['value'] ?? null;
                if ($name === '' || (!is_int($value) && !is_float($value) && !is_string($value))) {
                    continue;
                }

                $constants[] = new EnumHolderConstant($name, $value);
            }

            if ($constants === []) {
                continue;
            }

            $holders[$cppType] = new EnumHolderDefinition(
                module: $module,
                cppType: $cppType,
                phpNamespace: $phpNamespace,
                phpClassName: $phpClassName,
                constants: $constants,
                isFlagAlias: (bool) ($holderPayload['is_flag_alias'] ?? false),
                sourceCppType: is_string($holderPayload['source_cpp_type'] ?? null) ? $holderPayload['source_cpp_type'] : null,
                headerPath: is_string($holderPayload['header'] ?? null) ? $holderPayload['header'] : '',
            );
        }

        ksort($holders);

        return new EnumHolderRegistry($holders);
    }

    /**
     * @param list<string> $includePaths
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $skippedClasses
     * @param array<string, string> $classNamespaces
     * @param list<EnumCandidateHeader> $queuedHeaders
     */
    public function write(
        string $metadataDir,
        array $includePaths,
        array $acceptedCandidates,
        array $skippedClasses,
        array $classNamespaces,
        EnumHolderRegistry $registry,
        array $queuedHeaders = [],
    ): string {
        $path = $this->path($metadataDir);
        @mkdir($metadataDir, 0755, true);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'cache_key' => $this->cacheKey($includePaths, $acceptedCandidates, $skippedClasses, $classNamespaces, $queuedHeaders),
            'holders' => array_map(
                static fn(EnumHolderDefinition $holder): array => $holder->toArray(),
                $registry->holders(),
            ),
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return $path;
    }

    public function path(string $metadataDir): string
    {
        return $metadataDir . '/enum_holders_cache.json';
    }

    /**
     * @param list<string> $includePaths
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $skippedClasses
     * @param array<string, string> $classNamespaces
     * @param list<EnumCandidateHeader> $queuedHeaders
     */
    private function cacheKey(
        array $includePaths,
        array $acceptedCandidates,
        array $skippedClasses,
        array $classNamespaces,
        array $queuedHeaders = [],
    ): string {
        $normalizedIncludePaths = array_values(array_unique(array_map(
            static fn(string $path): string => self::normalizePath($path),
            array_values(array_filter($includePaths, 'is_string')),
        )));
        sort($normalizedIncludePaths);

        $classNamespacePayload = [];
        foreach ($classNamespaces as $className => $namespace) {
            if (!is_string($className) || !is_string($namespace)) {
                continue;
            }

            $classNamespacePayload[$className] = $namespace;
        }
        ksort($classNamespacePayload);

        /** @var array<string, array{module: string, stat: string}> $headers */
        $headers = [];
        foreach ($acceptedCandidates as $candidate) {
            $headers[$candidate->parseHeader] = [
                'module' => $candidate->module,
                'stat' => $this->fileSignature($candidate->parseHeader),
            ];
        }

        foreach ($skippedClasses as $entry) {
            $header = is_string($entry['header'] ?? null) ? $entry['header'] : '';
            $module = is_string($entry['module'] ?? null) ? $entry['module'] : '';
            if ($header === '' || $module === '') {
                continue;
            }

            $headers[$header] ??= [
                'module' => $module,
                'stat' => $this->fileSignature($header),
            ];
        }

        /** @var array<string, array{module: string, types: list<string>}> $queued */
        $queued = [];
        foreach ($queuedHeaders as $entry) {
            if (!$entry instanceof EnumCandidateHeader || $entry->header === '' || $entry->module === '') {
                continue;
            }

            $types = array_values(array_filter(array_map(
                static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                $entry->types,
            ), static fn(string $value): bool => $value !== ''));
            sort($types);

            $queued[$entry->header] = [
                'module' => $entry->module,
                'types' => $types,
            ];
            $headers[$entry->header] ??= [
                'module' => $entry->module,
                'stat' => $this->fileSignature($entry->header),
            ];
        }

        ksort($headers);
        ksort($queued);

        return hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'include_paths' => $normalizedIncludePaths,
            'class_namespaces' => $classNamespacePayload,
            'headers' => $headers,
            'queued_headers' => $queued,
        ], JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function fileSignature(string $path): string
    {
        $normalized = self::normalizePath($path);
        $mtime = @filemtime($path);
        $size = @filesize($path);

        return $normalized . '|' . (($mtime === false) ? 'missing' : (string) $mtime) . '|' . (($size === false) ? 'missing' : (string) $size);
    }

    private static function normalizePath(string $path): string
    {
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }
}
