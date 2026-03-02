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
     */
    public function __construct(
        public array $acceptedCandidates,
        public array $skippedClasses,
        public array $allowedClasses,
        public int $candidateCount,
        public int $passes = 0,
        public array $errors = [],
    ) {}
}
