<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;

/**
 * Template context for a single C++ overload variant.
 *
 * Used by the overloaded method template to generate dispatch logic
 * (arg count checks, type checks) and the actual C++ method call.
 */
class OverloadContext
{
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

    public function __construct(
        MethodOverload $overload,
        ClassContext $classCtx,
        TypeBridge $typeBridge,
    ) {
        $this->cppReturnType = $overload->returnType;
        $this->paramCount = $overload->parameterCount();
        $this->requiredParamCount = $overload->requiredParameterCount();
        $this->isConst = $overload->isConst;
        $this->isStatic = $overload->isStatic;
        $this->isVirtual = $overload->isVirtual;
        $this->isPureVirtual = $overload->isPureVirtual;

        // Map the C++ return type through the type mapper to get strategy
        $phpReturnType = $this->cppReturnToPhp($overload->returnType, $typeBridge);
        $this->returnStrategy = $typeBridge->returnStrategy($phpReturnType);

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
        // Reuse the CppToPhpTypeMapper logic via a simple inline approach.
        // We need to normalize and check against known patterns.
        $normalized = trim($cppType);

        // Strip const and references
        if (str_starts_with($normalized, 'const ')) {
            $normalized = substr($normalized, 6);
        }
        $normalized = rtrim(rtrim($normalized, '&'));
        if (str_ends_with($normalized, ' *') && !str_contains($normalized, '<')) {
            $normalized = rtrim(rtrim($normalized, '*'));
        }
        $normalized = trim($normalized);

        // Check the type bridge for known types
        if ($typeBridge->isScalarType($normalized) || $normalized === 'void') {
            return $normalized;
        }

        if (\in_array($normalized, ['QString', 'QByteArray', 'QLatin1String'], true)) {
            return 'string';
        }

        if ($typeBridge->isValueType($normalized)) {
            return $normalized;
        }

        if ($normalized !== '' && ctype_upper($normalized[0])) {
            return $normalized;
        }

        return 'mixed';
    }
}
