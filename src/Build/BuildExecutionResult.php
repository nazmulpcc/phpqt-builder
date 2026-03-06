<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class BuildExecutionResult
{
    /**
     * @param list<string> $generatedClasses
     * @param list<array<string, string|null>> $skippedClasses
     * @param list<array<string, string|null>> $errors
     * @param array<string, mixed> $summary
     */
    public function __construct(
        public bool $successful,
        public ExtensionBuildContext $context,
        public array $generatedClasses,
        public array $skippedClasses,
        public array $errors,
        public array $summary,
        public ?ModuleAbiManifest $abiManifest = null,
    ) {}
}
