<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\CodeGen\TypeBridge;
use QtBuilder\Qt\QtInstallation;

readonly class ExtensionBuildContext
{
    /**
     * @param list<string> $modules
     * @param list<string> $generatedClasses
     * @param array<string, string|null> $generatedClassParents
     * @param array<string, list<string>> $generatedClassDependencies
     */
    public function __construct(
        public string $extensionName,
        public string $extensionVersion,
        public string $outputDir,
        public QtInstallation $installation,
        public array $modules,
        public array $generatedClasses = [],
        public array $generatedClassParents = [],
        public array $generatedClassDependencies = [],
        public bool $includeSignalConnectionSupport = false,
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
            $this->outputDir,
            $this->installation,
            $this->modules,
            $generatedClasses,
            $generatedClassParents,
            $generatedClassDependencies,
            $includeSignalConnectionSupport,
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

    public function buildRootDir(): string
    {
        $trimmed = rtrim($this->outputDir, '/');

        if (basename($trimmed) === 'ext') {
            return dirname($trimmed);
        }

        return $trimmed;
    }

    public function metadataDir(): string
    {
        return $this->buildRootDir() . '/generated';
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
        ]));
        if ($classes === []) {
            return [];
        }

        $generatedSet = array_fill_keys($classes, true);
        $ordered = [];
        $orderedSet = [];
        $visiting = [];
        $visited = [];

        $visit = function (string $className) use (&$visit, &$ordered, &$orderedSet, &$visiting, &$visited, $generatedSet): void {
            if (isset($visited[$className])) {
                return;
            }

            if (isset($visiting[$className])) {
                return;
            }

            $visiting[$className] = true;

            $parentClass = $this->generatedClassParents[$className] ?? null;
            if (is_string($parentClass) && isset($generatedSet[$parentClass])) {
                $visit($parentClass);
            }

            foreach ($this->generatedClassDependencies[$className] ?? [] as $dependencyClass) {
                if (isset($generatedSet[$dependencyClass])) {
                    $visit($dependencyClass);
                }
            }

            unset($visiting[$className]);
            $visited[$className] = true;

            if (!isset($orderedSet[$className])) {
                $ordered[] = $className;
                $orderedSet[$className] = true;
            }
        };

        foreach ($classes as $className) {
            $visit($className);
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    public function moduleLibraryNames(): array
    {
        $libraries = array_map(
            fn(string $module): string => $this->libraryNameForModule($module),
            $this->modules,
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
}
