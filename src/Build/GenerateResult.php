<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class GenerateResult
{
    /**
     * @param list<string> $generatedFiles
     * @param list<array<string, string>> $skippedMethods
     * @param array<string, int> $summary
     */
    public function __construct(
        public string $status,
        public string $className,
        public string $headerPath,
        public ?string $parentClassName = null,
        public array $classDependencies = [],
        public array $generatedFiles = [],
        public array $skippedMethods = [],
        public array $summary = [],
        public ?string $reasonCode = null,
        public ?string $reasonMessage = null,
        public string $stderr = '',
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload, string $stderr = ''): self
    {
        return new self(
            status: (string) ($payload['status'] ?? 'error'),
            className: (string) ($payload['class'] ?? ''),
            headerPath: (string) ($payload['header'] ?? ''),
            parentClassName: is_string($payload['parent_class'] ?? null) ? $payload['parent_class'] : null,
            classDependencies: array_values(array_filter(
                array_map(
                    static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                    $payload['class_dependencies'] ?? [],
                ),
                static fn(string $value): bool => $value !== '',
            )),
            generatedFiles: array_values($payload['generated_files'] ?? []),
            skippedMethods: array_values($payload['skipped_methods'] ?? []),
            summary: $payload['summary'] ?? [],
            reasonCode: isset($payload['reason_code']) ? (string) $payload['reason_code'] : null,
            reasonMessage: isset($payload['reason_message']) ? (string) $payload['reason_message'] : null,
            stderr: $stderr,
        );
    }

    public static function error(string $className, string $headerPath, string $reasonMessage, string $stderr = ''): self
    {
        return new self('error', $className, $headerPath, null, [], [], [], [], 'worker_error', $reasonMessage, $stderr);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }
}
