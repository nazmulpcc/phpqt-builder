<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

/**
 * Represents a single C++ overload variant's parameter.
 *
 * Preserves the original C++ type for code generation dispatch.
 */
readonly class OverloadParameter
{
    public function __construct(
        public string $name,
        public string $cppType,
        public bool $hasDefault,
        public bool $isReference = false,
        public bool $isConstReference = false,
        public bool $isNonConstReference = false,
        public int $pointerDepth = 0,
    ) {}

    /**
     * @return array{name: string, cpp_type: string, has_default: bool, is_reference: bool, is_const_reference: bool, is_non_const_reference: bool, pointer_depth: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'cpp_type' => $this->cppType,
            'has_default' => $this->hasDefault,
            'is_reference' => $this->isReference,
            'is_const_reference' => $this->isConstReference,
            'is_non_const_reference' => $this->isNonConstReference,
            'pointer_depth' => $this->pointerDepth,
        ];
    }
}
