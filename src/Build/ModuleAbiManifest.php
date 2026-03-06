<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class ModuleAbiManifest
{
    /**
     * @param list<string> $includeDirs
     * @param list<string> $sharedIncludeDirs
     * @param list<string> $dependencyModules
     * @param list<string> $classes
     * @param array<string, string> $classNamespaces
     */
    public function __construct(
        public string $module,
        public string $extensionName,
        public string $buildRootDir,
        public string $outputDir,
        public string $metadataDir,
        public string $acceptedCandidatesPath,
        public string $classCacheDir,
        public array $includeDirs,
        public array $sharedIncludeDirs,
        public array $dependencyModules,
        public array $classes,
        public array $classNamespaces,
        public bool $includesSignalConnectionSupport,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'extension_name' => $this->extensionName,
            'build_root_dir' => $this->buildRootDir,
            'output_dir' => $this->outputDir,
            'metadata_dir' => $this->metadataDir,
            'accepted_candidates_path' => $this->acceptedCandidatesPath,
            'class_cache_dir' => $this->classCacheDir,
            'include_dirs' => array_values($this->includeDirs),
            'shared_include_dirs' => array_values($this->sharedIncludeDirs),
            'dependency_modules' => array_values($this->dependencyModules),
            'classes' => array_values($this->classes),
            'class_namespaces' => $this->classNamespaces,
            'includes_signal_connection_support' => $this->includesSignalConnectionSupport,
        ];
    }

    public function write(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create directory: %s', $directory));
        }

        $encoded = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Could not encode module ABI manifest.');
        }

        file_put_contents($path, $encoded . "\n");
    }

    public static function load(string $path): self
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Could not decode module ABI manifest: %s', $path));
        }

        return new self(
            module: (string) ($decoded['module'] ?? ''),
            extensionName: (string) ($decoded['extension_name'] ?? ''),
            buildRootDir: (string) ($decoded['build_root_dir'] ?? ''),
            outputDir: (string) ($decoded['output_dir'] ?? ''),
            metadataDir: (string) ($decoded['metadata_dir'] ?? ''),
            acceptedCandidatesPath: (string) ($decoded['accepted_candidates_path'] ?? ''),
            classCacheDir: (string) ($decoded['class_cache_dir'] ?? ''),
            includeDirs: self::filterStringList($decoded['include_dirs'] ?? []),
            sharedIncludeDirs: self::filterStringList($decoded['shared_include_dirs'] ?? []),
            dependencyModules: self::filterStringList($decoded['dependency_modules'] ?? []),
            classes: self::filterStringList($decoded['classes'] ?? []),
            classNamespaces: self::filterStringMap($decoded['class_namespaces'] ?? []),
            includesSignalConnectionSupport: (bool) ($decoded['includes_signal_connection_support'] ?? false),
        );
    }

    /**
     * @return list<string>
     */
    private static function filterStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $entry): string => is_string($entry) ? $entry : '', $value),
            static fn(string $entry): bool => $entry !== '',
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function filterStringMap(mixed $value): array
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

        return $resolved;
    }
}
