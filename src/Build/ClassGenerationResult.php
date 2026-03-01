<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Definition\PhpClass;

readonly class ClassGenerationResult
{
    /**
     * @param list<array<string, string>> $skippedMethods
     * @param list<string> $generatedFiles
     */
    public function __construct(
        public string $status,
        public string $className,
        public string $headerPath,
        public ?PhpClass $phpClass = null,
        public array $skippedMethods = [],
        public array $generatedFiles = [],
        public ?string $reasonCode = null,
        public ?string $reasonMessage = null,
    ) {}

    public static function ok(string $className, string $headerPath, PhpClass $phpClass, array $skippedMethods = []): self
    {
        return new self('ok', $className, $headerPath, $phpClass, $skippedMethods);
    }

    public static function skipped(string $className, string $headerPath, string $reasonCode, string $reasonMessage, array $skippedMethods = []): self
    {
        return new self('skipped', $className, $headerPath, null, $skippedMethods, [], $reasonCode, $reasonMessage);
    }

    public function withGeneratedFiles(array $generatedFiles): self
    {
        return new self(
            $this->status,
            $this->className,
            $this->headerPath,
            $this->phpClass,
            $this->skippedMethods,
            $generatedFiles,
            $this->reasonCode,
            $this->reasonMessage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'class' => $this->className,
            'header' => $this->headerPath,
            'generated_files' => $this->generatedFiles,
            'reason_code' => $this->reasonCode,
            'reason_message' => $this->reasonMessage,
            'skipped_methods' => $this->skippedMethods,
            'summary' => [
                'generated_methods' => $this->phpClass !== null ? count($this->phpClass->methods) : 0,
                'skipped_methods' => count($this->skippedMethods),
            ],
        ];
    }
}
