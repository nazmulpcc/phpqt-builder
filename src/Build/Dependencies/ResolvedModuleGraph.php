<?php

declare(strict_types=1);

namespace QtBuilder\Build\Dependencies;

final readonly class ResolvedModuleGraph
{
    /**
     * @param list<string> $requestedModules
     * @param array<string, list<string>> $dependencies
     * @param list<string> $buildOrder
     * @param array<string, string> $extensionNames
     * @param list<string> $unmappedModules
     */
    public function __construct(
        public array $requestedModules,
        public array $dependencies,
        public array $buildOrder,
        public array $extensionNames,
        public array $unmappedModules = [],
        public string $dependencySource = 'static_manifest',
    ) {}

    /**
     * @return list<string>
     */
    public function expandedModules(): array
    {
        return $this->buildOrder;
    }

    /**
     * @return list<string>
     */
    public function autoAddedModules(): array
    {
        return array_values(array_filter(
            $this->buildOrder,
            fn (string $module): bool => !in_array($module, $this->requestedModules, true),
        ));
    }

    /**
     * @return list<string>
     */
    public function dependenciesFor(string $module): array
    {
        return $this->dependencies[$module] ?? [];
    }

    public function extensionNameFor(string $module): string
    {
        return $this->extensionNames[$module] ?? strtolower($module);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requested_modules' => array_values($this->requestedModules),
            'expanded_modules' => array_values($this->expandedModules()),
            'unmapped_modules' => array_values($this->unmappedModules),
            'dependency_source' => $this->dependencySource,
            'extension_names' => $this->extensionNames,
            'dependencies' => $this->dependencies,
            'build_order' => array_values($this->buildOrder),
        ];
    }
}
