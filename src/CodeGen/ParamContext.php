<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\PhpParameter;

/**
 * Prepared template context for a single PHP method parameter.
 */
class ParamContext
{
    /** PHP parameter name */
    public readonly string $name;

    /** C variable name (same as PHP name, sanitized) */
    public readonly string $cVarName;

    /** PHP type string (may be union) */
    public readonly string $phpType;

    /** Whether this parameter is optional */
    public readonly bool $isOptional;

    /** Position index (0-based) */
    public readonly int $position;

    /** Whether this is an object type */
    public readonly bool $isObject;

    /** Whether this is a union type */
    public readonly bool $isUnion;

    /** PHP stub type string, including "|null" when required for null defaults */
    public readonly string $stubPhpType;

    /** C variable type for declaration (e.g. "zend_long", "zval *") */
    public readonly string $cVarType;

    /** C default value expression (e.g. "0", "NULL") */
    public readonly string $cDefault;

    /** ZPP macro call (e.g. "Z_PARAM_LONG(width)") */
    public readonly string $zppMacro;

    /** For object types: the CE variable name (e.g. "qt_ce_QPoint") or null */
    public readonly ?string $ceVarName;

    public function __construct(
        PhpParameter $param,
        ClassContext $classCtx,
        TypeBridge $typeBridge,
    ) {
        $this->name = $param->name;
        $this->cVarName = $this->sanitizeCVarName($param->name);
        $this->phpType = $param->phpType;
        $this->isOptional = $param->hasDefault;
        $this->position = $param->position;
        $this->isUnion = $typeBridge->isUnionType($param->phpType);
        $this->stubPhpType = $typeBridge->stubType($param->phpType, $param->hasDefault);

        // For union types or object types, use zval*
        $primaryType = $this->primaryType($param->phpType);
        $this->isObject = $typeBridge->isObjectType($primaryType);

        if ($this->isUnion || $this->isObject) {
            $this->cVarType = 'zval *';
            $this->cDefault = 'NULL';
            $this->ceVarName = $this->isObject && !$this->isUnion
                ? $typeBridge->ceVarName($primaryType)
                : null;
            $this->zppMacro = $this->isObject && !$this->isUnion
                ? ($this->isOptional
                    ? $typeBridge->zppMacroOptional($primaryType, $this->cVarName, $this->ceVarName)
                    : $typeBridge->zppMacro($primaryType, $this->cVarName, $this->ceVarName))
                : sprintf('Z_PARAM_ZVAL(%s)', $this->cVarName);
        } else {
            $this->cVarType = $typeBridge->cVarType($primaryType);
            $this->cDefault = $typeBridge->cDefaultValue($primaryType);
            $this->ceVarName = null;
            $this->zppMacro = $typeBridge->zppMacro($primaryType, $this->cVarName);
        }
    }

    /**
     * Get a full C variable declaration line.
     */
    public function cDeclaration(): string
    {
        $default = $this->isOptional ? ' = ' . $this->cDefault : '';

        // For pointer types (e.g. "zval *"), the space + * is already part of cVarType.
        // For non-pointer types (e.g. "zend_long"), we need an explicit space.
        $separator = str_ends_with($this->cVarType, '*') ? '' : ' ';

        return sprintf('%s%s%s%s', $this->cVarType, $separator, $this->cVarName, $default);
    }

    private function primaryType(string $phpType): string
    {
        if (!str_contains($phpType, '|')) {
            return $phpType;
        }

        return explode('|', $phpType)[0];
    }

    /**
     * Sanitize a parameter name for use as a C variable.
     * Avoids C++ keywords and ensures valid identifier.
     */
    private function sanitizeCVarName(string $name): string
    {
        // C/C++ reserved words that might collide
        $reserved = ['class', 'new', 'delete', 'this', 'return', 'int', 'void',
            'bool', 'float', 'double', 'long', 'short', 'char', 'const',
            'static', 'virtual', 'default', 'switch', 'case', 'break',
            'continue', 'for', 'while', 'do', 'if', 'else', 'template',
            'namespace', 'using', 'operator', 'throw', 'try', 'catch',
            'public', 'private', 'protected', 'friend', 'inline', 'register',
            'volatile', 'extern', 'struct', 'union', 'enum', 'typedef',
            'unsigned', 'signed', 'sizeof', 'auto', 'goto', 'type',
        ];

        if (\in_array($name, $reserved, true)) {
            return $name . '_';
        }

        if ($name === '' || !preg_match('/^[a-zA-Z_]/', $name)) {
            return 'p' . $name;
        }

        return $name;
    }
}
