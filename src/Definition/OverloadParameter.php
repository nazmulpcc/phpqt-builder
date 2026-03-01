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
    ) {}

    /**
     * @return array{name: string, cpp_type: string, has_default: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'cpp_type' => $this->cppType,
            'has_default' => $this->hasDefault,
        ];
    }
}
