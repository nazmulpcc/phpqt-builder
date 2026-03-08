<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Parsing\CppToPhpTypeMapper;
use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\TypeResolutionContext;

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

    public readonly ?string $smartPointerTargetCppType;

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
        ?CppClassTypeResolver $classTypeResolver = null,
    ) {
        $this->name = $param->name !== '' ? $param->name : 'p' . spl_object_id($param);
        $this->cppType = $param->cppType;
        $this->hasDefault = $param->hasDefault;
        $this->isReference = $param->isReference;
        $this->isConstReference = $param->isConstReference;
        $this->isNonConstReference = $param->isNonConstReference;
        $this->isRvalueReference = $param->isRvalueReference;
        $this->pointerDepth = $param->pointerDepth;
        $this->smartPointerTargetCppType = $param->smartPointerTargetCppType;

        $mapper = new CppToPhpTypeMapper();
        $resolvedPhpType = $mapper->map(
            $param->cppType,
            $ownerClass,
            $classTypeResolver,
            TypeResolutionContext::fromNames($this->ownerClassName($ownerClass), $ownerClass),
            $typeBridge->smartPointerAliases(),
        );
        if ($param->smartPointerTargetCppType !== null) {
            $resolvedPhpType = $mapper->map(
                $param->smartPointerTargetCppType,
                $ownerClass,
                $classTypeResolver,
                TypeResolutionContext::fromNames($this->ownerClassName($ownerClass), $ownerClass),
                $typeBridge->smartPointerAliases(),
            );
        }
        $this->phpType = ($this->shouldExpandStringLikeForOwner($ownerClass) && $this->canAcceptPhpStringForParameter($param))
            ? $this->expandStringLikeParameterPhpType($resolvedPhpType, $param->cppType)
            : $resolvedPhpType;
        $this->isCharPointerArray = $this->phpType === 'array' && $param->pointerDepth >= 2;
        $this->isWritableByRef = $param->isWritableByRef;
        $this->isWritableByRefPointer = $param->isWritableByRefPointer;
        $this->isWritableQtString = $param->isWritableQtString;
    }

    private function ownerClassName(string $ownerClass): string
    {
        if (!str_contains($ownerClass, '::')) {
            return $ownerClass;
        }

        return (string) substr($ownerClass, (int) strrpos($ownerClass, '::') + 2);
    }

    private function expandStringLikeParameterPhpType(string $phpType, string $cppType): string
    {
        if ($phpType === '' || $phpType === 'mixed' || str_contains($phpType, 'string')) {
            return $phpType;
        }

        $baseType = $this->normalizeBaseCppType($cppType);
        if (!\in_array($baseType, [
            'QString',
            'QByteArray',
            'QStringView',
            'QLatin1StringView',
            'QAnyStringView',
        ], true)) {
            return $phpType;
        }

        return $phpType . '|string';
    }

    private function canAcceptPhpStringForParameter(OverloadParameter $param): bool
    {
        return !$param->isNonConstReference
            && !$param->isRvalueReference
            && $param->pointerDepth === 0;
    }

    private function shouldExpandStringLikeForOwner(string $ownerClass): bool
    {
        $ownerName = $this->ownerClassName($ownerClass);

        return !\in_array($ownerName, [
            'QString',
            'QByteArray',
            'QStringView',
            'QLatin1StringView',
            'QAnyStringView',
        ], true);
    }

    private function normalizeBaseCppType(string $cppType): string
    {
        $type = trim($cppType);
        $type = preg_replace('/\bconst\b/', '', $type) ?? $type;
        $type = trim(preg_replace('/\s+/', ' ', $type) ?? $type);
        $type = rtrim($type, '& ');
        if (!str_contains($type, '<')) {
            while (str_ends_with($type, '*')) {
                $type = rtrim(substr($type, 0, -1));
            }
        }

        return trim($type);
    }
}
