<?php

declare(strict_types=1);

namespace QtBuilder\Filtering;

readonly class ExposureDecision
{
    public function __construct(
        public bool $accepted,
        public ?string $reasonCode = null,
        public ?string $reasonMessage = null,
    ) {}

    public static function accept(): self
    {
        return new self(true);
    }

    public static function skip(string $reasonCode, string $reasonMessage): self
    {
        return new self(false, $reasonCode, $reasonMessage);
    }
}
