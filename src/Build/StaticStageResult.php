<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\IO\FileWriteStats;

final readonly class StaticStageResult
{
    /**
     * @param list<string> $stagedFiles
     * @param list<string> $prunedFiles
     */
    public function __construct(
        public string $sourceDir,
        public string $targetDir,
        public string $manifestPath,
        public array $stagedFiles,
        public array $prunedFiles,
        public FileWriteStats $writeStats,
    ) {}
}
