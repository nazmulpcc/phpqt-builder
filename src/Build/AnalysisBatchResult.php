<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class AnalysisBatchResult
{
    /**
     * @param list<array<string, mixed>> $results
     */
    public function __construct(
        public string $status,
        public array $results = [],
        public ?string $errorMessage = null,
    ) {}

    /**
     * @param list<array<string, mixed>> $results
     */
    public static function ok(array $results): self
    {
        return new self('ok', $results);
    }

    public static function error(string $message): self
    {
        return new self('error', [], $message);
    }
}
