<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\CodeGen\TypeBridge;
use QtBuilder\Qt\QtInstallation;

readonly class ExtensionBuildContext
{
    private const WINDOWS_SOURCE_BUCKET_SIZE = 64;

    /**
     * @param list<string> $modules
     * @param list<string> $linkModules
     * @param list<string> $importIncludeRoots
     * @param list<string> $generatedClasses
     * @param array<string, string|null> $generatedClassParents
     * @param array<string, list<string>> $generatedClassDependencies
     * @param array<string, string> $generatedClassIds
     * @param list<EnumHolderDefinition> $enumHolders
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
        public array $generatedClassIds = [],
        public array $enumHolders = [],
        public bool $includeSignalConnectionSupport = false,
        public array $linkModules = [],
        public array $importIncludeRoots = [],
        public bool $includeBuildInfoSupport = false,
        public bool $includeThreadRuntimeSupport = false,
        public ?RuntimeManifest $runtimeManifest = null,
        public ?string $currentQtModule = null,
        public string $buildMode = RuntimeManifest::MODE_MONOLITHIC,
        public string $builderAbiVersion = RuntimeManifest::BUILDER_ABI_VERSION,
    ) {}

    public function withGeneratedClasses(
        array $generatedClasses,
        array $generatedClassParents = [],
        array $generatedClassDependencies = [],
        array $generatedClassIds = [],
        array $enumHolders = [],
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
            $generatedClassIds,
            $enumHolders,
            $includeSignalConnectionSupport,
            $this->linkModules,
            $this->importIncludeRoots,
            $this->includeBuildInfoSupport,
            $this->includeThreadRuntimeSupport,
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

    public function windowsLibraryRoot(): ?string
    {
        $root = $this->installation->libraryRoots[0] ?? null;
        if (!is_string($root) || $root === '') {
            return null;
        }

        return $this->normalizeWindowsPath($root);
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
    public function windowsCompileIncludeRoots(): array
    {
        return array_values(array_map(
            fn(string $path): string => $this->normalizeWindowsPath($path),
            array_values(array_filter(
                $this->compileIncludeRoots(),
                static fn(string $path): bool => $path !== '' && !str_starts_with($path, '-'),
            )),
        ));
    }

    /**
     * @return list<string>
     */
    public function classHeaders(): array
    {
        $bridge = new TypeBridge();

        return array_values(array_merge(
            array_map(
                fn(string $className): string => 'classes/' . $bridge->minitNameForId($this->generatedClassId($className)) . '.h',
                $this->orderedGeneratedClasses(),
            ),
            array_map(
                static fn(EnumHolderDefinition $holder): string => 'classes/' . $holder->filePrefix() . '.h',
                $this->enumHolders,
            ),
            $this->includeSignalConnectionSupport
                ? [
                    'classes/qt_qphpsignalconnection.h',
                    'classes/qt_signalattribute.h',
                    'classes/qt_slotattribute.h',
                    'classes/qt_qmetaobject_bridge.h',
                ]
                : [],
        ));
    }

    /**
     * @return list<string>
     */
    public function classSources(): array
    {
        $bridge = new TypeBridge();

        return array_values(array_merge(
            array_map(
                fn(string $className): string => 'classes/' . $bridge->minitNameForId($this->generatedClassId($className)) . '.cpp',
                $this->orderedGeneratedClasses(),
            ),
            array_map(
                static fn(EnumHolderDefinition $holder): string => 'classes/' . $holder->filePrefix() . '.cpp',
                $this->enumHolders,
            ),
            $this->includeSignalConnectionSupport
                ? [
                    'classes/qt_qphpsignalconnection.cpp',
                    'classes/qt_php_signal_helpers.cpp',
                    'classes/qt_signalattribute.cpp',
                    'classes/qt_slotattribute.cpp',
                    'classes/qt_qmetaobject_bridge.cpp',
                ]
                : [],
        ));
    }

    /**
     * @return list<string>
     */
    public function classSourceBasenames(): array
    {
        return array_values(array_map(
            static fn(string $path): string => basename($path),
            $this->classSources(),
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    public function windowsSourceBuckets(): array
    {
        $sources = $this->classSourceBasenames();
        if ($sources === []) {
            return [];
        }

        $chunks = array_chunk($sources, self::WINDOWS_SOURCE_BUCKET_SIZE);
        $buckets = [];

        foreach ($chunks as $index => $chunk) {
            $buckets[sprintf('src_%02d', $index)] = array_values($chunk);
        }

        return $buckets;
    }

    /**
     * @return array<string, string>
     */
    public function windowsUnitySourceFiles(): array
    {
        $unitySources = [];

        foreach (array_keys($this->windowsSourceBuckets()) as $bucketDir) {
            $suffix = substr($bucketDir, 4);
            $unitySources[$bucketDir] = sprintf('qt_bucket_%s.cpp', $suffix !== false ? $suffix : '00');
        }

        return $unitySources;
    }

    /**
     * @return list<string>
     */
    public function classMinits(): array
    {
        $bridge = new TypeBridge();

        return array_values(array_merge(
            array_map(
                fn(string $className): string => $bridge->minitNameForId($this->generatedClassId($className)),
                $this->orderedGeneratedClasses(),
            ),
            array_map(
                static fn(EnumHolderDefinition $holder): string => $holder->minitName(),
                $this->enumHolders,
            ),
            $this->includeSignalConnectionSupport
                ? ['qt_qphpsignalconnection', 'qt_signalattribute', 'qt_slotattribute']
                : [],
        ));
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
            ...($this->includeThreadRuntimeSupport ? ['QThreadRuntime', 'QFuture', 'QPromise'] : []),
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

    private function generatedClassId(string $classKey): string
    {
        return $this->generatedClassIds[$classKey] ?? (new TypeBridge())->generationIdForQualifiedName($classKey);
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

    /**
     * @return list<string>
     */
    public function windowsReleaseModuleLibraryFiles(): array
    {
        return array_values(array_map(
            static fn(string $library): string => $library . '.lib',
            $this->moduleLibraryNames(),
        ));
    }

    /**
     * @return list<string>
     */
    public function windowsDebugModuleLibraryFiles(): array
    {
        return array_values(array_map(
            static fn(string $library): string => $library . 'd.lib',
            $this->moduleLibraryNames(),
        ));
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

    private function normalizeWindowsPath(string $path): string
    {
        return str_replace('/', '\\', $path);
    }
}
