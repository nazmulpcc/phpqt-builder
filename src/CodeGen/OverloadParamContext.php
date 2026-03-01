<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\OverloadParameter;

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

    public function __construct(
        OverloadParameter $param,
        TypeBridge $typeBridge,
    ) {
        $this->name = $param->name !== '' ? $param->name : 'p' . spl_object_id($param);
        $this->cppType = $param->cppType;
        $this->hasDefault = $param->hasDefault;

        // Quick mapping for dispatch logic
        $this->phpType = $this->mapType($param->cppType, $typeBridge);
    }

    private function mapType(string $cppType, TypeBridge $typeBridge): string
    {
        $normalized = trim($cppType);

        if (str_starts_with($normalized, 'const ')) {
            $normalized = substr($normalized, 6);
        }
        $normalized = rtrim(rtrim($normalized, '&'));
        if (str_ends_with($normalized, ' *') && !str_contains($normalized, '<')) {
            $normalized = rtrim(rtrim($normalized, '*'));
        }
        $normalized = trim($normalized);

        if ($typeBridge->isScalarType($normalized)) {
            return $normalized;
        }

        if (\in_array($normalized, ['QString', 'QByteArray', 'QLatin1String', 'char'], true)) {
            return 'string';
        }

        if ($normalized !== '' && ctype_upper($normalized[0])) {
            return $normalized;
        }

        return 'mixed';
    }
}
