<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Runtime\Support;

final class QtRuntimeProcessResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly int $exitCode,
        private readonly string $stdout,
        private readonly string $stderr,
        private readonly array $payload = [],
    ) {
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function stdout(): string
    {
        return $this->stdout;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function isSkipped(): bool
    {
        return $this->exitCode === 77;
    }

    public function skipReason(): string
    {
        if (!$this->isSkipped()) {
            return '';
        }

        $reason = $this->payload['reason'] ?? '';

        return is_string($reason) && $reason !== '' ? $reason : 'Runtime fixture skipped.';
    }
}
