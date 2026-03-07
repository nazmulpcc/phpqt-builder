<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class EnumExtractionTask
{
    /**
     * @param list<string> $includePaths
     */
    public function __construct(
        public string $headerPath,
        public string $module,
        public array $includePaths = [],
        public ?string $knownClassesFile = null,
    ) {}
}
