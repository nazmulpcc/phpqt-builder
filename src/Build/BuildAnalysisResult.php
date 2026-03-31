<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Definition\PhpClass;
use QtBuilder\Scanning\HeaderCandidate;

readonly class BuildAnalysisResult
{
    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $skippedClasses
     * @param list<array<string, string>> $skippedMethods
     * @param list<array<string, string|null>> $errors
     * @param list<string> $generatedClasses
     * @param array<string, PhpClass> $generatedPhpClasses
     * @param array<string, string|null> $generatedClassParents
     * @param array<string, list<string>> $generatedClassDependencies
     * @param array<string, string> $generatedClassHeaders
     * @param array<string, string> $generatedClassModules
     * @param array<string, string> $classNamespaces
     * @param array<string, int> $moduleMethodTotals
     * @param array<string, int> $moduleAcceptedMethodTotals
     * @param array<string, int> $moduleGeneratedMethodTotals
     * @param list<EnumHolderDefinition> $enumHolders
     * @param array<string, mixed> $cacheMetadata
     * @param array<string, float> $timings
     */
    public function __construct(
        public string $metadataDir,
        public int $candidateCount,
        public array $acceptedCandidates,
        public array $skippedClasses,
        public array $skippedMethods,
        public array $errors,
        public array $generatedClasses,
        public array $generatedPhpClasses,
        public array $generatedClassParents,
        public array $generatedClassDependencies,
        public array $generatedClassHeaders,
        public array $generatedClassModules,
        public array $classNamespaces,
        public array $enumHolders,
        public array $moduleMethodTotals,
        public array $moduleAcceptedMethodTotals,
        public array $moduleGeneratedMethodTotals,
        public int $passes,
        public bool $requiresSignalConnectionSupport,
        public array $cacheMetadata = [],
        public array $timings = [],
    ) {}
}
