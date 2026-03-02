<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;

/**
 * Prepared template context for a single PHP method.
 *
 * Computes all C/C++ identifiers, ZPP patterns, and return handling
 * needed by the method templates.
 */
class MethodContext
{
    /** PHP method name */
    public readonly string $name;

    /** C++ method name (same as PHP for Qt) */
    public readonly string $cppName;

    /** Access: "public" or "protected" */
    public readonly string $access;

    /** Zend access flags string (e.g. "ZEND_ACC_PUBLIC") */
    public readonly string $accessFlags;

    /** Whether this is __construct */
    public readonly bool $isConstructor;

    /** Whether all overloads are static */
    public readonly bool $isStatic;

    /** Whether this method has multiple C++ overloads */
    public readonly bool $isOverloaded;

    /** Number of C++ overloads */
    public readonly int $overloadCount;

    /** PHP return type string (may be union) */
    public readonly string $returnType;

    /** PHP stub return type string (may be fully qualified) */
    public readonly string $stubReturnType;

    /** Return strategy: 'scalar', 'string', 'void', 'value_object', 'qobject_pointer', 'mixed', 'array' */
    public readonly string $returnStrategy;

    /** RETURN_* macro for scalar returns (e.g. "RETURN_LONG") or null */
    public readonly ?string $returnMacro;

    /** Minimum required argument count */
    public readonly int $requiredArgCount;

    /** Maximum argument count */
    public readonly int $maxArgCount;

    /** @var list<ParamContext> Merged PHP parameters */
    public readonly array $params;

    /** @var list<OverloadContext> Original C++ overload variants */
    public readonly array $overloads;

    /** The arginfo symbol name */
    public readonly string $arginfoName;

    /** Whether any param is an object type (needs cross-class include) */
    public readonly bool $hasObjectParams;

    /** Whether the return type is an object (needs cross-class include) */
    public readonly bool $returnsObject;

    public function __construct(
        PhpMethod $method,
        ClassContext $classCtx,
        TypeBridge $typeBridge,
    ) {
        $this->name = $method->name;
        $this->cppName = $method->name;
        $this->access = $method->access;
        $this->isConstructor = $method->name === '__construct';
        $this->isStatic = $method->isStatic;
        $this->isOverloaded = $method->isOverloaded();
        $this->overloadCount = $method->overloadCount();
        $this->returnType = $method->returnType;
        $this->stubReturnType = $typeBridge->stubType(
            $method->returnType,
            false,
            $classCtx->phpNamespace,
            $classCtx->classNamespaces,
        );

        // Access flags
        $flags = $method->access === 'public' ? 'ZEND_ACC_PUBLIC' : 'ZEND_ACC_PROTECTED';
        if ($method->isStatic) {
            $flags .= ' | ZEND_ACC_STATIC';
        }
        $this->accessFlags = $flags;

        // Return handling
        $primaryReturn = $this->primaryReturnType($method->returnType);
        $primaryCppReturn = $method->overloads[0]->returnType ?? $primaryReturn;
        $this->returnStrategy = $typeBridge->returnStrategyForCpp($primaryReturn, $primaryCppReturn);
        $this->returnMacro = $typeBridge->returnMacro($primaryReturn);
        $this->returnsObject = $typeBridge->isObjectType($primaryReturn);

        // Arginfo
        $this->arginfoName = $typeBridge->arginfoName(
            $classCtx->phpNamespace,
            $classCtx->phpClassName,
            $method->name,
        );

        // Parameters
        $params = [];
        $hasObjectParams = false;
        $requiredCount = 0;
        $seenOptional = false;

        foreach ($method->parameters as $param) {
            $paramCtx = new ParamContext($param, $classCtx, $typeBridge);
            $params[] = $paramCtx;

            if ($paramCtx->isObject) {
                $hasObjectParams = true;
            }

            if (!$param->hasDefault && !$seenOptional) {
                $requiredCount++;
            } else {
                $seenOptional = true;
            }
        }

        $this->params = $params;
        $this->hasObjectParams = $hasObjectParams;
        $this->requiredArgCount = $requiredCount;
        $this->maxArgCount = \count($params);

        // Overload contexts
        $overloads = [];
        foreach ($method->overloads as $overload) {
            $overloads[] = new OverloadContext($overload, $classCtx, $typeBridge);
        }
        $this->overloads = $overloads;
    }

    /**
     * Whether this method takes no parameters (including no optional ones).
     */
    public function hasNoParams(): bool
    {
        return $this->maxArgCount === 0;
    }

    /**
     * Extract the "primary" return type from a potential union type.
     * For template selection, we use the first type in the union.
     * The actual dispatch handles the full union.
     */
    private function primaryReturnType(string $returnType): string
    {
        if (!str_contains($returnType, '|')) {
            return $returnType;
        }

        $parts = explode('|', $returnType);

        return $parts[0];
    }
}
