<?php

namespace QtBuilder\Preflight;

final readonly class CheckResult
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private string $id,
        private string $label,
        private CheckStatus $status,
        private string $message,
        private array $meta = [],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getStatus(): CheckStatus
    {
        return $this->status;
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
