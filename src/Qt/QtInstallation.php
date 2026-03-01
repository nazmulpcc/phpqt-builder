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
        public array $tools = [],
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
