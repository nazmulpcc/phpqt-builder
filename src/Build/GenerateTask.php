<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class GenerateTask
{
    /**
     * @param list<string> $allowedClasses
     * @param list<string> $includePaths
     */
    public function __construct(
        public string $headerPath,
        public string $className,
        public string $module,
        public string $namespace,
        public string $outputDir,
        public string $extensionName,
        public ?string $qtPath,
        public ?string $candidateKey = null,
        public array $includePaths = [],
        public ?string $allowedClassesFile = null,
        public ?string $classBatchFile = null,
        public ?string $classNamespacesFile = null,
        public ?string $classHeadersFile = null,
        public array $allowedClasses = [],
        public string $workerMode = 'generate',
    ) {}
}
