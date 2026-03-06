<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Qt\QtInstallation;

readonly class BuildExecutionRequest
{
    /**
     * @param list<string> $modules
     * @param list<string> $linkModules
     */
    public function __construct(
        public QtInstallation $installation,
        public string $buildRootDir,
        public string $outputDir,
        public array $modules,
        public string $extensionName,
        public string $extensionVersion,
        public int $jobs,
        public array $linkModules = [],
        public ?ImportedModuleAbi $importedAbi = null,
        public bool $forceSignalConnectionSupport = false,
        public bool $writeAbiManifest = false,
        public bool $reuseDiscoveryCache = true,
    ) {}

    /**
     * @return list<string>
     */
    public function effectiveLinkModules(): array
    {
        return $this->linkModules !== [] ? $this->linkModules : $this->modules;
    }
}
