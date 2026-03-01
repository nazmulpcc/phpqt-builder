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
     */
    public function __construct(
        public string $extensionName,
        public string $extensionVersion,
        public string $outputDir,
        public QtInstallation $installation,
        public array $modules,
        public array $generatedClasses = [],
    ) {}

    public function withGeneratedClasses(array $generatedClasses): self
    {
        return new self(
            $this->extensionName,
            $this->extensionVersion,
            $this->outputDir,
            $this->installation,
            $this->modules,
            $generatedClasses,
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
            $this->generatedClasses,
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
            $this->generatedClasses,
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
            $this->generatedClasses,
        );
    }

    public function moduleLibraryName(): string
    {
        return $this->installation->isDarwin() ? 'QtCore' : 'Qt6Core';
    }
}
