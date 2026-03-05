<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Scanning\HeaderCandidate;

readonly class BuildDiscoveryResult
{
    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $skippedClasses
     * @param list<string> $allowedClasses
     * @param list<array<string, string|null>> $errors
     * @param array<string, int> $moduleMethodTotals
     * @param array<string, int> $moduleAcceptedMethodTotals
     */
    public function __construct(
        public array $acceptedCandidates,
        public array $skippedClasses,
        public array $allowedClasses,
        public int $candidateCount,
        public array $moduleMethodTotals = [],
        public array $moduleAcceptedMethodTotals = [],
        public int $passes = 0,
        public array $errors = [],
    ) {}
}
