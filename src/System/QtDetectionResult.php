<?php

namespace QtBuilder\System;

final readonly class QtDetectionResult
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private bool $detected,
        private string $message,
        private array $meta = [],
    ) {
    }

    public function isDetected(): bool
    {
        return $this->detected;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }
}
