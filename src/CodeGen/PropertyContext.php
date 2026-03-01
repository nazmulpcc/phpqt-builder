<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\PhpProperty;

/**
 * Template context for a single PHP property.
 */
class PropertyContext
{
    /** Property name */
    public readonly string $name;

    /** PHP type */
    public readonly string $phpType;

    /** C++ type */
    public readonly string $cppType;

    /** Access: "public" or "protected" */
    public readonly string $access;

    /** Whether the property is static */
    public readonly bool $isStatic;

    public function __construct(
        PhpProperty $property,
        TypeBridge $typeBridge,
    ) {
        $this->name = $property->name;
        $this->phpType = $property->phpType;
        $this->cppType = $property->cppType;
        $this->access = $property->access;
        $this->isStatic = $property->isStatic;
    }
}
