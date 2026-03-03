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
    private readonly TypeBridge $typeBridge;
    private readonly string $className;

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

    /** Whether this method is a Qt signal */
    public readonly bool $isSignal;

    /** Whether this method is a Qt slot */
    public readonly bool $isSlot;

    /** Whether any overload is virtual */
    public readonly bool $hasVirtualOverloads;

    /** Whether any overload is pure virtual */
    public readonly bool $hasPureVirtualOverloads;

    /** Whether any overload is a callable protected base implementation */
    public readonly bool $hasCallableProtectedOverloads;

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
        $this->typeBridge = $typeBridge;
        $this->className = $classCtx->phpClassName;
        $this->name = $method->name;
        $this->cppName = $method->name;
        $this->access = $method->access;
        $this->isConstructor = $method->name === '__construct';
        $this->isStatic = $method->isStatic;
        $this->isSignal = $method->isSignal;
        $this->isSlot = $method->isSlot;
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
        $hasVirtualOverloads = false;
        $hasPureVirtualOverloads = false;
        $hasCallableProtectedOverloads = false;
        foreach ($overloads as $overload) {
            if ($overload->isVirtual || $overload->isPureVirtual) {
                $hasVirtualOverloads = true;
            }
            if ($overload->isPureVirtual) {
                $hasPureVirtualOverloads = true;
            }
            if ($overload->access === 'protected' && !$overload->isPureVirtual) {
                $hasCallableProtectedOverloads = true;
            }
        }
        $this->hasVirtualOverloads = $hasVirtualOverloads;
        $this->hasPureVirtualOverloads = $hasPureVirtualOverloads;
        $this->hasCallableProtectedOverloads = $hasCallableProtectedOverloads;
    }

    public function accessShimHelperName(int $overloadIndex = 0): string
    {
        return sprintf('qt_access_%s_%d', $this->name, $overloadIndex);
    }

    public function hasInstanceProtectedCallPath(): bool
    {
        foreach ($this->overloads as $overload) {
            if ($overload->access === 'protected' && !$overload->isStatic && !$overload->isPureVirtual) {
                return true;
            }
        }

        return false;
    }

    public function overloadMatchCondition(OverloadContext $overload, string $argcVar = '_argc'): string
    {
        $conditions = [
            sprintf('(%1$s >= %2$d && %1$s <= %3$d)', $argcVar, $overload->requiredParamCount, $overload->paramCount),
        ];

        foreach ($overload->params as $index => $param) {
            $conditions[] = sprintf(
                '(%s < %d || (%s))',
                $argcVar,
                $index + 1,
                $this->overloadParamMatchCondition($index, $param),
            );
        }

        return implode(' && ', $conditions);
    }

    public function noMatchingOverloadMessage(): string
    {
        return sprintf('No matching overload for %s::%s().', $this->className, $this->name);
    }

    public function ambiguousOverloadMessage(): string
    {
        return sprintf('Ambiguous overload resolution for %s::%s().', $this->className, $this->name);
    }

    /**
     * Whether this method takes no parameters (including no optional ones).
     */
    public function hasNoParams(): bool
    {
        return $this->maxArgCount === 0;
    }

    /**
     * @return array{setup_lines: list<string>, args: list<string>}
     */
    public function callPlan(
        ClassContext $classCtx,
        ?OverloadContext $overload = null,
        ?string $persistentStorageVar = null,
    ): array {
        $overload ??= $this->overloads[0] ?? null;
        if ($overload === null) {
            return ['setup_lines' => [], 'args' => []];
        }

        $setupLines = [];
        $args = [];
        /** @var array<int, string|null> $localVarNames */
        $localVarNames = [];

        foreach ($overload->params as $i => $param) {
            $mergedParam = $this->params[$i] ?? null;
            $sourceVarName = $mergedParam?->cVarName ?? $param->name;
            $sourceIsZval = $mergedParam?->isParsedAsZval ?? false;
            $nullable = $param->hasDefault;
            $pairedCountVarName = null;
            $targetCppType = $overload->access === 'protected' && !$this->isConstructor
                ? $classCtx->typeBridge->accessShimBoundaryType($param->phpType, $param->cppType)
                : $param->cppType;

            if (
                $param->isCharPointerArray
                && $i > 0
                && isset($overload->params[$i - 1])
                && $overload->params[$i - 1]->isNonConstReference
                && $overload->params[$i - 1]->phpType === 'int'
            ) {
                $pairedCountVarName = $localVarNames[$i - 1] ?? null;
            }

            $setup = $classCtx->typeBridge->nativeArgumentSetup(
                phpType: $param->phpType,
                cppType: $targetCppType,
                sourceVarName: $sourceVarName,
                nativeVarName: sprintf('_qt_arg_%d', $i),
                sourceIsZval: $sourceIsZval,
                nullable: $nullable,
                persistentStorageVar: $persistentStorageVar,
                pairedCountVarName: $pairedCountVarName,
            );

            foreach ($setup['lines'] as $line) {
                $setupLines[] = $line;
            }

            $args[] = $setup['expr'];
            $localVarNames[$i] = $setup['local_var'];
        }

        return [
            'setup_lines' => $setupLines,
            'args' => $args,
        ];
    }

    /**
     * @return list<string>
     */
    public function postCallLines(ClassContext $classCtx, ?OverloadContext $overload = null): array
    {
        if ($classCtx->phpClassName === 'QCoreApplication' && $this->name === 'postEvent') {
            $eventParam = $this->params[1] ?? null;
            if ($eventParam !== null) {
                $eventStruct = $classCtx->typeBridge->objectStructName('QEvent');
                $fromObj = $classCtx->typeBridge->fromObjFuncName('QEvent');

                return [
                    sprintf('%s *_qt_posted_event = %s(Z_OBJ_P(%s));', $eventStruct, $fromObj, $eventParam->cVarName),
                    '_qt_posted_event->prevent_destroy = true;',
                    '_qt_posted_event->native_ptr = NULL;',
                ];
            }
        }

        $overload ??= $this->overloads[0] ?? null;
        if ($overload === null) {
            return [];
        }

        $lines = [];
        $handledParamIndexes = [];

        foreach ($this->ownershipTransferSpecs($classCtx, $overload) as [$paramIndex, $phpClassName]) {
            $ownedParam = $this->params[$paramIndex] ?? null;
            if ($ownedParam === null) {
                continue;
            }

            $objectStruct = $classCtx->typeBridge->objectStructName($phpClassName);
            $fromObj = $classCtx->typeBridge->fromObjFuncName($phpClassName);

            $lines[] = sprintf('%s *_qt_owned_arg_%d = %s(Z_OBJ_P(%s));', $objectStruct, $paramIndex, $fromObj, $ownedParam->cVarName);
            $lines[] = sprintf('_qt_owned_arg_%d->prevent_destroy = true;', $paramIndex);
            $handledParamIndexes[$paramIndex] = true;
        }

        foreach ($overload->params as $paramIndex => $param) {
            if (isset($handledParamIndexes[$paramIndex])) {
                continue;
            }

            $ownershipProbe = $this->ownershipProbeSpec($classCtx, $paramIndex, $param);
            if ($ownershipProbe === null) {
                continue;
            }

            [$phpClassName, $probeExpr] = $ownershipProbe;
            $ownedParam = $this->params[$paramIndex] ?? null;
            if ($ownedParam === null) {
                continue;
            }

            $objectStruct = $classCtx->typeBridge->objectStructName($phpClassName);
            $fromObj = $classCtx->typeBridge->fromObjFuncName($phpClassName);

            $lines[] = sprintf('%s *_qt_owned_arg_%d = %s(Z_OBJ_P(%s));', $objectStruct, $paramIndex, $fromObj, $ownedParam->cVarName);
            $lines[] = sprintf('if (%s) {', $probeExpr);
            $lines[] = sprintf('    _qt_owned_arg_%d->prevent_destroy = true;', $paramIndex);
            $lines[] = '}';
        }

        return $lines;
    }

    /**
     * @return list<array{int, string}>
     */
    private function ownershipTransferSpecs(ClassContext $classCtx, OverloadContext $overload): array
    {
        $firstParam = $overload->params[0] ?? null;
        if ($firstParam === null) {
            return [];
        }

        if ($this->name === 'setLayout' && $classCtx->phpClassName === 'QWidget' && $firstParam->phpType === 'QLayout') {
            return [[0, 'QLayout']];
        }

        if ($this->name === 'addWidget' && $firstParam->phpType === 'QWidget') {
            return [[0, 'QWidget']];
        }

        if ($this->name === 'addLayout' && $firstParam->phpType === 'QLayout') {
            return [[0, 'QLayout']];
        }

        return [];
    }

    /**
     * @return array{string, string}|null
     */
    private function ownershipProbeSpec(ClassContext $classCtx, int $paramIndex, OverloadParamContext $param): ?array
    {
        if (!$classCtx->typeBridge->isObjectType($param->phpType) || $classCtx->typeBridge->isValueType($param->phpType)) {
            return null;
        }

        $probeVar = sprintf('_qt_owned_arg_%d', $paramIndex);

        return match ($param->phpType) {
            'QStandardItem' => [
                'QStandardItem',
                sprintf(
                    '(_qt_owned_arg_%1$d->native_ptr != NULL && (_qt_owned_arg_%1$d->native_ptr->model() != NULL || _qt_owned_arg_%1$d->native_ptr->parent() != NULL))',
                    $paramIndex,
                ),
            ],
            'QTableWidgetItem' => [
                'QTableWidgetItem',
                sprintf('(_qt_owned_arg_%d->native_ptr != NULL && _qt_owned_arg_%d->native_ptr->tableWidget() != NULL)', $paramIndex, $paramIndex),
            ],
            default => [
                $param->phpType,
                sprintf('qt_native_has_qobject_parent(%s->native_ptr)', $probeVar),
            ],
        };
    }

    private function overloadParamMatchCondition(int $position, OverloadParamContext $param): string
    {
        $mergedParam = $this->params[$position] ?? null;
        if ($mergedParam === null) {
            return 'false';
        }

        if ($mergedParam->isParsedAsZval) {
            return $this->typeBridge->zvalTypeMatchExpr($mergedParam->cVarName, $param->phpType);
        }

        return $mergedParam->phpType === $param->phpType ? 'true' : 'false';
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
