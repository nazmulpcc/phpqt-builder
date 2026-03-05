<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

/**
 * A parameter in the unified PHP method signature.
 *
 * When multiple C++ overloads exist, the PHP type may be a union
 * (e.g. "QSize|int") and the parameter may be marked optional if
 * it only appears in longer overload variants.
 */
readonly class PhpParameter
{
    public function __construct(
        public string $name,
        public string $phpType,
        public bool $hasDefault,
        public int $position,
        public bool $isByRef = false,
        public bool $isNullableByRef = false,
    ) {}

    /**
     * @return array{name: string, php_type: string, has_default: bool, position: int, is_by_ref: bool, is_nullable_by_ref: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'php_type' => $this->phpType,
            'has_default' => $this->hasDefault,
            'position' => $this->position,
            'is_by_ref' => $this->isByRef,
            'is_nullable_by_ref' => $this->isNullableByRef,
        ];
    }
}
