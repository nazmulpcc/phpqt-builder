<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Parsing\CppToPhpTypeMapper;

/**
 * Template context for a single C++ overload variant.
 *
 * Used by the overloaded method template to generate dispatch logic
 * (arg count checks, type checks) and the actual C++ method call.
 */
class OverloadContext
{
    private CppToPhpTypeMapper $typeMapper;

    /** C++ return type (raw) */
    public readonly string $cppReturnType;

    /** Number of C++ parameters */
    public readonly int $paramCount;

    /** Number of required (no default) C++ parameters */
    public readonly int $requiredParamCount;

    /** Whether this overload is const */
    public readonly bool $isConst;

    /** Whether this overload is static */
    public readonly bool $isStatic;

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
        $this->cppReturnType = $overload->returnType;
        $this->paramCount = $overload->parameterCount();
        $this->requiredParamCount = $overload->requiredParameterCount();
        $this->isConst = $overload->isConst;
        $this->isStatic = $overload->isStatic;
        $this->isVirtual = $overload->isVirtual;
        $this->isPureVirtual = $overload->isPureVirtual;

        // Map the C++ return type through the type mapper to get strategy
        $this->phpReturnType = $this->cppReturnToPhp($overload->returnType, $typeBridge);
        $this->returnStrategy = $typeBridge->returnStrategyForCpp($this->phpReturnType, $overload->returnType);

        $params = [];
        foreach ($overload->parameters as $param) {
            $params[] = new OverloadParamContext($param, $typeBridge);
        }
        $this->params = $params;
    }

    /**
     * Quick C++ return type to PHP type mapping for strategy determination.
     */
    private function cppReturnToPhp(string $cppType, TypeBridge $typeBridge): string
    {
        return $this->typeMapper->map($cppType);
    }
}
