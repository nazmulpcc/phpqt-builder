<?php

declare(strict_types=1);

namespace QtBuilder\Build;

class ModuleBuildGraphBuilder
{
    /**
     * @param list<string> $requestedModules
     */
    public function build(array $requestedModules, BuildAnalysisResult $analysis): ModuleBuildGraph
    {
        $moduleOrder = array_flip($requestedModules);
        /** @var array<string, list<string>> $dependencies */
        $dependencies = [];
        foreach ($requestedModules as $module) {
            $dependencies[$module] = [];
        }

        foreach ($analysis->generatedClasses as $className) {
            $ownerModule = $analysis->generatedClassModules[$className] ?? null;
            if (!is_string($ownerModule) || $ownerModule === '') {
                continue;
            }

            $this->addDependency(
                $dependencies,
                $ownerModule,
                $analysis->generatedClassParents[$className] ?? null,
                $analysis->generatedClassModules,
            );

            foreach ($analysis->generatedClassDependencies[$className] ?? [] as $dependencyClass) {
                $this->addDependency(
                    $dependencies,
                    $ownerModule,
                    $dependencyClass,
                    $analysis->generatedClassModules,
                );
            }

            $phpClass = $analysis->generatedPhpClasses[$className] ?? null;
            if ($phpClass !== null && $phpClass->signals !== [] && $ownerModule !== 'QtCore' && isset($dependencies['QtCore'])) {
                $this->pushDependency($dependencies, $ownerModule, 'QtCore');
            }
        }

        foreach ($dependencies as $module => $items) {
            usort(
                $items,
                static fn(string $left, string $right): int => ($moduleOrder[$left] ?? PHP_INT_MAX) <=> ($moduleOrder[$right] ?? PHP_INT_MAX),
            );
            $dependencies[$module] = array_values(array_unique($items));
        }

        return new ModuleBuildGraph(
            classOwnership: $analysis->generatedClassModules,
            dependencies: $dependencies,
            buildOrder: $this->topologicalSort($requestedModules, $dependencies, $moduleOrder),
        );
    }

    /**
     * @param array<string, list<string>> $dependencies
     * @param array<string, string> $classOwnership
     */
    private function addDependency(array &$dependencies, string $ownerModule, mixed $dependencyClass, array $classOwnership): void
    {
        if (!is_string($dependencyClass) || $dependencyClass === '') {
            return;
        }

        $dependencyModule = $classOwnership[$dependencyClass] ?? null;
        if (!is_string($dependencyModule) || $dependencyModule === '') {
            return;
        }

        $this->pushDependency($dependencies, $ownerModule, $dependencyModule);
    }

    /**
     * @param array<string, list<string>> $dependencies
     */
    private function pushDependency(array &$dependencies, string $ownerModule, string $dependencyModule): void
    {
        if ($ownerModule === $dependencyModule) {
            return;
        }

        $dependencies[$ownerModule] ??= [];
        $dependencies[$dependencyModule] ??= [];
        $dependencies[$ownerModule][] = $dependencyModule;
    }

    /**
     * @param list<string> $requestedModules
     * @param array<string, list<string>> $dependencies
     * @param array<string, int> $moduleOrder
     * @return list<string>
     */
    private function topologicalSort(array $requestedModules, array $dependencies, array $moduleOrder): array
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
                static fn(string $left, string $right): int => ($moduleOrder[$left] ?? PHP_INT_MAX) <=> ($moduleOrder[$right] ?? PHP_INT_MAX),
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
                'Module dependency cycle detected: %s.',
                implode(' -> ', $cycleModules),
            ));
        }

        return $order;
    }
}
