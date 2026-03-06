<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Definition\PhpClass;
use QtBuilder\Qt\QtInstallation;

final class RuntimeManifestBuilder
{
    public function buildForMonolithic(
        BuildExecutionRequest $request,
        BuildAnalysisResult $analysis,
        bool $includesSignalConnectionSupport,
    ): RuntimeManifest {
        return $this->buildManifest(
            buildMode: RuntimeManifest::MODE_MONOLITHIC,
            installation: $request->installation,
            extensionVersion: $request->extensionVersion,
            requestedModules: $request->modules,
            buildOrder: $request->modules,
            dependencies: $this->deriveDependencies($request->modules, $analysis),
            analysis: $analysis,
            extensionNameResolver: static fn(string $module): string => $request->extensionName,
            signalSupportResolver: static fn(string $module): bool => $includesSignalConnectionSupport,
        );
    }

    public function buildForModular(
        array $requestedModules,
        BuildAnalysisResult $analysis,
        ModuleBuildGraph $graph,
        string $extensionVersion,
        QtInstallation $installation,
        bool $includesSignalConnectionSupport,
    ): RuntimeManifest {
        return $this->buildManifest(
            buildMode: RuntimeManifest::MODE_MODULAR,
            installation: $installation,
            extensionVersion: $extensionVersion,
            requestedModules: $requestedModules,
            buildOrder: $graph->buildOrder,
            dependencies: $graph->dependencies,
            analysis: $analysis,
            extensionNameResolver: static fn(string $module): string => strtolower($module),
            signalSupportResolver: static fn(string $module): bool => $module === 'QtCore' && $includesSignalConnectionSupport,
        );
    }

    /**
     * @param list<string> $requestedModules
     * @param list<string> $buildOrder
     * @param array<string, list<string>> $dependencies
     */
    private function buildManifest(
        string $buildMode,
        QtInstallation $installation,
        string $extensionVersion,
        array $requestedModules,
        array $buildOrder,
        array $dependencies,
        BuildAnalysisResult $analysis,
        callable $extensionNameResolver,
        callable $signalSupportResolver,
    ): RuntimeManifest {
        $modules = [];
        foreach ($requestedModules as $module) {
            $modules[$module] = new RuntimeModuleMetadata(
                module: $module,
                extensionName: (string) $extensionNameResolver($module),
                dependencies: array_values($dependencies[$module] ?? []),
                namespaces: $this->namespacesForModule($analysis, $module),
                classCount: $this->classCountForModule($analysis, $module),
                includesSignalConnectionSupport: (bool) $signalSupportResolver($module),
            );
        }

        return new RuntimeManifest(
            buildMode: $buildMode,
            qtVersion: $installation->qtVersion,
            qtVersionMajor: $installation->qtVersionMajor,
            qtVersionMinor: $installation->qtVersionMinor,
            qtVersionPatch: $installation->qtVersionPatch,
            extensionVersion: $extensionVersion,
            builderAbiVersion: RuntimeManifest::BUILDER_ABI_VERSION,
            builtModules: array_values($buildOrder),
            buildOrder: array_values($buildOrder),
            modules: $modules,
        );
    }

    /**
     * @param list<string> $requestedModules
     * @return array<string, list<string>>
     */
    private function deriveDependencies(array $requestedModules, BuildAnalysisResult $analysis): array
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
            if ($phpClass instanceof PhpClass && $phpClass->signals !== [] && $ownerModule !== 'QtCore' && isset($dependencies['QtCore'])) {
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

        return $dependencies;
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
     * @return list<string>
     */
    private function namespacesForModule(BuildAnalysisResult $analysis, string $module): array
    {
        $namespaces = [];
        foreach ($analysis->generatedClassModules as $className => $ownerModule) {
            if ($ownerModule !== $module) {
                continue;
            }

            $namespace = $analysis->classNamespaces[$className] ?? null;
            if (!is_string($namespace) || $namespace === '') {
                continue;
            }

            $namespaces[] = $namespace;
        }

        $namespaces = array_values(array_unique($namespaces));
        sort($namespaces);

        return $namespaces;
    }

    private function classCountForModule(BuildAnalysisResult $analysis, string $module): int
    {
        $count = 0;
        foreach ($analysis->generatedClassModules as $ownerModule) {
            if ($ownerModule === $module) {
                $count++;
            }
        }

        return $count;
    }
}
