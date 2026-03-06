<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class RuntimeManifest
{
    public const int SCHEMA_VERSION = 1;
    public const string MODE_MONOLITHIC = 'monolithic';
    public const string MODE_MODULAR = 'modular';
    public const string BUILDER_ABI_VERSION = 'phpqt-builder-abi-v1';

    /**
     * @param list<string> $builtModules
     * @param list<string> $buildOrder
     * @param array<string, RuntimeModuleMetadata> $modules
     */
    public function __construct(
        public string $buildMode,
        public string $qtVersion,
        public int $qtVersionMajor,
        public int $qtVersionMinor,
        public int $qtVersionPatch,
        public string $extensionVersion,
        public string $builderAbiVersion,
        public array $builtModules,
        public array $buildOrder,
        public array $modules,
    ) {}

    public function module(string $module): ?RuntimeModuleMetadata
    {
        return $this->modules[$module] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $modules = [];
        foreach ($this->modules as $module => $metadata) {
            $modules[$module] = $metadata->toArray();
            unset($modules[$module]['loaded']);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'build_mode' => $this->buildMode,
            'qt_version' => $this->qtVersion,
            'qt_version_major' => $this->qtVersionMajor,
            'qt_version_minor' => $this->qtVersionMinor,
            'qt_version_patch' => $this->qtVersionPatch,
            'extension_version' => $this->extensionVersion,
            'builder_abi_version' => $this->builderAbiVersion,
            'built_modules' => array_values($this->builtModules),
            'build_order' => array_values($this->buildOrder),
            'modules' => $modules,
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
            throw new \RuntimeException('Could not encode runtime manifest.');
        }

        file_put_contents($path, $encoded . "\n");
    }

    public static function load(string $path): self
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Could not decode runtime manifest: %s', $path));
        }

        $modules = [];
        foreach (($decoded['modules'] ?? []) as $module => $metadata) {
            if (!is_string($module) || !is_array($metadata)) {
                continue;
            }

            $modules[$module] = RuntimeModuleMetadata::fromArray($metadata);
        }

        return new self(
            buildMode: is_string($decoded['build_mode'] ?? null) ? $decoded['build_mode'] : self::MODE_MONOLITHIC,
            qtVersion: is_string($decoded['qt_version'] ?? null) ? $decoded['qt_version'] : '',
            qtVersionMajor: max(0, (int) ($decoded['qt_version_major'] ?? 0)),
            qtVersionMinor: max(0, (int) ($decoded['qt_version_minor'] ?? 0)),
            qtVersionPatch: max(0, (int) ($decoded['qt_version_patch'] ?? 0)),
            extensionVersion: is_string($decoded['extension_version'] ?? null) ? $decoded['extension_version'] : '',
            builderAbiVersion: is_string($decoded['builder_abi_version'] ?? null) ? $decoded['builder_abi_version'] : self::BUILDER_ABI_VERSION,
            builtModules: self::filterStringList($decoded['built_modules'] ?? []),
            buildOrder: self::filterStringList($decoded['build_order'] ?? []),
            modules: $modules,
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
}
