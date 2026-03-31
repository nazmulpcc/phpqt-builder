<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

/**
 * A property (field) in the PHP class definition.
 *
 * Preserves both the mapped PHP type and the original C++ type
 * so the code generator can emit correct accessor logic.
 */
readonly class PhpProperty
{
    public function __construct(
        public string $name,
        public string $phpType,
        public string $cppType,
        public string $access,
        public bool $isStatic,
    ) {}

    /**
     * @return array{name: string, php_type: string, cpp_type: string, access: string, is_static: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'php_type' => $this->phpType,
            'cpp_type' => $this->cppType,
            'access' => $this->access,
            'is_static' => $this->isStatic,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            name: is_string($payload['name'] ?? null) ? $payload['name'] : '',
            phpType: is_string($payload['php_type'] ?? null) ? $payload['php_type'] : 'mixed',
            cppType: is_string($payload['cpp_type'] ?? null) ? $payload['cpp_type'] : '',
            access: is_string($payload['access'] ?? null) ? $payload['access'] : 'public',
            isStatic: (bool) ($payload['is_static'] ?? false),
        );
    }
}
