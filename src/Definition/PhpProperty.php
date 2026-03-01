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
}
