<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\CodeGen\TypeBridge;
use QtBuilder\Qt\QtInstallation;

readonly class ExtensionBuildContext
{
    /**
     * @param list<string> $modules
     * @param list<string> $linkModules
     * @param list<string> $importIncludeRoots
     * @param list<string> $generatedClasses
     * @param array<string, string|null> $generatedClassParents
     * @param array<string, list<string>> $generatedClassDependencies
     */
    public function __construct(
        public string $extensionName,
        public string $extensionVersion,
        public string $buildRootDir,
        public string $outputDir,
        public QtInstallation $installation,
        public array $modules,
        public array $generatedClasses = [],
        public array $generatedClassParents = [],
        public array $generatedClassDependencies = [],
        public bool $includeSignalConnectionSupport = false,
        public array $linkModules = [],
        public array $importIncludeRoots = [],
        public bool $includeBuildInfoSupport = false,
        public ?RuntimeManifest $runtimeManifest = null,
        public ?string $currentQtModule = null,
        public string $buildMode = RuntimeManifest::MODE_MONOLITHIC,
        public string $builderAbiVersion = RuntimeManifest::BUILDER_ABI_VERSION,
    ) {}

    public function withGeneratedClasses(
        array $generatedClasses,
        array $generatedClassParents = [],
        array $generatedClassDependencies = [],
        bool $includeSignalConnectionSupport = false,
    ): self
    {
        return new self(
            $this->extensionName,
            $this->extensionVersion,
            $this->buildRootDir,
            $this->outputDir,
            $this->installation,
            $this->modules,
            $generatedClasses,
            $generatedClassParents,
            $generatedClassDependencies,
            $includeSignalConnectionSupport,
            $this->linkModules,
            $this->importIncludeRoots,
            $this->includeBuildInfoSupport,
            $this->runtimeManifest,
            $this->currentQtModule,
            $this->buildMode,
            $this->builderAbiVersion,
        );
    }

    public function phpHeaderFilename(): string
    {
        return sprintf('php_%s.h', $this->extensionName);
    }

    public function moduleSourceFilename(): string
    {
        return sprintf('%s.cpp', $this->extensionName);
    }

    public function metadataDir(): string
    {
        return $this->buildRootDir . '/generated';
    }

    /**
     * @return list<string>
     */
    public function compileIncludeRoots(): array
    {
        return array_values(array_unique([
            ...$this->installation->includeRoots,
            ...$this->importIncludeRoots,
        ]));
    }

    /**
     * @return list<string>
     */
    public function classHeaders(): array
    {
        $bridge = new TypeBridge();

        return array_map(
            static fn(string $className): string => 'classes/' . $bridge->minitName($className) . '.h',
            $this->orderedGeneratedClasses(),
        );
    }

    /**
     * @return list<string>
     */
    public function classSources(): array
    {
        $bridge = new TypeBridge();

        return array_map(
            static fn(string $className): string => 'classes/' . $bridge->minitName($className) . '.cpp',
            $this->orderedGeneratedClasses(),
        );
    }

    /**
     * @return list<string>
     */
    public function classMinits(): array
    {
        $bridge = new TypeBridge();

        return array_map(
            static fn(string $className): string => $bridge->minitName($className),
            $this->orderedGeneratedClasses(),
        );
    }

    /**
     * @return list<string>
     */
    private function orderedGeneratedClasses(): array
    {
        $classes = array_values(array_unique([
            ...$this->generatedClasses,
            ...($this->includeSignalConnectionSupport ? ['QMetaObjectConnection'] : []),
            ...($this->includeBuildInfoSupport ? ['BuildInfo'] : []),
        ]));
        if ($classes === []) {
            return [];
        }

        $generatedSet = array_fill_keys($classes, true);
        $originalIndex = array_flip($classes);
        /** @var array<string, list<string>> $outgoing */
        $outgoing = [];
        /** @var array<string, int> $hardIndegree */
        $hardIndegree = array_fill_keys($classes, 0);
        /** @var array<string, int> $softIndegree */
        $softIndegree = array_fill_keys($classes, 0);

        foreach ($classes as $className) {
            $parentClass = $this->generatedClassParents[$className] ?? null;
            if (is_string($parentClass) && isset($generatedSet[$parentClass])) {
                $outgoing[$parentClass][] = $className;
                $hardIndegree[$className]++;
            }

            foreach ($this->generatedClassDependencies[$className] ?? [] as $dependencyClass) {
                if (!isset($generatedSet[$dependencyClass]) || $dependencyClass === $parentClass) {
                    continue;
                }

                $outgoing[$dependencyClass][] = $className;
                $softIndegree[$className]++;
            }
        }

        $ordered = [];
        /** @var array<string, bool> $emitted */
        $emitted = [];

        while (count($ordered) < count($classes)) {
            $ready = array_values(array_filter(
                $classes,
                static fn(string $className): bool => !isset($emitted[$className])
                    && ($hardIndegree[$className] ?? 0) === 0
                    && ($softIndegree[$className] ?? 0) === 0,
            ));

            if ($ready === []) {
                // Break dependency-only cycles, but never violate parent-before-child ordering.
                $ready = array_values(array_filter(
                    $classes,
                    static fn(string $className): bool => !isset($emitted[$className])
                        && ($hardIndegree[$className] ?? 0) === 0,
                ));
            }

            if ($ready === []) {
                // Defensive fallback for malformed parent cycles; preserve deterministic output.
                $ready = array_values(array_filter(
                    $classes,
                    static fn(string $className): bool => !isset($emitted[$className]),
                ));
            }

            usort(
                $ready,
                static fn(string $left, string $right): int => ($originalIndex[$left] ?? PHP_INT_MAX) <=> ($originalIndex[$right] ?? PHP_INT_MAX),
            );

            $className = $ready[0];
            $ordered[] = $className;
            $emitted[$className] = true;

            foreach ($outgoing[$className] ?? [] as $dependentClass) {
                $parentClass = $this->generatedClassParents[$dependentClass] ?? null;
                if ($parentClass === $className) {
                    $hardIndegree[$dependentClass] = max(0, ($hardIndegree[$dependentClass] ?? 0) - 1);
                    continue;
                }

                $softIndegree[$dependentClass] = max(0, ($softIndegree[$dependentClass] ?? 0) - 1);
            }
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    public function moduleLibraryNames(): array
    {
        $modules = $this->linkModules !== [] ? $this->linkModules : $this->modules;
        $libraries = array_map(
            fn(string $module): string => $this->libraryNameForModule($module),
            $modules,
        );

        return array_values(array_unique($libraries));
    }

    public function moduleLibraryName(): string
    {
        return $this->moduleLibraryNames()[0] ?? ($this->installation->isDarwin() ? 'QtCore' : 'Qt6Core');
    }

    private function libraryNameForModule(string $module): string
    {
        if ($this->installation->isDarwin()) {
            return str_starts_with($module, 'Qt6') ? 'Qt' . substr($module, 3) : $module;
        }

        if (str_starts_with($module, 'Qt6')) {
            return $module;
        }

        if (str_starts_with($module, 'Qt')) {
            return 'Qt6' . substr($module, 2);
        }

        return $module;
    }

    public function requiresBuildInfoRegistration(): bool
    {
        return $this->buildMode === RuntimeManifest::MODE_MODULAR
            && !$this->includeBuildInfoSupport
            && $this->currentQtModule !== null;
    }

    public function currentModuleMetadata(): ?RuntimeModuleMetadata
    {
        if ($this->runtimeManifest === null || $this->currentQtModule === null) {
            return null;
        }

        return $this->runtimeManifest->module($this->currentQtModule);
    }
}
