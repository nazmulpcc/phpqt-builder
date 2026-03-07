<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Parsing\CppToPhpTypeMapper;

/**
 * Template context for a single C++ overload parameter.
 */
class OverloadParamContext
{
    /** Parameter name */
    public readonly string $name;

    /** Raw C++ type */
    public readonly string $cppType;

    /** Whether this parameter has a default value */
    public readonly bool $hasDefault;

    /** PHP type this maps to */
    public readonly string $phpType;

    /** Whether this parameter is a reference in C++ */
    public readonly bool $isReference;

    /** Whether this parameter is a const reference in C++ */
    public readonly bool $isConstReference;

    /** Whether this parameter is a writable reference in C++ */
    public readonly bool $isNonConstReference;

    /** Whether this parameter is an rvalue reference in C++ */
    public readonly bool $isRvalueReference;

    /** Raw pointer depth from the original C++ type */
    public readonly int $pointerDepth;

    /** Whether this parameter uses the char** argv bridge */
    public readonly bool $isCharPointerArray;

    /** Whether this parameter supports writable PHP by-reference bridging */
    public readonly bool $isWritableByRef;

    /** Whether writable by-ref uses pointer form (`T *`) */
    public readonly bool $isWritableByRefPointer;

    /** Whether writable by-ref maps to Qt string classes (QString/QByteArray) */
    public readonly bool $isWritableQtString;

    public function __construct(
        OverloadParameter $param,
        string $ownerClass,
        TypeBridge $typeBridge,
    ) {
        $this->name = $param->name !== '' ? $param->name : 'p' . spl_object_id($param);
        $this->cppType = $param->cppType;
        $this->hasDefault = $param->hasDefault;
        $this->isReference = $param->isReference;
        $this->isConstReference = $param->isConstReference;
        $this->isNonConstReference = $param->isNonConstReference;
        $this->isRvalueReference = $param->isRvalueReference;
        $this->pointerDepth = $param->pointerDepth;

        $mapper = new CppToPhpTypeMapper();
        $this->phpType = $mapper->map($param->cppType, $ownerClass);
        $this->isCharPointerArray = $this->phpType === 'array' && $param->pointerDepth >= 2;
        $this->isWritableByRef = $param->isWritableByRef;
        $this->isWritableByRefPointer = $param->isWritableByRefPointer;
        $this->isWritableQtString = $param->isWritableQtString;
    }
}
