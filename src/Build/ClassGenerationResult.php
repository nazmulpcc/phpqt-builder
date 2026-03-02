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
            'parent_class' => $this->phpClass?->parent,
            'class_dependencies' => $this->classDependencies(),
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

    /**
     * @return list<string>
     */
    private function classDependencies(): array
    {
        if ($this->phpClass === null) {
            return [];
        }

        $dependencies = [];

        foreach ($this->phpClass->methods as $method) {
            foreach ($this->typeParts($method->returnType) as $type) {
                $dependencies[$type] = true;
            }

            foreach ($method->parameters as $parameter) {
                foreach ($this->typeParts($parameter->phpType) as $type) {
                    $dependencies[$type] = true;
                }
            }
        }

        unset($dependencies[$this->phpClass->name]);

        $resolved = array_keys($dependencies);
        sort($resolved);

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function typeParts(string $phpType): array
    {
        $parts = [];

        foreach (explode('|', $phpType) as $part) {
            $part = trim($part);
            if ($part === '' || $part === 'null' || $part === 'mixed') {
                continue;
            }

            if (in_array($part, ['int', 'float', 'string', 'bool', 'array', 'void'], true)) {
                continue;
            }

            $part = ltrim($part, '\\');
            if (str_contains($part, '\\')) {
                $segments = explode('\\', $part);
                $part = end($segments) ?: $part;
            }

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return array_values(array_unique($parts));
    }
}
