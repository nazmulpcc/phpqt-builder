<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class AnalysisTask
{
    public function __construct(
        public string $batchFile,
        public int $candidateCount,
    ) {}
}
