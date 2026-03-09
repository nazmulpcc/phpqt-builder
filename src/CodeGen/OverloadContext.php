<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Parsing\CppToPhpTypeMapper;
use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\TypeResolutionContext;

/**
 * Template context for a single C++ overload variant.
 *
 * Used by the overloaded method template to generate dispatch logic
 * (arg count checks, type checks) and the actual C++ method call.
 */
class OverloadContext
{
    private CppToPhpTypeMapper $typeMapper;
    private CppClassTypeResolver $classTypeResolver;
    /** @var array<string, string> */
    private array $smartPointerAliases = [];

    /** C++ return type (raw) */
    public readonly string $cppReturnType;
    public readonly ?string $smartPointerReturnTargetCppType;

    /** Declaring C++ class for member pointer expressions */
    public readonly string $declaringClass;

    /** Number of C++ parameters */
    public readonly int $paramCount;

    /** Number of required (no default) C++ parameters */
    public readonly int $requiredParamCount;

    /** Whether this overload is const */
    public readonly bool $isConst;

    /** Whether this overload is static */
    public readonly bool $isStatic;

    /** Access for this specific overload */
    public readonly string $access;

    /** Whether this overload is virtual */
    public readonly bool $isVirtual;

    /** Whether this overload is pure virtual */
    public readonly bool $isPureVirtual;

    /** @var list<OverloadParamContext> */
    public readonly array $params;

    /** Return strategy for this specific overload */
    public readonly string $returnStrategy;

    /** PHP-mapped return type for this specific overload */
    public readonly string $phpReturnType;

    public function __construct(
        MethodOverload $overload,
        ClassContext $classCtx,
        TypeBridge $typeBridge,
    ) {
        $this->typeMapper = new CppToPhpTypeMapper();
        $this->classTypeResolver = $classCtx->classTypeResolver;
        $this->smartPointerAliases = $classCtx->smartPointerAliases;
        $this->declaringClass = $overload->declaringClass;
        $this->cppReturnType = $overload->returnType;
        $this->smartPointerReturnTargetCppType = $overload->smartPointerReturnTargetCppType;
        $this->paramCount = $overload->parameterCount();
        $this->requiredParamCount = $overload->requiredParameterCount();
        $this->access = $overload->access;
        $this->isConst = $overload->isConst;
        $this->isStatic = $overload->isStatic;
        $this->isVirtual = $overload->isVirtual;
        $this->isPureVirtual = $overload->isPureVirtual;

        // Map the C++ return type through the type mapper to get strategy
        $ownerClass = $overload->declaringClass !== '' ? $overload->declaringClass : $classCtx->nativeCppType;
        $this->phpReturnType = $overload->smartPointerReturnTargetCppType !== null
            ? $this->cppReturnToPhp($overload->smartPointerReturnTargetCppType, $ownerClass)
            : $this->cppReturnToPhp($overload->returnType, $ownerClass);
        $this->returnStrategy = $overload->smartPointerReturnTargetCppType !== null
            ? 'smart_pointer_alias'
            : $typeBridge->returnStrategyForCpp($this->phpReturnType, $overload->returnType);

        $params = [];
        foreach ($overload->parameters as $param) {
            $params[] = new OverloadParamContext($param, $ownerClass, $typeBridge, $this->classTypeResolver);
        }
        $this->params = $params;
    }

    /**
     * Quick C++ return type to PHP type mapping for strategy determination.
     */
    private function cppReturnToPhp(string $cppType, string $ownerClass): string
    {
        return $this->typeMapper->map(
            $cppType,
            $ownerClass,
            $this->classTypeResolver(),
            TypeResolutionContext::fromNames($this->classNameForContext($ownerClass), $ownerClass),
            $this->smartPointerAliases,
        );
    }

    private function classTypeResolver(): CppClassTypeResolver
    {
        return $this->classTypeResolver;
    }

    private function classNameForContext(string $ownerClass): string
    {
        if (!str_contains($ownerClass, '::')) {
            return $ownerClass;
        }

        return (string) substr($ownerClass, (int) strrpos($ownerClass, '::') + 2);
    }
}
