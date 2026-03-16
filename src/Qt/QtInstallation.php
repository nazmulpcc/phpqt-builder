<?php

declare(strict_types=1);

namespace QtBuilder\Qt;

readonly class QtInstallation
{
    /**
     * @param list<string> $includeRoots
     * @param list<string> $libraryRoots
     * @param array<string, string> $moduleHeaderRoots
     * @param array<string, string> $tools
     */
    public function __construct(
        public string $rootPath,
        public string $osFamily,
        public array $includeRoots,
        public array $libraryRoots,
        public array $moduleHeaderRoots,
        public ?string $moduleLinkFlags = null,
        public array $tools = [],
        public string $qtVersion = '',
        public int $qtVersionMajor = 0,
        public int $qtVersionMinor = 0,
        public int $qtVersionPatch = 0,
        public string $buildTarget = \QtBuilder\Build\BuildTarget::DESKTOP,
        public array $iosSdks = [],
        public string $iosMinimumVersion = '',
        public array $iosArchitectures = [],
    ) {}

    public function headerRootFor(string $module): ?string
    {
        return $this->moduleHeaderRoots[$module] ?? null;
    }

    public function isDarwin(): bool
    {
        return $this->osFamily === 'Darwin';
    }
}
