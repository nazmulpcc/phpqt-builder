<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Build\Dependencies\ResolvedModuleGraph;
use QtBuilder\Qt\QtInstallation;

final class RuntimeManifestBuilder
{
    public function buildForMonolithic(
        BuildExecutionRequest $request,
        BuildAnalysisResult $analysis,
        bool $includesSignalConnectionSupport,
    ): RuntimeManifest {
        $graph = $request->resolvedModuleGraph;
        if (!$graph instanceof ResolvedModuleGraph) {
            throw new \RuntimeException('BuildExecutionRequest is missing a resolved module dependency graph.');
        }

        return $this->buildManifest(
            buildMode: RuntimeManifest::MODE_MONOLITHIC,
            installation: $request->installation,
            extensionVersion: $request->extensionVersion,
            requestedModules: $request->effectiveRequestedModules(),
            graph: $graph,
            analysis: $analysis,
            extensionNameResolver: static fn(string $module): string => $request->extensionName,
            signalSupportResolver: static fn(string $module): bool => $includesSignalConnectionSupport,
        );
    }

    public function buildForModular(
        ResolvedModuleGraph $graph,
        BuildAnalysisResult $analysis,
        string $extensionVersion,
        QtInstallation $installation,
        bool $includesSignalConnectionSupport,
    ): RuntimeManifest {
        return $this->buildManifest(
            buildMode: RuntimeManifest::MODE_MODULAR,
            installation: $installation,
            extensionVersion: $extensionVersion,
            requestedModules: $graph->requestedModules,
            graph: $graph,
            analysis: $analysis,
            extensionNameResolver: static fn(string $module): string => $graph->extensionNameFor($module),
            signalSupportResolver: static fn(string $module): bool => $module === 'QtCore' && $includesSignalConnectionSupport,
        );
    }

    /**
     * @param list<string> $requestedModules
     */
    private function buildManifest(
        string $buildMode,
        QtInstallation $installation,
        string $extensionVersion,
        array $requestedModules,
        ResolvedModuleGraph $graph,
        BuildAnalysisResult $analysis,
        callable $extensionNameResolver,
        callable $signalSupportResolver,
    ): RuntimeManifest {
        $modules = [];
        foreach ($graph->buildOrder as $module) {
            $modules[$module] = new RuntimeModuleMetadata(
                module: $module,
                extensionName: (string) $extensionNameResolver($module),
                dependencies: array_values($graph->dependencies[$module] ?? []),
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
            requestedModules: array_values($requestedModules),
            expandedModules: array_values($graph->expandedModules()),
            dependencySource: $graph->dependencySource,
            builtModules: array_values($graph->buildOrder),
            buildOrder: array_values($graph->buildOrder),
            modules: $modules,
        );
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
