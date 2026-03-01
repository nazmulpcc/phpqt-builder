<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class GenerateTask
{
    /**
     * @param list<string> $allowedClasses
     */
    public function __construct(
        public string $headerPath,
        public string $className,
        public string $module,
        public string $namespace,
        public string $outputDir,
        public string $extensionName,
        public ?string $qtPath,
        public ?string $allowedClassesFile = null,
        public array $allowedClasses = [],
    ) {}
}
