<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class EmissionTask
{
    public function __construct(
        public string $batchFile,
        public string $outputDir,
        public int $classCount,
    ) {}
}
