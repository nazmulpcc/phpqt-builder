<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Parsing\CppToPhpTypeMapper;
use QtBuilder\Support\TypeResolutionContext;

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
    private readonly ClassContext $classCtx;

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

    /** Whether this PHP-visible method should be registered as abstract */
    public readonly bool $isAbstractMethod;

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
        $this->classCtx = $classCtx;
        $this->name = $method->name;
        $this->cppName = $method->cppName ?? $method->name;
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
        $primarySmartPointerTarget = $method->overloads[0]->smartPointerReturnTargetCppType ?? null;
        $this->returnStrategy = $primarySmartPointerTarget !== null
            ? 'smart_pointer_alias'
            : $typeBridge->returnStrategyForCpp($primaryReturn, $primaryCppReturn);
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
            if (!$this->isConstructor && $overload->access === 'protected' && !$overload->isPureVirtual) {
                $hasCallableProtectedOverloads = true;
            }
        }
        $this->hasVirtualOverloads = $hasVirtualOverloads;
        $this->hasPureVirtualOverloads = $hasPureVirtualOverloads;
        $this->hasCallableProtectedOverloads = $hasCallableProtectedOverloads;
        $this->isAbstractMethod = $method->isAbstractMethod;
    }

    public function accessShimHelperName(int $overloadIndex = 0): string
    {
        return sprintf('qt_access_%s_%d', $this->name, $overloadIndex);
    }

    public function hasInstanceProtectedCallPath(): bool
    {
        foreach ($this->overloads as $overload) {
            if (!$this->isConstructor && $overload->access === 'protected' && !$overload->isStatic && !$overload->isPureVirtual) {
                return true;
            }
        }

        return false;
    }

    public function shouldUseQStringUtf8Return(?OverloadContext $overload = null): bool
    {
        $selectedOverload = $overload ?? ($this->overloads[0] ?? null);
        if (!$selectedOverload instanceof OverloadContext) {
            return false;
        }

        return $this->classCtx->nativeCppType === 'QString'
            && !$this->isStatic
            && $this->cppName === 'toStdString'
            && $selectedOverload->cppReturnType === 'std::string';
    }

    public function shouldReturnNullForVoidOverload(?OverloadContext $overload = null): bool
    {
        $selectedOverload = $overload ?? ($this->overloads[0] ?? null);
        if (!$selectedOverload instanceof OverloadContext || $selectedOverload->returnStrategy !== 'void') {
            return false;
        }

        return $this->returnType === 'null' || str_contains($this->returnType, '|null') || str_contains($this->returnType, 'null|');
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

    /**
     * @return list<string>
     */
    public function overloadScoreSetupLines(OverloadContext $overload, int $overloadIndex, string $argcVar = '_argc'): array
    {
        $matchVar = sprintf('_qt_match_%d', $overloadIndex);
        $scoreVar = sprintf('_qt_score_%d', $overloadIndex);
        $lines = [
            sprintf('    bool %s = (%s >= %d && %s <= %d);', $matchVar, $argcVar, $overload->requiredParamCount, $argcVar, $overload->paramCount),
            sprintf('    int %s = -1;', $scoreVar),
            sprintf('    if (%s) {', $matchVar),
            sprintf('        %s = 0;', $scoreVar),
        ];

        foreach ($overload->params as $position => $param) {
            $paramScoreVar = sprintf('_qt_param_score_%d_%d', $overloadIndex, $position);
            $lines[] = sprintf('        if (%s >= %d) {', $argcVar, $position + 1);
            $lines[] = sprintf('            int %s = %s;', $paramScoreVar, $this->overloadParamMatchScoreExpr($position, $param));
            $lines[] = sprintf('            if (%s < 0) {', $paramScoreVar);
            $lines[] = sprintf('                %s = false;', $matchVar);
            $lines[] = '            } else {';
            $lines[] = sprintf('                %s += %s;', $scoreVar, $paramScoreVar);
            $lines[] = '            }';
            $lines[] = '        }';
        }

        $lines[] = sprintf('        if (!%s) {', $matchVar);
        $lines[] = sprintf('            %s = -1;', $scoreVar);
        $lines[] = '        }';
        $lines[] = '    }';

        return $lines;
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
            $effectivePhpType = $param->phpType;
            if ($param->smartPointerTargetCppType !== null) {
                $mapper = new CppToPhpTypeMapper();
                $ownerClass = $overload->declaringClass !== '' ? $overload->declaringClass : $classCtx->nativeCppType;
                $effectivePhpType = $mapper->map(
                    $param->smartPointerTargetCppType,
                    $this->ownerClassName($ownerClass),
                    $classCtx->classTypeResolver,
                    TypeResolutionContext::fromNames($this->ownerClassName($ownerClass), $ownerClass),
                    $classCtx->smartPointerAliases,
                );
            }
            $targetCppType = $overload->access === 'protected' && !$this->isConstructor
                ? $classCtx->typeBridge->accessShimBoundaryType($effectivePhpType, $param->cppType)
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
                phpType: $effectivePhpType,
                cppType: $targetCppType,
                sourceVarName: $sourceVarName,
                nativeVarName: sprintf('_qt_arg_%d', $i),
                sourceIsZval: $sourceIsZval,
                nullable: $nullable,
                isRvalueReference: $param->isRvalueReference,
                persistentStorageVar: $persistentStorageVar,
                pairedCountVarName: $pairedCountVarName,
                isWritableByRef: $mergedParam?->isByRef ?? false,
                isWritableByRefPointer: ($mergedParam?->isByRef ?? false) && $param->isWritableByRefPointer,
                isWritableQtString: ($mergedParam?->isByRef ?? false) && $param->isWritableQtString,
                nullableObjectFallbackExpr: $this->constructorNullableObjectFallbackExpr($overload, $i),
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

    private function ownerClassName(string $ownerClass): string
    {
        if (!str_contains($ownerClass, '::')) {
            return $ownerClass;
        }

        return (string) substr($ownerClass, (int) strrpos($ownerClass, '::') + 2);
    }

    private function constructorNullableObjectFallbackExpr(OverloadContext $overload, int $paramIndex): ?string
    {
        if (!$this->isConstructor) {
            return null;
        }

        if (!in_array($this->className, ['QMouseEvent', 'QWheelEvent'], true)) {
            return null;
        }

        $param = $overload->params[$paramIndex] ?? null;
        if ($param === null || !$param->hasDefault || $param->phpType !== 'QPointingDevice') {
            return null;
        }

        // Qt defaults these optional event-constructor device parameters to the
        // process primary pointing device; passing NULL crashes real construction.
        return 'QPointingDevice::primaryPointingDevice()';
    }

    /**
     * @return list<string>
     */
    public function writebackLines(ClassContext $classCtx, ?OverloadContext $overload = null): array
    {
        $overload ??= $this->overloads[0] ?? null;
        if ($overload === null) {
            return [];
        }

        $lines = [];

        foreach ($overload->params as $i => $param) {
            $mergedParam = $this->params[$i] ?? null;
            if ($mergedParam === null || !$mergedParam->isByRef || !$param->isWritableByRef) {
                continue;
            }

            $targetVar = $mergedParam->cVarName;
            $sourceExpr = $param->isWritableByRefPointer ? sprintf('(*_qt_arg_%d)', $i) : sprintf('_qt_arg_%d', $i);
            $guardExpr = $param->isWritableByRefPointer ? sprintf('_qt_arg_%d != NULL', $i) : 'true';

            $lines[] = sprintf('if (%s != NULL && %s) {', $targetVar, $guardExpr);
            $lines[] = sprintf('    ZEND_ASSERT(Z_TYPE_P(%s) == IS_REFERENCE);', $targetVar);

            if ($param->phpType === 'int') {
                $lines[] = sprintf('    ZEND_TRY_ASSIGN_REF_LONG(%s, (zend_long)(%s));', $targetVar, $sourceExpr);
            } elseif ($param->phpType === 'float') {
                $lines[] = sprintf('    ZEND_TRY_ASSIGN_REF_DOUBLE(%s, (double)(%s));', $targetVar, $sourceExpr);
            } elseif ($param->phpType === 'bool') {
                $lines[] = sprintf('    ZEND_TRY_ASSIGN_REF_BOOL(%s, (bool)(%s));', $targetVar, $sourceExpr);
            } elseif ($param->phpType === 'string' && $param->isWritableQtString) {
                if (str_contains($param->cppType, 'QByteArray')) {
                    $sizeExpr = sprintf('%s.size()', $sourceExpr);
                    $lines[] = sprintf('    zend_string *_qt_ref_str_%1$d = zend_string_alloc((size_t)%2$s, 0);', $i, $sizeExpr);
                    $lines[] = sprintf('    memcpy(ZSTR_VAL(_qt_ref_str_%d), %s.constData(), (size_t)%s);', $i, $sourceExpr, $sizeExpr);
                    $lines[] = sprintf('    ZSTR_VAL(_qt_ref_str_%1$d)[(size_t)%2$s] = \'\\0\';', $i, $sizeExpr);
                    $lines[] = sprintf('    ZEND_TRY_ASSIGN_REF_NEW_STR(%s, _qt_ref_str_%d);', $targetVar, $i);
                } else {
                    if (str_contains($param->cppType, 'QLatin1String')) {
                        $lines[] = sprintf(
                            '    QByteArray _qt_ref_utf8_%d = QString::fromLatin1(%s.data(), %s.size()).toUtf8();',
                            $i,
                            $sourceExpr,
                            $sourceExpr,
                        );
                    } else {
                        $lines[] = sprintf('    QByteArray _qt_ref_utf8_%d = %s.toUtf8();', $i, $sourceExpr);
                    }
                    $sizeExpr = sprintf('_qt_ref_utf8_%d.size()', $i);
                    $lines[] = sprintf('    zend_string *_qt_ref_str_%1$d = zend_string_alloc((size_t)%2$s, 0);', $i, $sizeExpr);
                    $lines[] = sprintf('    memcpy(ZSTR_VAL(_qt_ref_str_%d), _qt_ref_utf8_%d.constData(), (size_t)%s);', $i, $i, $sizeExpr);
                    $lines[] = sprintf('    ZSTR_VAL(_qt_ref_str_%1$d)[(size_t)%2$s] = \'\\0\';', $i, $sizeExpr);
                    $lines[] = sprintf('    ZEND_TRY_ASSIGN_REF_NEW_STR(%s, _qt_ref_str_%d);', $targetVar, $i);
                }
            }

            $lines[] = '}';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public function postCallLines(ClassContext $classCtx, ?OverloadContext $overload = null): array
    {
        if ($classCtx->phpClassName === 'QCoreApplication' && $this->name === 'postEvent') {
            $eventParam = $this->params[1] ?? null;
            if ($eventParam !== null) {
                $eventStruct = $classCtx->objectStructNameForPhpType('QEvent');
                $fromObj = $classCtx->fromObjFuncNameForPhpType('QEvent');

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

            $objectStruct = $classCtx->objectStructNameForPhpType($phpClassName);
            $fromObj = $classCtx->fromObjFuncNameForPhpType($phpClassName);

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

            $objectStruct = $classCtx->objectStructNameForPhpType($phpClassName);
            $fromObj = $classCtx->fromObjFuncNameForPhpType($phpClassName);

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

        if ($this->name === 'setLayout' && $classCtx->phpClassName === 'QWidget' && $this->paramHasPhpType($firstParam, 'QLayout')) {
            return [[0, 'QLayout']];
        }

        if ($this->name === 'addWidget' && $this->paramHasPhpType($firstParam, 'QWidget')) {
            return [[0, 'QWidget']];
        }

        if ($this->name === 'addLayout' && $this->paramHasPhpType($firstParam, 'QLayout')) {
            return [[0, 'QLayout']];
        }

        if (
            $this->name === 'setRootEntity'
            && $classCtx->phpClassName === 'QAspectEngine'
            && $firstParam->smartPointerTargetCppType !== null
            && $this->paramHasPhpType($firstParam, 'QEntity')
        ) {
            // Qt3D keeps the root entity through a QSharedPointer alias after
            // the call returns, so the PHP wrapper must stop owning the pointee.
            return [[0, 'QEntity']];
        }

        if (
            $this->name === 'registerAspect'
            && $classCtx->phpClassName === 'QAspectEngine'
            && $this->paramHasPhpType($firstParam, 'QAbstractAspect')
        ) {
            // QAspectEngine retains registered aspect instances.
            return [[0, 'QAbstractAspect']];
        }

        if ($this->name === 'setSurface' && $classCtx->phpClassName === 'QRenderSurfaceSelector' && $this->paramHasPhpType($firstParam, 'QObject')) {
            // The frame graph retains the target surface object beyond the
            // setter call. QWindow instances have no QObject parent, so the
            // generic parent-based ownership probe is too weak here.
            return [[0, 'QObject']];
        }

        return [];
    }

    /**
     * @return array{string, string}|null
     */
    private function ownershipProbeSpec(ClassContext $classCtx, int $paramIndex, OverloadParamContext $param): ?array
    {
        if (!$this->isObjectOnlyTypeUnion($param->phpType)) {
            return null;
        }

        $objectPhpType = $this->firstObjectType($param->phpType);
        if ($objectPhpType === null || $classCtx->typeBridge->isValueType($objectPhpType)) {
            return null;
        }

        // Smart-pointer aliases like QEntityPtr become non-owning QSharedPointer<T>
        // wrappers around an existing native pointer at the PHP boundary. The
        // callee may retain that shared-pointer alias after the PHP wrapper falls
        // out of scope, so the pointee must not be deleted by the PHP wrapper.
        if ($param->smartPointerTargetCppType !== null) {
            return [
                $objectPhpType,
                'true',
            ];
        }

        $probeVar = sprintf('_qt_owned_arg_%d', $paramIndex);

        return match ($objectPhpType) {
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
                $objectPhpType,
                sprintf('qt_native_has_qobject_parent(%s->native_ptr)', $probeVar),
            ],
        };
    }

    private function paramHasPhpType(OverloadParamContext $param, string $phpType): bool
    {
        foreach (explode('|', $param->phpType) as $candidate) {
            if ($this->phpTypeMatches(trim($candidate), $phpType)) {
                return true;
            }
        }

        return false;
    }

    private function phpTypeMatches(string $actual, string $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        if ($actual === '' || $actual === 'null') {
            return false;
        }

        $actualBase = $this->phpTypeBaseName($actual);
        $expectedBase = $this->phpTypeBaseName($expected);

        return $actualBase !== '' && $actualBase === $expectedBase;
    }

    private function phpTypeBaseName(string $phpType): string
    {
        $trimmed = ltrim(trim($phpType), '\\');
        if ($trimmed === '' || in_array($trimmed, ['null', 'int', 'float', 'bool', 'string', 'array', 'mixed', 'void'], true)) {
            return $trimmed;
        }

        $separator = strrpos($trimmed, '\\');
        if ($separator === false) {
            return $trimmed;
        }

        return substr($trimmed, $separator + 1);
    }

    private function isObjectOnlyTypeUnion(string $phpType): bool
    {
        $parts = array_values(array_filter(explode('|', $phpType), static fn(string $part): bool => trim($part) !== ''));
        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed === 'null') {
                continue;
            }

            if (!$this->typeBridge->isObjectType($trimmed)) {
                return false;
            }
        }

        return true;
    }

    private function firstObjectType(string $phpType): ?string
    {
        foreach (explode('|', $phpType) as $part) {
            $part = trim($part);
            if ($part === '' || $part === 'null') {
                continue;
            }

            if ($this->typeBridge->isObjectType($part)) {
                return $part;
            }
        }

        return null;
    }

    private function overloadParamMatchCondition(int $position, OverloadParamContext $param): string
    {
        $mergedParam = $this->params[$position] ?? null;
        if ($mergedParam === null) {
            return 'false';
        }

        if ($mergedParam->isParsedAsZval) {
            $matchVar = $mergedParam->isByRef
                ? $this->typeBridge->dereferencedZvalExpr($mergedParam->cVarName)
                : $mergedParam->cVarName;

            return $this->typeBridge->zvalTypeMatchExpr($matchVar, $param->phpType);
        }

        return $mergedParam->phpType === $param->phpType ? 'true' : 'false';
    }

    private function overloadParamMatchScoreExpr(int $position, OverloadParamContext $param): string
    {
        $mergedParam = $this->params[$position] ?? null;
        if ($mergedParam === null) {
            return '-1';
        }

        if ($mergedParam->isParsedAsZval) {
            $matchVar = $mergedParam->isByRef
                ? $this->typeBridge->dereferencedZvalExpr($mergedParam->cVarName)
                : $mergedParam->cVarName;

            return $this->overloadObjectAwareMatchScoreExpr($matchVar, $param->phpType, $param->cppType);
        }

        return $mergedParam->phpType === $param->phpType ? '500' : '-1';
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

    private function overloadObjectAwareMatchScoreExpr(string $varName, string $phpType, ?string $cppType = null): string
    {
        if ($this->typeBridge->isUnionType($phpType)) {
            $parts = array_values(array_filter(explode('|', $phpType), static fn(string $part): bool => $part !== ''));
            if ($parts === []) {
                return '-1';
            }

            $expr = $this->overloadObjectAwareMatchScoreExpr($varName, array_shift($parts), $cppType);
            foreach ($parts as $part) {
                $expr = sprintf('qt_match_score_max(%s, %s)', $expr, $this->overloadObjectAwareMatchScoreExpr($varName, $part, $cppType));
            }

            return $expr;
        }

        return match ($phpType) {
            'int' => sprintf('((Z_TYPE_P(%s) == IS_LONG) ? 500 : -1)', $varName),
            'float' => sprintf('((Z_TYPE_P(%s) == IS_DOUBLE) ? 500 : -1)', $varName),
            'string' => sprintf('((Z_TYPE_P(%s) == IS_STRING) ? %d : -1)', $varName, $this->stringOverloadMatchScore($cppType)),
            'bool' => sprintf('(((Z_TYPE_P(%1$s) == IS_TRUE || Z_TYPE_P(%1$s) == IS_FALSE)) ? 500 : -1)', $varName),
            'array' => sprintf('((Z_TYPE_P(%s) == IS_ARRAY) ? 500 : -1)', $varName),
            'null' => sprintf('((Z_TYPE_P(%s) == IS_NULL) ? 500 : -1)', $varName),
            'void' => '-1',
            'mixed' => '0',
            default => sprintf('qt_zval_object_match_score(%s, %s)', $varName, $this->classCtx->ceVarNameForPhpType($phpType)),
        };
    }

    private function stringOverloadMatchScore(?string $cppType): int
    {
        if ($cppType === null || $cppType === '') {
            return 500;
        }

        $base = trim($cppType);
        $base = preg_replace('/\bconst\b/', '', $base) ?? $base;
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? $base);
        $base = rtrim($base, '& ');
        if (!str_contains($base, '<')) {
            while (str_ends_with($base, '*')) {
                $base = rtrim(substr($base, 0, -1));
            }
        }

        return match ($base) {
            'QString' => 560,
            'QByteArray' => 550,
            'QAnyStringView' => 540,
            'QStringView' => 530,
            'QLatin1StringView' => 520,
            default => 500,
        };
    }
}
