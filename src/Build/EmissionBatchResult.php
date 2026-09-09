<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\IO\FileWriteStats;

readonly class EmissionBatchResult
{
    /**
     * @param list<array<string, mixed>> $classmap
     */
    public function __construct(
        public string $status,
        public FileWriteStats $fileWriteStats,
        public array $classmap,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @param list<array<string, mixed>> $classmap
     */
    public static function ok(FileWriteStats $stats, array $classmap): self
    {
        return new self('ok', $stats, $classmap);
    }

    public static function error(string $message): self
    {
        return new self('error', new FileWriteStats(), [], $message);
    }
}
