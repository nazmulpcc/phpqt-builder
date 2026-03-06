<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Scanning\HeaderCandidate;

final readonly class ImportedModuleAbi
{
    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<string> $availableClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     */
    public function __construct(
        public ModuleAbiManifest $manifest,
        public array $acceptedCandidates,
        public array $availableClasses,
        public array $preparedClassDataByClass,
    ) {}

    public static function load(string $manifestPath): self
    {
        $manifest = ModuleAbiManifest::load($manifestPath);
        $acceptedCandidates = self::loadAcceptedCandidates($manifest->acceptedCandidatesPath);
        $preparedClassDataByClass = self::loadPreparedClassData($manifest);

        return new self(
            manifest: $manifest,
            acceptedCandidates: $acceptedCandidates,
            availableClasses: $manifest->classes,
            preparedClassDataByClass: $preparedClassDataByClass,
        );
    }

    /**
     * @return list<string>
     */
    public function includeDirs(): array
    {
        return $this->manifest->includeDirs;
    }

    /**
     * @return array<string, string>
     */
    public function classNamespaces(): array
    {
        return $this->manifest->classNamespaces;
    }

    /**
     * @param array<string, array<string, mixed>> $localClassData
     * @return array<string, array<string, mixed>>
     */
    public function mergePreparedClassData(array $localClassData): array
    {
        return array_replace($this->preparedClassDataByClass, $localClassData);
    }

    /**
     * @return list<HeaderCandidate>
     */
    private static function loadAcceptedCandidates(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        $acceptedCandidates = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $module = is_string($entry['module'] ?? null) ? $entry['module'] : null;
            $class = is_string($entry['class'] ?? null) ? $entry['class'] : null;
            $publicHeader = is_string($entry['public_header'] ?? null) ? $entry['public_header'] : null;
            $parseHeader = is_string($entry['parse_header'] ?? null) ? $entry['parse_header'] : null;

            if ($module === null || $class === null || $publicHeader === null || $parseHeader === null) {
                continue;
            }

            $acceptedCandidates[] = new HeaderCandidate($module, $class, $publicHeader, $parseHeader);
        }

        return $acceptedCandidates;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadPreparedClassData(ModuleAbiManifest $manifest): array
    {
        $prepared = [];

        foreach ($manifest->classes as $className) {
            $path = $manifest->classCacheDir . '/' . self::safeClassCacheName($className) . '.json';
            if (!is_file($path)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true);
            if (!is_array($decoded)) {
                continue;
            }

            $classData = $decoded['payload']['class_data'] ?? null;
            if (!is_array($classData)) {
                continue;
            }

            $prepared[$className] = $classData;
        }

        return $prepared;
    }

    private static function safeClassCacheName(string $className): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $className) ?? $className;
    }
}
