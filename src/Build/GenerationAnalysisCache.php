<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Definition\PhpClass;
use QtBuilder\Scanning\HeaderCandidate;
use QtBuilder\Support\GeneratedTypeIdentity;

final class GenerationAnalysisCache
{
    private const SCHEMA_VERSION = 1;

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $initialSkippedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, string> $classNamespaces
     * @return array{
     *   accepted_candidates: list<HeaderCandidate>,
     *   generated_classes: list<string>,
     *   generated_php_classes: array<string, PhpClass>,
     *   module_generated_method_totals: array<string, int>,
     *   generated_class_parents: array<string, string|null>,
     *   generated_class_dependencies: array<string, list<string>>,
     *   generated_class_headers: array<string, string>,
     *   generated_class_modules: array<string, string>,
     *   synthetic_class_namespaces: array<string, string>,
     *   skipped_classes: list<array<string, string|null>>,
     *   skipped_methods: list<array<string, string>>, 
     *   errors: list<array<string, string|null>>,
     *   passes: int,
     *   requires_signal_connection_support: bool
     * }|null
     */
    public function load(
        string $metadataDir,
        BuildExecutionRequest $request,
        array $acceptedCandidates,
        array $initialSkippedClasses,
        array $preparedClassDataByClass,
        array $classNamespaces,
        EnumHolderRegistry $enumRegistry,
    ): ?array {
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
        if ($cacheKey === '' || $cacheKey !== $this->cacheKey(
            $request,
            $acceptedCandidates,
            $initialSkippedClasses,
            $preparedClassDataByClass,
            $classNamespaces,
            $enumRegistry,
        )) {
            return null;
        }

        $payload = $decoded['generation'] ?? null;
        if (!is_array($payload)) {
            return null;
        }

        return $this->hydrateGeneration($payload);
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $initialSkippedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, string> $classNamespaces
     * @param array{
     *   accepted_candidates: list<HeaderCandidate>,
     *   generated_classes: list<string>,
     *   generated_php_classes: array<string, PhpClass>,
     *   module_generated_method_totals: array<string, int>,
     *   generated_class_parents: array<string, string|null>,
     *   generated_class_dependencies: array<string, list<string>>,
     *   generated_class_headers: array<string, string>,
     *   generated_class_modules: array<string, string>,
     *   synthetic_class_namespaces: array<string, string>,
     *   skipped_classes: list<array<string, string|null>>,
     *   skipped_methods: list<array<string, string>>, 
     *   errors: list<array<string, string|null>>,
     *   passes: int,
     *   requires_signal_connection_support: bool
     * } $generation
     */
    public function write(
        string $metadataDir,
        BuildExecutionRequest $request,
        array $acceptedCandidates,
        array $initialSkippedClasses,
        array $preparedClassDataByClass,
        array $classNamespaces,
        EnumHolderRegistry $enumRegistry,
        array $generation,
    ): string {
        $path = $this->path($metadataDir);
        if (!is_dir($metadataDir) && !mkdir($metadataDir, 0755, true) && !is_dir($metadataDir)) {
            throw new \RuntimeException(sprintf('Could not create metadata directory: %s', $metadataDir));
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'cache_key' => $this->cacheKey(
                $request,
                $acceptedCandidates,
                $initialSkippedClasses,
                $preparedClassDataByClass,
                $classNamespaces,
                $enumRegistry,
            ),
            'generation' => $this->serializeGeneration($generation),
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return $path;
    }

    public function path(string $metadataDir): string
    {
        return $metadataDir . '/generation_analysis_cache.json';
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $initialSkippedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, string> $classNamespaces
     */
    private function cacheKey(
        BuildExecutionRequest $request,
        array $acceptedCandidates,
        array $initialSkippedClasses,
        array $preparedClassDataByClass,
        array $classNamespaces,
        EnumHolderRegistry $enumRegistry,
    ): string {
        return hash('sha256', json_encode($this->normalizeValue([
            'schema' => self::SCHEMA_VERSION,
            'request' => [
                'modules' => array_values($request->modules),
                'requested_modules' => $request->effectiveRequestedModules(),
                'expanded_modules' => $request->resolvedModuleGraph?->expandedModules() ?? $request->modules,
                'dependency_source' => $request->resolvedModuleGraph?->dependencySource ?? $request->dependencySource,
                'extension_name' => $request->extensionName,
                'imported_abi' => $this->normalizeImportedAbi($request->importedAbi),
            ],
            'accepted_candidates' => $this->serializeCandidates($acceptedCandidates),
            'initial_skipped_classes' => $this->normalizeSkippedClasses($initialSkippedClasses),
            'prepared_class_data' => $preparedClassDataByClass,
            'class_namespaces' => $classNamespaces,
            'enum_holders' => array_map(
                static fn(EnumHolderDefinition $holder): array => $holder->toArray(),
                $enumRegistry->holders(),
            ),
        ]), JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeImportedAbi(?ImportedModuleAbi $importedAbi): ?array
    {
        if (!$importedAbi instanceof ImportedModuleAbi) {
            return null;
        }

        return [
            'manifest' => $importedAbi->manifest->toArray(),
            'available_classes' => array_values($importedAbi->availableClasses),
            'accepted_candidates' => $this->serializeCandidates($importedAbi->acceptedCandidates),
        ];
    }

    /**
     * @param list<HeaderCandidate> $candidates
     * @return list<array<string, string|null>>
     */
    private function serializeCandidates(array $candidates): array
    {
        $serialized = array_values(array_map(
            function (HeaderCandidate $candidate): array {
                $publicHeader = $this->normalizeStringValue($candidate->publicHeader);
                $parseHeader = $this->normalizeStringValue($candidate->parseHeader);

                return [
                    'module' => $candidate->module,
                    'class' => $candidate->className,
                    'qualified_name' => $candidate->qualifiedClassName,
                    'generation_id' => $this->normalizedCandidateGenerationId($candidate, $parseHeader),
                    'public_header' => $publicHeader,
                    'parse_header' => $parseHeader,
                ];
            },
            $candidates,
        ));

        usort($serialized, static function (array $left, array $right): int {
            return strcmp(
                json_encode($left, JSON_UNESCAPED_SLASHES) ?: '',
                json_encode($right, JSON_UNESCAPED_SLASHES) ?: '',
            );
        });

        return $serialized;
    }

    private function normalizedCandidateGenerationId(HeaderCandidate $candidate, string $normalizedParseHeader): string
    {
        $qualifiedName = $candidate->qualifiedClassName;
        if (is_string($qualifiedName) && trim($qualifiedName) !== '') {
            return GeneratedTypeIdentity::fromNames(
                $candidate->className,
                $qualifiedName,
                $candidate->module,
            )->generationId;
        }

        return GeneratedTypeIdentity::provisional(
            $candidate->module,
            $candidate->className,
            $normalizedParseHeader,
        )->generationId;
    }

    /**
     * @param array{
     *   accepted_candidates: list<HeaderCandidate>,
     *   generated_classes: list<string>,
     *   generated_php_classes: array<string, PhpClass>,
     *   module_generated_method_totals: array<string, int>,
     *   generated_class_parents: array<string, string|null>,
     *   generated_class_dependencies: array<string, list<string>>,
     *   generated_class_headers: array<string, string>,
     *   generated_class_modules: array<string, string>,
     *   synthetic_class_namespaces: array<string, string>,
     *   skipped_classes: list<array<string, string|null>>,
     *   skipped_methods: list<array<string, string>>,
     *   errors: list<array<string, string|null>>,
     *   passes: int,
     *   requires_signal_connection_support: bool
     * } $generation
     * @return array<string, mixed>
     */
    private function serializeGeneration(array $generation): array
    {
        $generatedPhpClasses = [];
        foreach (($generation['generated_php_classes'] ?? []) as $className => $phpClass) {
            if (!is_string($className) || !$phpClass instanceof PhpClass) {
                continue;
            }

            $generatedPhpClasses[$className] = $phpClass->toArray();
        }
        ksort($generatedPhpClasses);

        return [
            'accepted_candidates' => $this->serializeCandidates($generation['accepted_candidates'] ?? []),
            'generated_classes' => array_values(array_filter(
                array_map(static fn(mixed $value): string => is_string($value) ? $value : '', $generation['generated_classes'] ?? []),
                static fn(string $value): bool => $value !== '',
            )),
            'generated_php_classes' => $generatedPhpClasses,
            'module_generated_method_totals' => $this->normalizeIntMap($generation['module_generated_method_totals'] ?? []),
            'generated_class_parents' => $this->normalizeNullableStringMap($generation['generated_class_parents'] ?? []),
            'generated_class_dependencies' => $this->normalizeStringListMap($generation['generated_class_dependencies'] ?? []),
            'generated_class_headers' => $this->normalizeStringMap($generation['generated_class_headers'] ?? []),
            'generated_class_modules' => $this->normalizeStringMap($generation['generated_class_modules'] ?? []),
            'synthetic_class_namespaces' => $this->normalizeStringMap($generation['synthetic_class_namespaces'] ?? []),
            'skipped_classes' => array_values(array_filter($generation['skipped_classes'] ?? [], 'is_array')),
            'skipped_methods' => array_values(array_filter($generation['skipped_methods'] ?? [], 'is_array')),
            'errors' => array_values(array_filter($generation['errors'] ?? [], 'is_array')),
            'passes' => max(0, (int) ($generation['passes'] ?? 0)),
            'requires_signal_connection_support' => (bool) ($generation['requires_signal_connection_support'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   accepted_candidates: list<HeaderCandidate>,
     *   generated_classes: list<string>,
     *   generated_php_classes: array<string, PhpClass>,
     *   module_generated_method_totals: array<string, int>,
     *   generated_class_parents: array<string, string|null>,
     *   generated_class_dependencies: array<string, list<string>>,
     *   generated_class_headers: array<string, string>,
     *   generated_class_modules: array<string, string>,
     *   synthetic_class_namespaces: array<string, string>,
     *   skipped_classes: list<array<string, string|null>>,
     *   skipped_methods: list<array<string, string>>, 
     *   errors: list<array<string, string|null>>,
     *   passes: int,
     *   requires_signal_connection_support: bool
     * }
     */
    private function hydrateGeneration(array $payload): array
    {
        $acceptedCandidates = [];
        foreach (($payload['accepted_candidates'] ?? []) as $candidatePayload) {
            if (!is_array($candidatePayload)) {
                continue;
            }

            $module = is_string($candidatePayload['module'] ?? null) ? $candidatePayload['module'] : '';
            $className = is_string($candidatePayload['class'] ?? null) ? $candidatePayload['class'] : '';
            $publicHeader = is_string($candidatePayload['public_header'] ?? null) ? $candidatePayload['public_header'] : '';
            $parseHeader = is_string($candidatePayload['parse_header'] ?? null) ? $candidatePayload['parse_header'] : '';
            if ($module === '' || $className === '' || $publicHeader === '' || $parseHeader === '') {
                continue;
            }

            $acceptedCandidates[] = new HeaderCandidate(
                module: $module,
                className: $className,
                publicHeader: $publicHeader,
                parseHeader: $parseHeader,
                qualifiedClassName: is_string($candidatePayload['qualified_name'] ?? null) ? $candidatePayload['qualified_name'] : null,
                generationId: is_string($candidatePayload['generation_id'] ?? null) ? $candidatePayload['generation_id'] : null,
            );
        }

        $generatedPhpClasses = [];
        foreach (($payload['generated_php_classes'] ?? []) as $className => $phpClassPayload) {
            if (!is_string($className) || !is_array($phpClassPayload)) {
                continue;
            }

            $generatedPhpClasses[$className] = PhpClass::fromArray($phpClassPayload);
        }
        ksort($generatedPhpClasses);

        return [
            'accepted_candidates' => $acceptedCandidates,
            'generated_classes' => array_values(array_filter(
                array_map(static fn(mixed $value): string => is_string($value) ? $value : '', $payload['generated_classes'] ?? []),
                static fn(string $value): bool => $value !== '',
            )),
            'generated_php_classes' => $generatedPhpClasses,
            'module_generated_method_totals' => $this->normalizeIntMap($payload['module_generated_method_totals'] ?? []),
            'generated_class_parents' => $this->normalizeNullableStringMap($payload['generated_class_parents'] ?? []),
            'generated_class_dependencies' => $this->normalizeStringListMap($payload['generated_class_dependencies'] ?? []),
            'generated_class_headers' => $this->normalizeStringMap($payload['generated_class_headers'] ?? []),
            'generated_class_modules' => $this->normalizeStringMap($payload['generated_class_modules'] ?? []),
            'synthetic_class_namespaces' => $this->normalizeStringMap($payload['synthetic_class_namespaces'] ?? []),
            'skipped_classes' => array_values(array_filter($payload['skipped_classes'] ?? [], 'is_array')),
            'skipped_methods' => array_values(array_filter($payload['skipped_methods'] ?? [], 'is_array')),
            'errors' => array_values(array_filter($payload['errors'] ?? [], 'is_array')),
            'passes' => max(0, (int) ($payload['passes'] ?? 0)),
            'requires_signal_connection_support' => (bool) ($payload['requires_signal_connection_support'] ?? false),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function normalizeIntMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $resolved = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $resolved[$key] = max(0, (int) $entry);
        }
        ksort($resolved);

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $resolved = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key) || !is_string($entry) || $key === '' || $entry === '') {
                continue;
            }

            $resolved[$key] = $entry;
        }
        ksort($resolved);

        return $resolved;
    }

    /**
     * @return array<string, string|null>
     */
    private function normalizeNullableStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $resolved = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $resolved[$key] = is_string($entry) ? $entry : null;
        }
        ksort($resolved);

        return $resolved;
    }

    /**
     * @return array<string, list<string>>
     */
    private function normalizeStringListMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $resolved = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key) || $key === '' || !is_array($entry)) {
                continue;
            }

            $resolved[$key] = array_values(array_filter(
                array_map(static fn(mixed $item): string => is_string($item) ? $item : '', $entry),
                static fn(string $item): bool => $item !== '',
            ));
            sort($resolved[$key]);
        }
        ksort($resolved);

        return $resolved;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->normalizeStringValue($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalizeValue($item), $value);
        }

        $normalized = [];
        $keys = array_keys($value);
        sort($keys);
        foreach ($keys as $key) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $normalized[(string) $key] = $this->normalizeValue($value[$key]);
        }

        return $normalized;
    }

    private function normalizeStringValue(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (!$this->looksLikePath($value)) {
            return $value;
        }

        $normalized = str_replace('\\', '/', $value);
        $normalized = preg_replace('#(?<!:)/{2,}#', '/', $normalized) ?? $normalized;

        if ($this->isWindowsAbsolutePath($normalized) || str_starts_with($normalized, '//')) {
            return strtolower($normalized);
        }

        return $normalized;
    }

    private function looksLikePath(string $value): bool
    {
        if ($this->isWindowsAbsolutePath($value) || str_starts_with($value, '\\\\') || str_starts_with($value, '//')) {
            return true;
        }

        return str_contains($value, '/');
    }

    private function isWindowsAbsolutePath(string $value): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $value) === 1;
    }

    /**
     * @param list<array<string, string|null>> $skippedClasses
     * @return list<array<string, string|null>>
     */
    private function normalizeSkippedClasses(array $skippedClasses): array
    {
        $normalized = array_values(array_filter($skippedClasses, 'is_array'));
        usort($normalized, static function (array $left, array $right): int {
            return strcmp(
                json_encode($left, JSON_UNESCAPED_SLASHES) ?: '',
                json_encode($right, JSON_UNESCAPED_SLASHES) ?: '',
            );
        });

        return $normalized;
    }
}
