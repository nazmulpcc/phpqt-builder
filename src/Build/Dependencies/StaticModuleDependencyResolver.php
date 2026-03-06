<?php

declare(strict_types=1);

namespace QtBuilder\Build\Dependencies;

final class StaticModuleDependencyResolver implements ModuleDependencyResolver
{
    /**
     * @var array<string, array{extension_name: string, dependencies: list<string>}>|null
     */
    private ?array $definitions = null;
    private readonly string $manifestPath;

    public function __construct(?string $manifestPath = null)
    {
        $this->manifestPath = $manifestPath ?? dirname(__DIR__, 3) . '/manifest.json';
    }

    public function resolve(array $requestedModules): ResolvedModuleGraph
    {
        $requestedModules = $this->normalizeModules($requestedModules);
        if ($requestedModules === []) {
            $requestedModules = ['QtCore'];
        }

        $definitions = $this->definitions();
        $supportedModules = array_keys($definitions);
        $unsupported = array_values(array_filter(
            $requestedModules,
            static fn(string $module): bool => !isset($definitions[$module]),
        ));

        if ($unsupported !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported Qt module%s: %s. Supported modules: %s',
                count($unsupported) === 1 ? '' : 's',
                implode(', ', $unsupported),
                implode(', ', $supportedModules),
            ));
        }

        $resolvedSet = [];
        $stack = array_reverse($requestedModules);
        while ($stack !== []) {
            $module = array_pop($stack);
            if (!is_string($module) || $module === '' || isset($resolvedSet[$module])) {
                continue;
            }

            $resolvedSet[$module] = true;
            foreach ($definitions[$module]['dependencies'] as $dependencyModule) {
                $stack[] = $dependencyModule;
            }
        }

        $resolvedModules = array_values(array_filter(
            $supportedModules,
            static fn(string $module): bool => isset($resolvedSet[$module]),
        ));

        /** @var array<string, list<string>> $dependencies */
        $dependencies = [];
        /** @var array<string, string> $extensionNames */
        $extensionNames = [];
        foreach ($resolvedModules as $module) {
            $dependencies[$module] = array_values(array_filter(
                $definitions[$module]['dependencies'],
                static fn(string $dependencyModule): bool => isset($resolvedSet[$dependencyModule]),
            ));
            $extensionNames[$module] = $definitions[$module]['extension_name'];
        }

        $orderIndex = array_flip($supportedModules);

        return new ResolvedModuleGraph(
            requestedModules: $requestedModules,
            dependencies: $dependencies,
            buildOrder: $this->topologicalSort($resolvedModules, $dependencies, $orderIndex),
            extensionNames: $extensionNames,
        );
    }

    public function supportedModules(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * @return array<string, array{extension_name: string, dependencies: list<string>}>
     */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        if (!is_file($this->manifestPath)) {
            throw new \RuntimeException(sprintf('Static module dependency manifest not found: %s', $this->manifestPath));
        }

        $decoded = json_decode((string) file_get_contents($this->manifestPath), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Could not decode static module dependency manifest: %s', $this->manifestPath));
        }

        $modules = $decoded['modules'] ?? null;
        if (!is_array($modules) || $modules === []) {
            throw new \RuntimeException(sprintf('Static module dependency manifest is missing a modules map: %s', $this->manifestPath));
        }

        /** @var array<string, array{extension_name: string, dependencies: list<string>}> $definitions */
        $definitions = [];
        foreach ($modules as $module => $payload) {
            if (!is_string($module) || $module === '' || !is_array($payload)) {
                continue;
            }

            $extensionName = is_string($payload['extension_name'] ?? null)
                ? trim((string) $payload['extension_name'])
                : '';
            if ($extensionName === '') {
                throw new \RuntimeException(sprintf(
                    'Static module dependency manifest entry %s is missing an extension_name.',
                    $module,
                ));
            }

            $dependencies = array_values(array_filter(
                array_map(
                    static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                    $payload['dependencies'] ?? [],
                ),
                static fn(string $value): bool => $value !== '',
            ));

            $definitions[$module] = [
                'extension_name' => $extensionName,
                'dependencies' => array_values(array_unique($dependencies)),
            ];
        }

        foreach ($definitions as $module => $payload) {
            foreach ($payload['dependencies'] as $dependencyModule) {
                if (!isset($definitions[$dependencyModule])) {
                    throw new \RuntimeException(sprintf(
                        'Static module dependency manifest entry %s references unknown dependency %s.',
                        $module,
                        $dependencyModule,
                    ));
                }
            }
        }

        $this->definitions = $definitions;

        return $this->definitions;
    }

    /**
     * @param list<string> $requestedModules
     * @param array<string, list<string>> $dependencies
     * @param array<string, int> $orderIndex
     * @return list<string>
     */
    private function topologicalSort(array $requestedModules, array $dependencies, array $orderIndex): array
    {
        $inDegree = array_fill_keys($requestedModules, 0);
        /** @var array<string, list<string>> $reverseEdges */
        $reverseEdges = [];

        foreach ($dependencies as $module => $items) {
            foreach ($items as $dependencyModule) {
                $reverseEdges[$dependencyModule][] = $module;
                $inDegree[$module] = ($inDegree[$module] ?? 0) + 1;
            }
        }

        $ready = array_values(array_filter(
            $requestedModules,
            static fn(string $module): bool => ($inDegree[$module] ?? 0) === 0,
        ));

        $order = [];
        while ($ready !== []) {
            usort(
                $ready,
                static fn(string $left, string $right): int => ($orderIndex[$left] ?? PHP_INT_MAX) <=> ($orderIndex[$right] ?? PHP_INT_MAX),
            );

            $module = array_shift($ready);
            if ($module === null) {
                break;
            }

            $order[] = $module;

            foreach ($reverseEdges[$module] ?? [] as $dependentModule) {
                $inDegree[$dependentModule] = max(0, ($inDegree[$dependentModule] ?? 0) - 1);
                if ($inDegree[$dependentModule] === 0) {
                    $ready[] = $dependentModule;
                }
            }
        }

        if (count($order) !== count($requestedModules)) {
            $cycleModules = array_values(array_filter(
                $requestedModules,
                static fn(string $module): bool => !in_array($module, $order, true),
            ));

            throw new \RuntimeException(sprintf(
                'Module dependency cycle detected in static manifest: %s.',
                implode(' -> ', $cycleModules),
            ));
        }

        return $order;
    }

    /**
     * @param list<string> $modules
     * @return list<string>
     */
    private function normalizeModules(array $modules): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn(string $module): string => trim($module), $modules),
            static fn(string $module): bool => $module !== '',
        )));
    }
}
