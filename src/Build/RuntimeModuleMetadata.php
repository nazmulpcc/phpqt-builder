<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class RuntimeModuleMetadata
{
    /**
     * @param list<string> $dependencies
     * @param list<string> $namespaces
     */
    public function __construct(
        public string $module,
        public string $extensionName,
        public array $dependencies,
        public array $namespaces,
        public int $classCount,
        public bool $includesSignalConnectionSupport,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $loaded = false): array
    {
        return [
            'module' => $this->module,
            'extension_name' => $this->extensionName,
            'dependencies' => array_values($this->dependencies),
            'loaded' => $loaded,
            'namespaces' => array_values($this->namespaces),
            'class_count' => $this->classCount,
            'includes_signal_connection_support' => $this->includesSignalConnectionSupport,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            module: is_string($payload['module'] ?? null) ? $payload['module'] : '',
            extensionName: is_string($payload['extension_name'] ?? null) ? $payload['extension_name'] : '',
            dependencies: self::filterStringList($payload['dependencies'] ?? []),
            namespaces: self::filterStringList($payload['namespaces'] ?? []),
            classCount: max(0, (int) ($payload['class_count'] ?? 0)),
            includesSignalConnectionSupport: (bool) ($payload['includes_signal_connection_support'] ?? false),
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
