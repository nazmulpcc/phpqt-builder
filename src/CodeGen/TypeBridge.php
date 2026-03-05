<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\ContainerType;

/**
 * Maps PHP type names (from the IR) to Zend C API constructs needed
 * in generated extension code.
 *
 * This covers:
 * - Zend type constants for arginfo (IS_LONG, IS_STRING, etc.)
 * - ZPP (zend_parse_parameters) macros for each type
 * - C variable declarations for parsed parameters
 * - RETURN_* macros for returning values to PHP
 * - Conversion expressions between C++/Qt types and PHP/Zend types
 */
class TypeBridge
{
    private ?ContainerBridge $containerBridge = null;

    /**
     * PHP scalar types -> Zend IS_* constants (for arginfo declarations).
     *
     * @var array<string, string>
     */
    private const array ZEND_TYPE_MAP = [
        'int' => 'IS_LONG',
        'float' => 'IS_DOUBLE',
        'string' => 'IS_STRING',
        'bool' => '_IS_BOOL',
        'array' => 'IS_ARRAY',
        'void' => 'IS_VOID',
        'mixed' => 'IS_MIXED',
        'null' => 'IS_NULL',
    ];

    /**
     * PHP scalar types -> MAY_BE_* bitmask constants (for union type arginfo).
     *
     * @var array<string, string>
     */
    private const array MAY_BE_MAP = [
        'int' => 'MAY_BE_LONG',
        'float' => 'MAY_BE_DOUBLE',
        'string' => 'MAY_BE_STRING',
        'bool' => 'MAY_BE_BOOL',
        'array' => 'MAY_BE_ARRAY',
        'null' => 'MAY_BE_NULL',
        'mixed' => 'MAY_BE_ANY',
    ];

    /**
     * PHP scalar types -> RETURN_* macro names.
     *
     * @var array<string, string>
     */
    private const array RETURN_MACRO_MAP = [
        'int' => 'RETURN_LONG',
        'float' => 'RETURN_DOUBLE',
        'bool' => 'RETURN_BOOL',
    ];

    /**
     * PHP scalar types -> C variable types for ZPP declarations.
     *
     * @var array<string, string>
     */
    private const array C_VAR_TYPE_MAP = [
        'int' => 'zend_long',
        'float' => 'double',
        'bool' => 'bool',
        'string' => 'zend_string *',
        'array' => 'zval *',
        'mixed' => 'zval *',
    ];

    /**
     * PHP scalar types -> ZPP fast-parse macro (no variable name filled in).
     *
     * @var array<string, string>
     */
    private const array ZPP_MACRO_MAP = [
        'int' => 'Z_PARAM_LONG',
        'float' => 'Z_PARAM_DOUBLE',
        'bool' => 'Z_PARAM_BOOL',
        'string' => 'Z_PARAM_STR',
        'array' => 'Z_PARAM_ARRAY',
        'mixed' => 'Z_PARAM_ZVAL',
    ];

    /**
     * Qt string types whose C++ values need conversion to/from PHP strings.
     *
     * @var list<string>
     */
    private const array QT_STRING_TYPES = [
        'std::filesystem::path',
        'std::string',
        'std::string_view',
        'std::wstring',
        'std::u16string',
        'std::u32string',
        'QString',
        'QByteArray',
        'QLatin1String',
        'QLatin1StringView',
        'QStringView',
        'QAnyStringView',
        'QUtf8StringView',
    ];

    /**
     * Known Qt value types that are copied (not pointer-owned by Qt parent).
     *
     * When returned from a C++ method, these are copy-constructed into
     * a new PHP object. When received as parameters, the C++ object
     * is extracted from the PHP wrapper.
     *
     * @var list<string>
     */
    private const array KNOWN_VALUE_TYPES = [
        'QBitArray',
        'QPoint', 'QPointF',
        'QSize', 'QSizeF',
        'QRect', 'QRectF',
        'QMargins', 'QMarginsF',
        'QColor',
        'QFont',
        'QIcon',
        'QPixmap',
        'QImage',
        'QCursor',
        'QKeySequence',
        'QUrl',
        'QLocale',
        'QSizePolicy',
        'QPalette',
        'QBrush',
        'QPen',
        'QRegion',
        'QVariant',
        'QModelIndex',
        'QPersistentModelIndex',
        'QDate', 'QTime', 'QDateTime',
    ];

    // ------------------------------------------------------------------
    // Query: Is this a scalar PHP type?
    // ------------------------------------------------------------------

    /**
     * Returns true if the PHP type is a built-in scalar (int, float, string, bool, array, void, mixed).
     */
    public function isScalarType(string $phpType): bool
    {
        return isset(self::ZEND_TYPE_MAP[$phpType]);
    }

    /**
     * Returns true if the PHP type is a Qt object class (not scalar).
     */
    public function isObjectType(string $phpType): bool
    {
        return !$this->isScalarType($phpType) && $phpType !== '';
    }

    /**
     * Returns true if the given class name is a known value type (copyable, no ownership).
     */
    public function isValueType(string $className): bool
    {
        return \in_array($className, self::KNOWN_VALUE_TYPES, true);
    }

    /**
     * @return list<string>
     */
    public function containerClassRefs(string $cppType): array
    {
        return $this->containerBridge()->classRefs($cppType);
    }

    public function isSupportedContainerType(string $cppType): bool
    {
        return $this->containerBridge()->isSupported($cppType);
    }

    /**
     * Returns true if the PHP type string is a union type (contains |).
     */
    public function isUnionType(string $phpType): bool
    {
        return str_contains($phpType, '|');
    }

    /**
     * Build a runtime type-check expression for a zval* against a PHP type.
     */
    public function zvalTypeMatchExpr(string $varName, string $phpType): string
    {
        if ($this->isUnionType($phpType)) {
            $parts = array_values(array_filter(explode('|', $phpType), static fn(string $part): bool => $part !== ''));
            $matches = array_map(
                fn(string $part): string => '(' . $this->zvalTypeMatchExpr($varName, $part) . ')',
                $parts,
            );

            return implode(' || ', $matches);
        }

        return match ($phpType) {
            'int' => sprintf('Z_TYPE_P(%s) == IS_LONG', $varName),
            'float' => sprintf('Z_TYPE_P(%s) == IS_DOUBLE', $varName),
            'string' => sprintf('Z_TYPE_P(%s) == IS_STRING', $varName),
            'bool' => sprintf('(Z_TYPE_P(%1$s) == IS_TRUE || Z_TYPE_P(%1$s) == IS_FALSE)', $varName),
            'array' => sprintf('Z_TYPE_P(%s) == IS_ARRAY', $varName),
            'null' => sprintf('Z_TYPE_P(%s) == IS_NULL', $varName),
            'void' => 'false',
            'mixed' => 'true',
            default => sprintf(
                '(Z_TYPE_P(%1$s) == IS_OBJECT && instanceof_function(Z_OBJCE_P(%1$s), %2$s))',
                $varName,
                $this->ceVarName($phpType),
            ),
        };
    }

    /**
     * Build a runtime match score expression for a zval* against a PHP type.
     *
     * Returns -1 for no match. Higher scores are more specific.
     */
    public function zvalTypeMatchScoreExpr(string $varName, string $phpType): string
    {
        if ($this->isUnionType($phpType)) {
            $parts = array_values(array_filter(explode('|', $phpType), static fn(string $part): bool => $part !== ''));
            if ($parts === []) {
                return '-1';
            }

            $expr = $this->zvalTypeMatchScoreExpr($varName, array_shift($parts));
            foreach ($parts as $part) {
                $expr = sprintf('qt_match_score_max(%s, %s)', $expr, $this->zvalTypeMatchScoreExpr($varName, $part));
            }

            return $expr;
        }

        return match ($phpType) {
            'int' => sprintf('((Z_TYPE_P(%s) == IS_LONG) ? 500 : -1)', $varName),
            'float' => sprintf('((Z_TYPE_P(%s) == IS_DOUBLE) ? 500 : -1)', $varName),
            'string' => sprintf('((Z_TYPE_P(%s) == IS_STRING) ? 500 : -1)', $varName),
            'bool' => sprintf('(((Z_TYPE_P(%1$s) == IS_TRUE || Z_TYPE_P(%1$s) == IS_FALSE)) ? 500 : -1)', $varName),
            'array' => sprintf('((Z_TYPE_P(%s) == IS_ARRAY) ? 500 : -1)', $varName),
            'null' => sprintf('((Z_TYPE_P(%s) == IS_NULL) ? 500 : -1)', $varName),
            'void' => '-1',
            'mixed' => '0',
            default => sprintf('qt_zval_object_match_score(%s, %s)', $varName, $this->ceVarName($phpType)),
        };
    }

    public function dereferencedZvalExpr(string $varName): string
    {
        return $this->zvalDerefExpr($varName);
    }

    // ------------------------------------------------------------------
    // Zend type constants (for arginfo)
    // ------------------------------------------------------------------

    /**
     * Get the Zend IS_* constant for a scalar PHP type.
     *
     * Returns null for object types (which use OBJ_INFO macros instead).
     */
    public function zendTypeConstant(string $phpType): ?string
    {
        return self::ZEND_TYPE_MAP[$phpType] ?? null;
    }

    /**
     * Get the MAY_BE_* bitmask for union type arginfo declarations.
     *
     * For object types, returns 'MAY_BE_OBJECT'.
     */
    public function mayBeConstant(string $phpType): string
    {
        return self::MAY_BE_MAP[$phpType] ?? 'MAY_BE_OBJECT';
    }

    /**
     * Build a MAY_BE_* bitmask expression from a union type string.
     *
     * E.g. "QPoint|QPointF" -> "MAY_BE_OBJECT"
     *      "int|string"     -> "MAY_BE_LONG|MAY_BE_STRING"
     *      "QSize|int"      -> "MAY_BE_OBJECT|MAY_BE_LONG"
     */
    public function mayBeMask(string $phpType): string
    {
        if (!$this->isUnionType($phpType)) {
            return $this->mayBeConstant($phpType);
        }

        $parts = explode('|', $phpType);
        $masks = [];

        foreach ($parts as $part) {
            $mask = $this->mayBeConstant($part);
            if (!\in_array($mask, $masks, true)) {
                $masks[] = $mask;
            }
        }

        return implode('|', $masks);
    }

    // ------------------------------------------------------------------
    // ZPP: zend_parse_parameters macros
    // ------------------------------------------------------------------

    /**
     * Get the ZPP fast-parse macro call for a PHP type.
     *
     * @param string $phpType   PHP type name
     * @param string $varName   C variable name to parse into
     * @param string|null $ceVar  For object types: the zend_class_entry* variable name
     * @return string  Complete ZPP macro line (e.g. "Z_PARAM_LONG(width)")
     */
    public function zppMacro(string $phpType, string $varName, ?string $ceVar = null): string
    {
        // Scalar types
        if (isset(self::ZPP_MACRO_MAP[$phpType])) {
            return sprintf('%s(%s)', self::ZPP_MACRO_MAP[$phpType], $varName);
        }

        // Object types
        if ($ceVar !== null) {
            return sprintf('Z_PARAM_OBJECT_OF_CLASS(%s, %s)', $varName, $ceVar);
        }

        // Fallback: treat as generic zval
        return sprintf('Z_PARAM_ZVAL(%s)', $varName);
    }

    /**
     * Get the ZPP macro for an optional parameter.
     *
     * @param string $phpType   PHP type name
     * @param string $varName   C variable name
     * @param string|null $ceVar  For objects: the CE variable
     * @return string  ZPP macro for optional variant
     */
    public function zppMacroOptional(string $phpType, string $varName, ?string $ceVar = null): string
    {
        if ($phpType === 'int') {
            return sprintf('Z_PARAM_LONG(%s)', $varName);
        }

        // For objects with optional, accept null too
        if ($ceVar !== null) {
            return sprintf('Z_PARAM_OBJECT_OF_CLASS_OR_NULL(%s, %s)', $varName, $ceVar);
        }

        return $this->zppMacro($phpType, $varName, $ceVar);
    }

    /**
     * Build the PHP stub type, appending "|null" when an optional parameter defaults to null.
     */
    public function stubType(
        string $phpType,
        bool $hasDefault,
        string $currentNamespace = '',
        array $classNamespaces = [],
    ): string
    {
        $parts = array_values(array_filter(
            explode('|', $phpType),
            static fn(string $part): bool => $part !== '',
        ));

        if (
            $hasDefault
            && $phpType !== 'mixed'
            && $phpType !== 'null'
            && !in_array($phpType, ['int', 'float', 'bool', 'string', 'array'], true)
            && !$this->typeIncludes($phpType, 'null')
        ) {
            $parts = array_values(array_filter(
                $parts,
                static fn(string $part): bool => $part !== 'null',
            ));
            $parts[] = 'null';
        }

        $qualifiedParts = [];
        foreach ($parts as $part) {
            $qualifiedParts[] = $this->qualifyStubTypePart($part, $currentNamespace, $classNamespaces);
        }

        return implode('|', $qualifiedParts);
    }

    /**
     * @param array<string, string> $classNamespaces
     */
    private function qualifyStubTypePart(string $type, string $currentNamespace, array $classNamespaces): string
    {
        if ($type === '' || $type === 'null' || $type === 'mixed' || isset(self::ZEND_TYPE_MAP[$type])) {
            return $type;
        }

        if (str_starts_with($type, '\\')) {
            return $type;
        }

        $namespace = $classNamespaces[$type] ?? null;
        if (!is_string($namespace) || $namespace === '') {
            return $type;
        }

        if ($currentNamespace !== '' && $namespace === $currentNamespace) {
            return '\\' . $namespace . '\\' . $type;
        }

        return '\\' . $namespace . '\\' . $type;
    }

    // ------------------------------------------------------------------
    // C variable declarations
    // ------------------------------------------------------------------

    /**
     * Get the C variable type for declaring a parsed parameter.
     */
    public function cVarType(string $phpType): string
    {
        return self::C_VAR_TYPE_MAP[$phpType] ?? 'zval *';
    }

    /**
     * Get a full C variable declaration with default value.
     *
     * @param string $phpType  PHP type name
     * @param string $varName  C variable name
     * @param bool   $optional Whether the parameter is optional
     * @return string  E.g. "zend_long width = 0"
     */
    public function cVarDeclaration(string $phpType, string $varName, bool $optional = false): string
    {
        $cType = $this->cVarType($phpType);
        $default = $optional ? ' = ' . $this->cDefaultValue($phpType) : '';

        // For pointer types (e.g. "zval *"), the space + * is already part of cType.
        // For non-pointer types (e.g. "zend_long"), we need an explicit space.
        $separator = str_ends_with($cType, '*') ? '' : ' ';

        return sprintf('%s%s%s%s', $cType, $separator, $varName, $default);
    }

    /**
     * Get the C default value for an optional parameter.
     */
    public function cDefaultValue(string $phpType): string
    {
        return match ($phpType) {
            'int' => '0',
            'float' => '0.0',
            'bool' => 'false',
            'string' => 'NULL',
            default => 'NULL',
        };
    }

    // ------------------------------------------------------------------
    // Return macros
    // ------------------------------------------------------------------

    /**
     * Get the RETURN_* macro for a scalar PHP return type.
     *
     * Returns null for non-scalar types (objects, void, etc.)
     * which need specialized return handling.
     */
    public function returnMacro(string $phpType): ?string
    {
        return self::RETURN_MACRO_MAP[$phpType] ?? null;
    }

    /**
     * Determine the return strategy for a given PHP return type.
     *
     * @return string One of: 'scalar', 'string', 'void', 'value_object', 'qobject_pointer', 'mixed', 'array'
     */
    public function returnStrategy(string $phpType): string
    {
        return $this->returnStrategyForCpp($phpType, $phpType);
    }

    /**
     * Determine the return strategy using both the PHP-facing type and the
     * original C++ return type.
     *
     * This matters for object returns because the mapped PHP type alone loses
     * whether C++ returned `T`, `T &`, or `T *`.
     *
     * @return string One of: 'scalar', 'string', 'void', 'value_object', 'qobject_pointer', 'mixed', 'array'
     */
    public function returnStrategyForCpp(string $phpType, string $cppType): string
    {
        if ($phpType === 'void') {
            return 'void';
        }

        if ($phpType === 'string') {
            return 'string';
        }

        if ($phpType === 'array') {
            return 'array';
        }

        if ($phpType === 'mixed') {
            return 'mixed';
        }

        if (isset(self::RETURN_MACRO_MAP[$phpType])) {
            return 'scalar';
        }

        // Object type — use the original C++ signature to distinguish value
        // returns from pointer returns. Unknown Qt value classes would
        // otherwise be misclassified as pointer-wrapped objects.
        if (!$this->isPointerType($cppType)) {
            return 'value_object';
        }

        return 'qobject_pointer';
    }

    /**
     * Build the declaration type for a returned object pointer variable.
     *
     * Preserves const qualifiers from the original C++ signature when present.
     */
    public function objectPointerReturnDeclarationType(string $cppType, string $phpClass): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $cppType) ?? $cppType);
        if ($normalized === '' || !str_contains($normalized, '*')) {
            return sprintf('%s *', $phpClass);
        }

        return $normalized;
    }

    /**
     * Return an expression suitable for wrap_native() calls that need a
     * non-const pointer.
     */
    public function writableObjectPointerExpr(string $cppType, string $phpClass, string $expr): string
    {
        if (preg_match('/\bconst\b/', $cppType) === 1) {
            return sprintf('const_cast<%s *>(%s)', $phpClass, $expr);
        }

        return $expr;
    }

    /**
     * Cast a native C++ scalar expression into the PHP-facing scalar type
     * expected by RETURN_LONG / RETURN_DOUBLE / RETURN_BOOL.
     */
    public function nativeScalarToPhpExpr(string $phpType, string $cppType, string $expr): string
    {
        if ($phpType === 'int' && $this->isChronoDurationType($cppType)) {
            return sprintf('(zend_long)(%s.count())', $expr);
        }

        return match ($phpType) {
            'int' => sprintf('(zend_long)(%s)', $expr),
            'float' => sprintf('(double)(%s)', $expr),
            'bool' => sprintf('(bool)(%s)', $expr),
            default => $expr,
        };
    }

    // ------------------------------------------------------------------
    // C++ <-> PHP conversion expressions
    // ------------------------------------------------------------------

    /**
     * Prepare the C++ setup needed to pass a PHP argument into a native call.
     *
     * Non-const references are treated as input-only: mutable locals are
     * materialized for the call, and native mutations are not written back to PHP.
     *
     * @return array{lines: list<string>, expr: string, local_var: ?string}
     */
    public function nativeArgumentSetup(
        string $phpType,
        string $cppType,
        string $sourceVarName,
        string $nativeVarName,
        bool $sourceIsZval = false,
        bool $nullable = false,
        bool $isRvalueReference = false,
        ?string $persistentStorageVar = null,
        ?string $pairedCountVarName = null,
        bool $isWritableByRef = false,
        bool $isWritableByRefPointer = false,
        bool $isWritableQtString = false,
    ): array {
        if (
            $persistentStorageVar !== null
            && $phpType === 'int'
            && $this->isNonConstReferenceType($cppType)
            && $this->normalizeCppType($cppType) === 'int'
        ) {
            $initExpr = $sourceIsZval
                ? $this->zvalToNativeExpr($phpType, $cppType, $sourceVarName, $nullable)
                : $this->directPhpToNativeExpr($phpType, $cppType, $sourceVarName, $nullable);

            return [
                'lines' => [
                    sprintf('%s & %s = %s->argc_value;', $this->cppCastType($cppType), $nativeVarName, $persistentStorageVar),
                    sprintf('%s = %s;', $nativeVarName, $initExpr),
                ],
                'expr' => $nativeVarName,
                'local_var' => $nativeVarName,
            ];
        }

        if ($phpType === 'array' && $this->isCharPointerArrayType($cppType)) {
            return [
                'lines' => [$this->charPointerArraySetupBlock(
                    sourceVarName: $sourceVarName,
                    nativeVarName: $nativeVarName,
                    persistentStorageVar: $persistentStorageVar,
                    pairedCountVarName: $pairedCountVarName,
                )],
                'expr' => $nativeVarName,
                'local_var' => $nativeVarName,
            ];
        }

        if ($phpType === 'array' && $this->isSupportedContainerType($cppType)) {
            return [
                'lines' => $this->phpArrayToNativeContainerLines($cppType, $sourceVarName, $nativeVarName),
                'expr' => $nativeVarName,
                'local_var' => $nativeVarName,
            ];
        }

        // Input-only scalar pointers still need native storage materialization
        // even when not writable-by-ref at the merged PHP signature level.
        if (
            !$isWritableByRef
            && $this->isPointerType($cppType)
            && in_array($phpType, ['int', 'float', 'bool'], true)
        ) {
            $valueVar = $nativeVarName . '_value';
            $baseType = $this->normalizeCppType($cppType);
            $valueExpr = $sourceIsZval ? $this->zvalDerefExpr($sourceVarName) : $sourceVarName;
            $initExpr = $sourceIsZval
                ? $this->zvalToNativeExpr($phpType, $cppType, $valueExpr, false)
                : $this->directPhpToNativeExpr($phpType, $cppType, $sourceVarName, false);
            $guardExpr = $nullable
                ? ($sourceIsZval
                    ? sprintf('(%s != NULL && Z_TYPE_P(%s) != IS_NULL)', $sourceVarName, $valueExpr)
                    : sprintf('(%s != NULL)', $sourceVarName))
                : 'true';

            return [
                'lines' => [
                    sprintf('%s %s;', $baseType, $valueVar),
                    sprintf('%s *%s = NULL;', $baseType, $nativeVarName),
                    sprintf('if (%s) {', $guardExpr),
                    sprintf('    %s = %s;', $valueVar, $initExpr),
                    sprintf('    %s = &%s;', $nativeVarName, $valueVar),
                    '}',
                ],
                'expr' => $nativeVarName,
                'local_var' => $nativeVarName,
            ];
        }

        if (!$isWritableByRef && $phpType === 'string' && $this->isQtStringPointerType($cppType)) {
            $storageVar = $nativeVarName . '_value';
            $baseType = $this->normalizeCppType($cppType);
            $sourceExpr = $sourceIsZval ? sprintf('Z_STR_P(%s)', $sourceVarName) : $sourceVarName;
            $guardExpr = $sourceIsZval
                ? sprintf('(%s != NULL && Z_TYPE_P(%s) == IS_STRING)', $sourceVarName, $sourceVarName)
                : sprintf('(%s != NULL)', $sourceVarName);

            return [
                'lines' => [
                    sprintf('%s %s;', $baseType, $storageVar),
                    sprintf('%s *%s = NULL;', $baseType, $nativeVarName),
                    sprintf('if (%s) {', $guardExpr),
                    sprintf('    %s = %s;', $storageVar, $this->phpStringToNativeExpr($cppType, $sourceExpr)),
                    sprintf('    %s = &%s;', $nativeVarName, $storageVar),
                    '}',
                ],
                'expr' => $nativeVarName,
                'local_var' => $nativeVarName,
            ];
        }

        if ($isWritableByRef && !$isWritableByRefPointer) {
            $sourceExpr = $sourceIsZval ? $this->zvalDerefExpr($sourceVarName) : $sourceVarName;
            $initExpr = $sourceIsZval
                ? $this->zvalToNativeExpr($phpType, $cppType, $sourceExpr, false)
                : $this->directPhpToNativeExpr($phpType, $cppType, $sourceVarName, false);

            return [
                'lines' => [sprintf('%s %s = %s;', $this->localValueType($phpType, $cppType), $nativeVarName, $initExpr)],
                'expr' => $nativeVarName,
                'local_var' => $nativeVarName,
            ];
        }

        if ($isWritableByRef && $isWritableByRefPointer) {
            $valueVar = $nativeVarName . '_value';
            $valueExpr = $sourceIsZval ? $this->zvalDerefExpr($sourceVarName) : $sourceVarName;
            $baseType = $this->normalizeCppType($cppType);
            $initExpr = $sourceIsZval
                ? $this->zvalToNativeExpr($phpType, $cppType, $valueExpr, false)
                : $this->directPhpToNativeExpr($phpType, $cppType, $sourceVarName, false);
            $guardExpr = $nullable
                ? ($sourceIsZval
                    ? sprintf('(%s != NULL && Z_TYPE_P(%s) != IS_NULL)', $sourceVarName, $valueExpr)
                    : sprintf('(%s != NULL)', $sourceVarName))
                : 'true';

            return [
                'lines' => [
                    sprintf('%s %s;', $baseType, $valueVar),
                    sprintf('%s *%s = NULL;', $baseType, $nativeVarName),
                    sprintf('if (%s) {', $guardExpr),
                    sprintf('    %s = %s;', $valueVar, $initExpr),
                    sprintf('    %s = &%s;', $nativeVarName, $valueVar),
                    '}',
                ],
                'expr' => $nativeVarName,
                'local_var' => $nativeVarName,
            ];
        }

        if ($isRvalueReference) {
            $initExpr = $sourceIsZval
                ? $this->zvalToNativeRvalueExpr($phpType, $cppType, $sourceVarName, $nullable)
                : $this->directPhpToNativeRvalueExpr($phpType, $cppType, $sourceVarName, $nullable);

            return [
                'lines' => [sprintf('%s %s = %s;', $this->localValueType($phpType, $cppType), $nativeVarName, $initExpr)],
                'expr' => sprintf('std::move(%s)', $nativeVarName),
                'local_var' => $nativeVarName,
            ];
        }

        if ($this->isNonConstReferenceType($cppType) && $this->shouldMaterializeReferenceLocal($phpType, $cppType)) {
            $initExpr = $sourceIsZval
                ? $this->zvalToNativeExpr($phpType, $cppType, $sourceVarName, $nullable)
                : $this->directPhpToNativeExpr($phpType, $cppType, $sourceVarName, $nullable);

            return [
                'lines' => [sprintf('%s %s = %s;', $this->localValueType($phpType, $cppType), $nativeVarName, $initExpr)],
                'expr' => $nativeVarName,
                'local_var' => $nativeVarName,
            ];
        }

        return [
            'lines' => [],
            'expr' => $sourceIsZval
                ? $this->zvalToNativeExpr($phpType, $cppType, $sourceVarName, $nullable)
                : $this->directPhpToNativeExpr($phpType, $cppType, $sourceVarName, $nullable),
            'local_var' => null,
        ];
    }

    public function accessShimBoundaryType(string $phpType, string $cppType, bool $forReturn = false): string
    {
        if (!$this->requiresAccessShimTypeErasure($cppType)) {
            return $cppType;
        }

        return match ($phpType) {
            'int' => 'zend_long',
            'float' => 'double',
            'bool' => 'bool',
            'string' => $forReturn ? $cppType : 'zend_string *',
            default => $cppType,
        };
    }

    public function accessShimBoundaryExpr(string $phpType, string $cppType, string $varName): string
    {
        $boundaryType = $this->accessShimBoundaryType($phpType, $cppType);
        if ($boundaryType === $cppType) {
            return $varName;
        }

        return match ($phpType) {
            'int' => $this->phpIntToNativeExpr($cppType, $varName),
            'float' => sprintf('(%s)%s', $this->cppCastType($cppType), $varName),
            'bool' => $varName,
            'string' => $this->phpStringToNativeExpr($cppType, $varName),
            default => $varName,
        };
    }

    public function accessShimBoundaryReturnExpr(string $phpType, string $cppType, string $expr): string
    {
        $boundaryType = $this->accessShimBoundaryType($phpType, $cppType, true);
        if ($boundaryType === $cppType) {
            return $expr;
        }

        return match ($phpType) {
            'int', 'float', 'bool' => $this->nativeScalarToPhpExpr($phpType, $cppType, $expr),
            default => $expr,
        };
    }

    /**
     * Generate a C++ expression that converts a C (ZPP-parsed) variable
     * to the C++ type needed by the Qt method call.
     *
     * @param string $phpType  PHP type name
     * @param string $cppType  Original C++ type (from overload)
     * @param string $varName  C variable name holding the parsed value
     * @param bool   $varIsZval  Whether the variable is a zval* (from union/object merged param)
     * @return string  C++ expression
     */
    public function phpToNativeExpr(
        string $phpType,
        string $cppType,
        string $varName,
        bool $varIsZval = false,
        bool $nullable = false,
    ): string
    {
        if ($varIsZval) {
            return $this->zvalToNativeExpr($phpType, $cppType, $varName, $nullable);
        }

        return $this->directPhpToNativeExpr($phpType, $cppType, $varName, $nullable);
    }

    /**
     * Generate a C++ expression that extracts a value from a zval* variable
     * and converts it to the target C++ type.
     *
     * Used when a merged parameter is zval* (union type or object) but a
     * specific overload branch needs it as a scalar or different type.
     *
     * @param string $phpType  The PHP type this overload expects (e.g. "int", "float", "string")
     * @param string $cppType  Original C++ type (from overload)
     * @param string $varName  C variable name (zval*)
     * @return string  C++ expression
     */
    public function zvalToNativeExpr(string $phpType, string $cppType, string $varName, bool $nullable = false): string
    {
        if ($nullable) {
            return match ($phpType) {
                'int' => sprintf(
                    '(%1$s != NULL && Z_TYPE_P(%1$s) == IS_LONG ? %2$s : %3$s)',
                    $varName,
                    $this->phpIntToNativeExpr($cppType, sprintf('Z_LVAL_P(%s)', $varName)),
                    $this->phpIntToNativeExpr($cppType, '0'),
                ),
                'float' => sprintf(
                    '(%1$s != NULL && Z_TYPE_P(%1$s) == IS_DOUBLE ? (%2$s)Z_DVAL_P(%1$s) : (%2$s)0.0)',
                    $varName,
                    $this->cppCastType($cppType),
                ),
                'bool' => sprintf('(%1$s != NULL && Z_TYPE_P(%1$s) == IS_TRUE)', $varName),
                'string' => sprintf(
                    '(%1$s != NULL && Z_TYPE_P(%1$s) == IS_STRING ? %2$s : %3$s)',
                    $varName,
                    $this->phpStringToNativeExpr($cppType, sprintf('Z_STR_P(%s)', $varName)),
                    $this->defaultNullableStringExpr($cppType),
                ),
                default => null,
            } ?? match ($phpType) {
                'array' => $varName,
                default => $this->isObjectType($phpType)
                    ? $this->phpObjectToNativeExpr($phpType, $cppType, $varName, true)
                    : $varName,
            };
        }

        return match ($phpType) {
            'int' => $this->phpIntToNativeExpr($cppType, sprintf('Z_LVAL_P(%s)', $varName)),
            'float' => sprintf('(%s)Z_DVAL_P(%s)', $this->cppCastType($cppType), $varName),
            'bool' => sprintf('Z_TYPE_P(%s) == IS_TRUE', $varName),
            'string' => $this->phpStringToNativeExpr($cppType, sprintf('Z_STR_P(%s)', $varName)),
            default => $this->isObjectType($phpType)
                ? $this->phpObjectToNativeExpr($phpType, $cppType, $varName, $nullable)
                : $varName,
        };
    }

    private function zvalToNativeRvalueExpr(string $phpType, string $cppType, string $varName, bool $nullable = false): string
    {
        if ($nullable) {
            return match ($phpType) {
                'int' => sprintf(
                    '(%1$s != NULL && Z_TYPE_P(%1$s) == IS_LONG ? %2$s : %3$s)',
                    $varName,
                    $this->phpIntToNativeExpr($cppType, sprintf('Z_LVAL_P(%s)', $varName)),
                    $this->phpIntToNativeExpr($cppType, '0'),
                ),
                'float' => sprintf(
                    '(%1$s != NULL && Z_TYPE_P(%1$s) == IS_DOUBLE ? (%2$s)Z_DVAL_P(%1$s) : (%2$s)0.0)',
                    $varName,
                    $this->cppCastType($cppType),
                ),
                'bool' => sprintf('(%1$s != NULL && Z_TYPE_P(%1$s) == IS_TRUE)', $varName),
                'string' => sprintf(
                    '(%1$s != NULL && Z_TYPE_P(%1$s) == IS_STRING ? %2$s : %3$s)',
                    $varName,
                    $this->phpStringToNativeExpr($cppType, sprintf('Z_STR_P(%s)', $varName)),
                    $this->defaultNullableStringExpr($cppType),
                ),
                default => null,
            } ?? match ($phpType) {
                'array' => $varName,
                default => $this->isObjectType($phpType)
                    ? $this->phpObjectToNativeRvalueExpr($phpType, $cppType, $varName, true)
                    : $varName,
            };
        }

        return match ($phpType) {
            'int' => $this->phpIntToNativeExpr($cppType, sprintf('Z_LVAL_P(%s)', $varName)),
            'float' => sprintf('(%s)Z_DVAL_P(%s)', $this->cppCastType($cppType), $varName),
            'bool' => sprintf('Z_TYPE_P(%s) == IS_TRUE', $varName),
            'string' => $this->phpStringToNativeExpr($cppType, sprintf('Z_STR_P(%s)', $varName)),
            default => $this->isObjectType($phpType)
                ? $this->phpObjectToNativeRvalueExpr($phpType, $cppType, $varName, $nullable)
                : $varName,
        };
    }

    private function directPhpToNativeExpr(string $phpType, string $cppType, string $varName, bool $nullable = false): string
    {
        return match ($phpType) {
            'int' => $this->phpIntToNativeExpr($cppType, $varName),
            'float' => sprintf('(%s)%s', $this->cppCastType($cppType), $varName),
            'bool' => $varName,
            'string' => $this->phpStringToNativeExpr($cppType, $varName),
            default => $this->isObjectType($phpType)
                ? $this->phpObjectToNativeExpr($phpType, $cppType, $varName, $nullable)
                : $varName,
        };
    }

    private function directPhpToNativeRvalueExpr(string $phpType, string $cppType, string $varName, bool $nullable = false): string
    {
        return match ($phpType) {
            'int' => $this->phpIntToNativeExpr($cppType, $varName),
            'float' => sprintf('(%s)%s', $this->cppCastType($cppType), $varName),
            'bool' => $varName,
            'string' => $this->phpStringToNativeExpr($cppType, $varName),
            default => $this->isObjectType($phpType)
                ? $this->phpObjectToNativeRvalueExpr($phpType, $cppType, $varName, $nullable)
                : $varName,
        };
    }

    /**
     * Generate a C++ expression that unwraps a zval* holding a PHP object
     * to the native C++ type needed by the Qt method call.
     *
     * @param string $phpType  PHP class name (e.g. "QPoint")
     * @param string $cppType  Original C++ type (e.g. "const QPoint &")
     * @param string $varName  C variable name (zval *)
     * @return string  C++ expression
     */
    public function phpObjectToNativeExpr(string $phpType, string $cppType, string $varName, bool $nullable = false): string
    {
        $fromObj = $this->fromObjFuncName($phpType);
        $baseExpr = sprintf('%s(Z_OBJ_P(%s))->native_ptr', $fromObj, $varName);

        // If C++ expects a pointer, pass the pointer directly
        if (str_contains($cppType, '*') && !str_contains($cppType, '&')) {
            if ($nullable) {
                return sprintf('(%1$s != NULL && Z_TYPE_P(%1$s) == IS_OBJECT ? %2$s : NULL)', $varName, $baseExpr);
            }

            return $baseExpr;
        }

        // Otherwise (const ref, value), dereference
        if ($nullable) {
            return sprintf(
                '(%1$s != NULL && Z_TYPE_P(%1$s) == IS_OBJECT ? *%2$s : %3$s())',
                $varName,
                $baseExpr,
                $this->normalizeCppType($cppType),
            );
        }

        return '*' . $baseExpr;
    }

    public function phpObjectToNativeRvalueExpr(string $phpType, string $cppType, string $varName, bool $nullable = false): string
    {
        $fromObj = $this->fromObjFuncName($phpType);
        $baseExpr = sprintf('%s(Z_OBJ_P(%s))->native_ptr', $fromObj, $varName);
        $normalizedType = $this->normalizeCppType($cppType);

        if ($nullable) {
            return sprintf(
                '(%1$s != NULL && Z_TYPE_P(%1$s) == IS_OBJECT ? %2$s : %3$s())',
                $varName,
                $this->nonNullableObjectRvalueExpr($normalizedType, $baseExpr),
                $normalizedType,
            );
        }

        return $this->nonNullableObjectRvalueExpr($normalizedType, $baseExpr);
    }

    /**
     * Generate a C++ expression converting a QString/QByteArray return value
     * to a PHP string return.
     *
     * @param string $cppType  The C++ return type
     * @param string $varName  C++ variable holding the return value
     * @return string  C code block for the return
     */
    public function nativeStringToPhpReturn(string $cppType, string $varName): string
    {
        $base = $this->normalizeCppType($cppType);
        $isPointer = $this->isPointerType($cppType);

        if ($this->isPointerType($cppType)) {
            if ($base === 'QString') {
                return sprintf(
                    "if (%s != NULL) {\n    QByteArray _utf8 = %s->toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size());\n}\n    RETURN_EMPTY_STRING()",
                    $varName,
                    $varName,
                );
            }

            if ($base === 'QByteArray') {
                return sprintf(
                    "if (%s != NULL) {\n    RETURN_STRINGL(%s->constData(), %s->size());\n}\n    RETURN_EMPTY_STRING()",
                    $varName,
                    $varName,
                    $varName,
                );
            }
        }

        if ($base === 'QByteArray') {
            if ($isPointer) {
                return sprintf(
                    "if (%s == nullptr) {\n    RETURN_EMPTY_STRING();\n}\n    RETURN_STRINGL(%s->constData(), %s->size())",
                    $varName,
                    $varName,
                    $varName,
                );
            }

            return sprintf('RETURN_STRINGL(%s.constData(), %s.size())', $varName, $varName);
        }

        if ($base === 'std::string' || $base === 'std::string_view') {
            if ($isPointer) {
                return sprintf(
                    "if (%s == nullptr) {\n    RETURN_EMPTY_STRING();\n}\n    RETURN_STRINGL(%s->data(), %s->size())",
                    $varName,
                    $varName,
                    $varName,
                );
            }

            return sprintf('RETURN_STRINGL(%s.data(), %s.size())', $varName, $varName);
        }

        if ($base === 'std::wstring') {
            if ($isPointer) {
                return sprintf(
                    "if (%s == nullptr) {\n    RETURN_EMPTY_STRING();\n}\n    QByteArray _utf8 = QString::fromStdWString(*%s).toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
                    $varName,
                    $varName,
                );
            }

            return sprintf(
                "QByteArray _utf8 = QString::fromStdWString(%s).toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
                $varName,
            );
        }

        if ($base === 'std::u16string') {
            if ($isPointer) {
                return sprintf(
                    "if (%s == nullptr) {\n    RETURN_EMPTY_STRING();\n}\n    QByteArray _utf8 = QString::fromStdU16String(*%s).toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
                    $varName,
                    $varName,
                );
            }

            return sprintf(
                "QByteArray _utf8 = QString::fromStdU16String(%s).toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
                $varName,
            );
        }

        if ($base === 'std::u32string') {
            if ($isPointer) {
                return sprintf(
                    "if (%s == nullptr) {\n    RETURN_EMPTY_STRING();\n}\n    QByteArray _utf8 = QString::fromStdU32String(*%s).toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
                    $varName,
                    $varName,
                );
            }

            return sprintf(
                "QByteArray _utf8 = QString::fromStdU32String(%s).toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
                $varName,
            );
        }

        if ($base === 'QAnyStringView') {
            if ($isPointer) {
                return sprintf(
                    "if (%s == nullptr) {\n    RETURN_EMPTY_STRING();\n}\n    QByteArray _utf8 = %s->toString().toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
                    $varName,
                    $varName,
                );
            }

            return sprintf(
                "QByteArray _utf8 = %s.toString().toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
                $varName,
            );
        }

        if ($base === 'std::filesystem::path') {
            if ($isPointer) {
                return sprintf(
                    "if (%s == nullptr) {\n    RETURN_EMPTY_STRING();\n}\n    std::string _path = %s->string();\n    RETURN_STRINGL(_path.data(), _path.size())",
                    $varName,
                    $varName,
                );
            }

            return sprintf(
                "std::string _path = %s.string();\n    RETURN_STRINGL(_path.data(), _path.size())",
                $varName,
            );
        }

        if ($base === 'char') {
            if ($this->isPointerType($cppType)) {
                return sprintf('RETURN_STRING(%s)', $varName);
            }

            return sprintf('RETURN_STRINGL(&%s, 1)', $varName);
        }

        if ($isPointer) {
            return sprintf(
                "if (%s == nullptr) {\n    RETURN_EMPTY_STRING();\n}\n    QByteArray _utf8 = %s->toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
                $varName,
                $varName,
            );
        }

        // Default: assume QString-like, convert via UTF-8
        return sprintf(
            "QByteArray _utf8 = %s.toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
            $varName,
        );
    }

    public function signalMethodSuffix(array $params): string
    {
        $params = $this->signalCallbackParams($params);
        if ($params === []) {
            return 'NoArgs';
        }

        $parts = array_map(
            fn(OverloadParamContext $param): string => $this->signalTypeSuffixPart($param->cppType, $param->phpType),
            $params,
        );

        return implode('', $parts);
    }

    public function signalSignature(string $signalName, OverloadContext $overload): string
    {
        $params = $this->signalCallbackParams($overload->params);
        $types = array_map(
            fn(OverloadParamContext $param): string => $this->normalizedSignalType($param->cppType),
            $params,
        );

        return sprintf('%s(%s)', $signalName, implode(',', $types));
    }

    public function signalMemberPointerExpr(string $declaringClass, string $methodName, OverloadContext $overload): string
    {
        $callbackParams = $this->signalCallbackParams($overload->params);
        if (count($callbackParams) !== count($overload->params)) {
            return sprintf('&%s::%s', $declaringClass, $methodName);
        }

        $parameterTypes = array_map(
            static fn(OverloadParamContext $param): string => $param->cppType,
            $overload->params,
        );
        $constQualifier = $overload->isConst ? ' const' : '';

        return sprintf(
            'static_cast<void (%s::*)(%s)%s>(&%s::%s)',
            $declaringClass,
            implode(', ', $parameterTypes),
            $constQualifier,
            $declaringClass,
            $methodName,
        );
    }

    public function signalArgToZvalBlock(string $zvalVar, string $phpType, string $cppType, string $sourceExpr, ?int $paramIndex = null): string
    {
        $strategy = $this->returnStrategyForCpp($phpType, $cppType);

        if ($strategy === 'array') {
            return $this->nativeContainerToPhpZvalBlock($zvalVar, $cppType, $sourceExpr, $paramIndex);
        }

        if ($strategy === 'scalar') {
            $scalarExpr = $sourceExpr;
            if ($this->isPointerType($cppType)) {
                $scalarExpr = match ($phpType) {
                    'int' => sprintf('(%1$s != NULL ? *%1$s : 0)', $sourceExpr),
                    'float' => sprintf('(%1$s != NULL ? *%1$s : 0.0)', $sourceExpr),
                    'bool' => sprintf('(%1$s != NULL ? *%1$s : false)', $sourceExpr),
                    default => $sourceExpr,
                };
            }

            return match ($phpType) {
                'int' => sprintf('ZVAL_LONG(%s, %s);', $zvalVar, $this->nativeScalarToPhpExpr($phpType, $cppType, $scalarExpr)),
                'float' => sprintf('ZVAL_DOUBLE(%s, %s);', $zvalVar, $this->nativeScalarToPhpExpr($phpType, $cppType, $scalarExpr)),
                'bool' => sprintf('ZVAL_BOOL(%s, %s);', $zvalVar, $this->nativeScalarToPhpExpr($phpType, $cppType, $scalarExpr)),
                default => sprintf('ZVAL_NULL(%s);', $zvalVar),
            };
        }

        if ($strategy === 'string') {
            $base = $this->normalizeCppType($cppType);

            if ($this->isPointerType($cppType)) {
                if ($base === 'QString') {
                    $utf8Var = $paramIndex === null
                        ? '_qt_utf8'
                        : sprintf('_qt_utf8_%d', $paramIndex);

                    return sprintf(
                        "if (%s != NULL) {\n    QByteArray %s = %s->toUtf8();\n    ZVAL_STRINGL(%s, %s.constData(), %s.size());\n} else {\n    ZVAL_NULL(%s);\n}",
                        $sourceExpr,
                        $utf8Var,
                        $sourceExpr,
                        $zvalVar,
                        $utf8Var,
                        $utf8Var,
                        $zvalVar,
                    );
                }

                if ($base === 'QByteArray') {
                    return sprintf(
                        "if (%s != NULL) {\n    ZVAL_STRINGL(%s, %s->constData(), %s->size());\n} else {\n    ZVAL_NULL(%s);\n}",
                        $sourceExpr,
                        $zvalVar,
                        $sourceExpr,
                        $sourceExpr,
                        $zvalVar,
                    );
                }
            }

            if ($base === 'QByteArray') {
                return sprintf('ZVAL_STRINGL(%s, %s.constData(), %s.size());', $zvalVar, $sourceExpr, $sourceExpr);
            }

            if ($base === 'std::string' || $base === 'std::string_view') {
                return sprintf('ZVAL_STRINGL(%s, %s.data(), %s.size());', $zvalVar, $sourceExpr, $sourceExpr);
            }

            if ($base === 'std::filesystem::path') {
                $pathVar = $paramIndex === null
                    ? '_qt_path'
                    : sprintf('_qt_path_%d', $paramIndex);

                return sprintf(
                    "std::string %s = %s.string();\n    ZVAL_STRINGL(%s, %s.data(), %s.size());",
                    $pathVar,
                    $sourceExpr,
                    $zvalVar,
                    $pathVar,
                    $pathVar,
                );
            }

            if ($base === 'char') {
                if ($this->isPointerType($cppType)) {
                    return sprintf(
                        "if (%s != NULL) {\n    ZVAL_STRING(%s, %s);\n} else {\n    ZVAL_NULL(%s);\n}",
                        $sourceExpr,
                        $zvalVar,
                        $sourceExpr,
                        $zvalVar,
                    );
                }

                return sprintf('ZVAL_STRINGL(%s, &%s, 1);', $zvalVar, $sourceExpr);
            }

            $utf8Var = $paramIndex === null
                ? '_qt_utf8'
                : sprintf('_qt_utf8_%d', $paramIndex);

            return sprintf(
                "QByteArray %s = %s.toUtf8();\n    ZVAL_STRINGL(%s, %s.constData(), %s.size());",
                $utf8Var,
                $sourceExpr,
                $zvalVar,
                $utf8Var,
                $utf8Var,
            );
        }

        if ($strategy === 'value_object') {
            return sprintf(
                "object_init_ex(%s, %s);\n    %s(%s)->native_ptr = new %s(%s);",
                $zvalVar,
                $this->ceVarName($phpType),
                $this->zMacroName($phpType),
                $zvalVar,
                $phpType,
                $sourceExpr,
            );
        }

        if ($strategy === 'qobject_pointer') {
            if ($this->isValueType($phpType)) {
                return sprintf(
                    "if (%s != NULL) {\n    object_init_ex(%s, %s);\n    %s(%s)->native_ptr = new %s(*%s);\n} else {\n    ZVAL_NULL(%s);\n}",
                    $sourceExpr,
                    $zvalVar,
                    $this->ceVarName($phpType),
                    $this->zMacroName($phpType),
                    $zvalVar,
                    $phpType,
                    $sourceExpr,
                    $zvalVar,
                );
            }

            return sprintf(
                '%s(%s, %s, %s, true);',
                $this->wrapNativeFuncName($phpType),
                $zvalVar,
                $this->writableObjectPointerExpr($cppType, $phpType, $sourceExpr),
                $this->ceVarName($phpType),
            );
        }

        return sprintf('ZVAL_NULL(%s);', $zvalVar);
    }

    public function zvalToNativeReturnExpr(string $phpType, string $cppType, string $zvalPtrExpr, bool $nullable = false): string
    {
        return match ($phpType) {
            'void' => '',
            'int' => $this->phpIntToNativeExpr($cppType, sprintf('zval_get_long(%s)', $zvalPtrExpr)),
            'float' => sprintf('(%s)zval_get_double(%s)', $this->cppCastType($cppType), $zvalPtrExpr),
            'bool' => sprintf('(bool)zend_is_true(%s)', $zvalPtrExpr),
            'string' => $this->phpStringToNativeExpr($cppType, sprintf('zval_get_string(%s)', $zvalPtrExpr)),
            default => $this->isObjectType($phpType)
                ? $this->phpObjectToNativeExpr($phpType, $cppType, $zvalPtrExpr, $nullable)
                : '',
        };
    }

    public function defaultNativeReturnExpr(string $phpType, string $cppType): string
    {
        $strategy = $this->returnStrategyForCpp($phpType, $cppType);

        return match ($strategy) {
            'void' => '',
            'scalar' => match ($phpType) {
                'bool' => 'false',
                'float' => '0.0',
                default => $this->phpIntToNativeExpr($cppType, '0'),
            },
            'string' => sprintf('%s()', $this->normalizeCppType($cppType)),
            'array' => sprintf('%s()', $this->normalizeCppType($cppType)),
            'qobject_pointer' => 'NULL',
            default => '{}',
        };
    }

    /**
     * @return array{lines: list<string>, expr: string, cleanup_lines: list<string>}
     */
    public function nativeReturnFromZvalSetup(string $phpType, string $cppType, string $zvalPtrExpr, string $tempPrefix = '_qt_ret'): array
    {
        if ($phpType === 'array' && $this->isSupportedContainerType($cppType)) {
            $failureExpr = sprintf('return %s;', $this->defaultNativeReturnExpr($phpType, $cppType));
            return [
                'lines' => $this->phpArrayToNativeContainerLines($cppType, $zvalPtrExpr, $tempPrefix, true, $failureExpr),
                'expr' => $tempPrefix,
                'cleanup_lines' => [],
            ];
        }

        if ($phpType === 'string') {
            $stringVar = $tempPrefix . '_string';

            return [
                'lines' => [
                    sprintf('zend_string *%s = zval_get_string(%s);', $stringVar, $zvalPtrExpr),
                ],
                'expr' => $this->phpStringToNativeExpr($cppType, $stringVar),
                'cleanup_lines' => [
                    sprintf('zend_string_release(%s);', $stringVar),
                ],
            ];
        }

        return [
            'lines' => [],
            'expr' => $this->zvalToNativeReturnExpr($phpType, $cppType, $zvalPtrExpr),
            'cleanup_lines' => [],
        ];
    }

    public function nativeContainerToPhpZvalBlock(string $zvalPtrExpr, string $cppType, string $sourceExpr, ?int $suffix = null): string
    {
        $container = $this->containerSpec($cppType);
        if ($container === null) {
            return sprintf('ZVAL_NULL(%s);', $zvalPtrExpr);
        }

        return $container->isSequence()
            ? $this->sequenceContainerToPhpBlock($zvalPtrExpr, $container, $sourceExpr, $suffix)
            : $this->mapContainerToPhpBlock($zvalPtrExpr, $container, $sourceExpr, $suffix);
    }

    // ------------------------------------------------------------------
    // Naming helpers for generated C symbols
    // ------------------------------------------------------------------

    /**
     * Convert a PHP class name to a lowercase C identifier component.
     *
     * "QWidget" -> "qwidget"
     */
    public function classToLower(string $className): string
    {
        return strtolower($className);
    }

    /**
     * Convert a PHP class name to an UPPER_CASE C identifier component.
     *
     * "QWidget" -> "QWIDGET"
     */
    public function classToUpper(string $className): string
    {
        return strtoupper($className);
    }

    /**
     * Build the zend_class_entry* variable name.
     *
     * "QWidget" -> "qt_ce_QWidget"
     */
    public function ceVarName(string $className): string
    {
        return sprintf('qt_ce_%s', $className);
    }

    /**
     * Build the object handlers variable name.
     *
     * "QWidget" -> "qt_qwidget_handlers"
     */
    public function handlersVarName(string $className): string
    {
        return sprintf('qt_%s_handlers', $this->classToLower($className));
    }

    /**
     * Build the custom object struct type name.
     *
     * "QWidget" -> "qt_qwidget_object"
     */
    public function objectStructName(string $className): string
    {
        return sprintf('qt_%s_object', $this->classToLower($className));
    }

    /**
     * Build the from_obj() inline function name.
     *
     * "QWidget" -> "qt_qwidget_from_obj"
     */
    public function fromObjFuncName(string $className): string
    {
        return sprintf('qt_%s_from_obj', $this->classToLower($className));
    }

    /**
     * Build the Z_*_P convenience macro name.
     *
     * "QWidget" -> "Z_QWIDGET_P"
     */
    public function zMacroName(string $className): string
    {
        return sprintf('Z_%s_P', $this->classToUpper($className));
    }

    /**
     * Build the wrap_native() helper function name (for QObject-derived types).
     *
     * "QWidget" -> "qt_qwidget_wrap_native"
     */
    public function wrapNativeFuncName(string $className): string
    {
        return sprintf('qt_%s_wrap_native', $this->classToLower($className));
    }

    /**
     * Build the Zend class symbol (used in ZEND_METHOD/ZEND_ME).
     *
     * @param string $namespace  PHP namespace (e.g. "Qt\\Widgets")
     * @param string $className  PHP class name (e.g. "QWidget")
     * @return string  E.g. "Qt_Widgets_QWidget"
     */
    public function zendClassSymbol(string $namespace, string $className): string
    {
        $parts = explode('\\', $namespace);
        $parts[] = $className;

        return implode('_', $parts);
    }

    /**
     * Build the arginfo symbol name for a method.
     *
     * @param string $namespace  PHP namespace
     * @param string $className  PHP class name
     * @param string $methodName PHP method name
     * @return string E.g. "arginfo_class_Qt_Widgets_QWidget_show"
     */
    public function arginfoName(string $namespace, string $className, string $methodName): string
    {
        return sprintf('arginfo_class_%s_%s', $this->zendClassSymbol($namespace, $className), $methodName);
    }

    /**
     * Build the MINIT function name for a class.
     *
     * "QWidget" -> "qt_qwidget"
     */
    public function minitName(string $className): string
    {
        return sprintf('qt_%s', $this->classToLower($className));
    }

    /**
     * Build the Qt C++ include path.
     *
     * "QWidget" -> "<QWidget>"
     */
    public function qtInclude(string $className): string
    {
        return sprintf('<%s>', $className);
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Determine the C++ cast type from the original C++ type string.
     * Strips const/ref/pointer to get the bare cast target.
     */
    private function cppCastType(string $cppType): string
    {
        $normalized = $this->normalizeCppType($cppType);

        if ($this->isChronoDurationType($normalized)) {
            return $normalized;
        }

        if ($this->isQtGlobalEnumLikeType($normalized)) {
            return $normalized;
        }

        if ($normalized !== '' && (str_contains($normalized, '::') || str_starts_with($normalized, 'QFlags<'))) {
            return $normalized;
        }

        return match ($normalized) {
            'short', 'unsigned short', 'qint8', 'qint16', 'quint8', 'quint16' => $normalized,
            'float' => 'float',
            'double', 'qreal' => 'double',
            'long', 'unsigned long' => $normalized,
            'long long', 'unsigned long long', 'qint64', 'quint64', 'qlonglong', 'qulonglong' => $normalized,
            default => 'int',
        };
    }

    private function isQtGlobalEnumLikeType(string $type): bool
    {
        if (!str_starts_with($type, 'Qt')) {
            return false;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $type) !== 1) {
            return false;
        }

        if ($type === 'QtMsgType') {
            return true;
        }

        foreach (['Type', 'Mode', 'Flag', 'Flags', 'Policy'] as $suffix) {
            if (str_ends_with($type, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a C++ type by stripping const, &, *.
     */
    private function normalizeCppType(string $type): string
    {
        $type = trim($type);

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

    private function zvalDerefExpr(string $varName): string
    {
        return sprintf('((Z_TYPE_P(%1$s) == IS_REFERENCE) ? Z_REFVAL_P(%1$s) : (%1$s))', $varName);
    }

    private function typeIncludes(string $phpType, string $needle): bool
    {
        return in_array($needle, explode('|', $phpType), true);
    }

    private function phpIntToNativeExpr(string $cppType, string $expr): string
    {
        $castType = $this->cppCastType($cppType);

        if ($this->isChronoDurationType($castType)) {
            return sprintf('%s((%s::rep)((int)(%s)))', $castType, $castType, $expr);
        }

        if (str_starts_with($castType, 'QFlags<')) {
            return sprintf('%s::fromInt((%s::Int)((int)(%s)))', $castType, $castType, $expr);
        }

        if (str_contains($castType, '::')) {
            return sprintf('(%s)((int)(%s))', $castType, $expr);
        }

        return sprintf('(%s)%s', $castType, $expr);
    }

    /**
     * Generate a C++ expression converting a PHP zend_string to the target C++ string type.
     */
    private function phpStringToNativeExpr(string $cppType, string $varName): string
    {
        $base = $this->normalizeCppType($cppType);

        if ($base === 'QByteArray') {
            return sprintf('QByteArray(ZSTR_VAL(%s), ZSTR_LEN(%s))', $varName, $varName);
        }

        if ($base === 'std::string') {
            return sprintf('std::string(ZSTR_VAL(%s), ZSTR_LEN(%s))', $varName, $varName);
        }

        if ($base === 'std::string_view') {
            return sprintf('std::string_view(ZSTR_VAL(%s), (size_t)ZSTR_LEN(%s))', $varName, $varName);
        }

        if ($base === 'std::wstring') {
            return sprintf('QString::fromUtf8(ZSTR_VAL(%s), (int)ZSTR_LEN(%s)).toStdWString()', $varName, $varName);
        }

        if ($base === 'std::u16string') {
            return sprintf('QString::fromUtf8(ZSTR_VAL(%s), (int)ZSTR_LEN(%s)).toStdU16String()', $varName, $varName);
        }

        if ($base === 'std::u32string') {
            return sprintf('QString::fromUtf8(ZSTR_VAL(%s), (int)ZSTR_LEN(%s)).toStdU32String()', $varName, $varName);
        }

        if ($base === 'std::filesystem::path') {
            return sprintf('std::filesystem::path(std::string(ZSTR_VAL(%s), ZSTR_LEN(%s)))', $varName, $varName);
        }

        if ($base === 'char') {
            if ($this->isPointerType($cppType)) {
                return sprintf('ZSTR_VAL(%s)', $varName);
            }

            return sprintf('(ZSTR_LEN(%s) > 0 ? ZSTR_VAL(%s)[0] : \'\\0\')', $varName, $varName);
        }

        // Default: QString
        return sprintf('QString::fromUtf8(ZSTR_VAL(%s), (int)ZSTR_LEN(%s))', $varName, $varName);
    }

    private function defaultNullableStringExpr(string $cppType): string
    {
        if ($this->isPointerType($cppType)) {
            return 'NULL';
        }

        return sprintf('%s()', $this->normalizeCppType($cppType));
    }

    private function isPointerType(string $cppType): bool
    {
        return str_contains($cppType, '*');
    }

    private function isQtStringPointerType(string $cppType): bool
    {
        if (!$this->isPointerType($cppType)) {
            return false;
        }

        if (preg_match('/\bconst\b/', $cppType) === 1 || preg_match('/\*\s*\*/', $cppType) === 1) {
            return false;
        }

        $base = $this->normalizeCppType($cppType);

        return $base === 'QString' || $base === 'QByteArray';
    }

    private function containerBridge(): ContainerBridge
    {
        return $this->containerBridge ??= new ContainerBridge();
    }

    private function containerSpec(string $cppType): ?ContainerType
    {
        return $this->containerBridge()->parse($cppType);
    }

    private function isNonConstReferenceType(string $cppType): bool
    {
        $trimmed = trim($cppType);

        return str_contains($trimmed, '&') && preg_match('/^\s*const\b/', $trimmed) !== 1;
    }

    private function isCharPointerArrayType(string $cppType): bool
    {
        $normalized = preg_replace('/\bconst\b/', '', $cppType) ?? $cppType;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        return preg_match('/^char\s*\*\s*\*$/', $normalized) === 1;
    }

    /**
     * @return list<string>
     */
    private function phpArrayToNativeContainerLines(
        string $cppType,
        string $sourceVarName,
        string $nativeVarName,
        bool $sourceIsZval = false,
        string $failureStatement = 'RETURN_THROWS();',
    ): array {
        $container = $this->containerSpec($cppType);
        if ($container === null) {
            return [sprintf('%s %s;', $this->normalizeCppType($cppType), $nativeVarName)];
        }

        if ($container->isSequence()) {
            return $this->phpArrayToSequenceLines($container, $sourceVarName, $nativeVarName, $sourceIsZval, $failureStatement);
        }

        return $this->phpArrayToMapLines($container, $sourceVarName, $nativeVarName, $sourceIsZval, $failureStatement);
    }

    /**
     * @return list<string>
     */
    private function phpArrayToSequenceLines(ContainerType $container, string $sourceVarName, string $nativeVarName, bool $sourceIsZval, string $failureStatement): array
    {
        $containerType = $this->normalizeCppType($container->rawType);
        $entryVar = $nativeVarName . '_entry';
        $stringVar = $nativeVarName . '_str';
        $valueVar = $nativeVarName . '_value';
        $phpType = $this->containerBridge()->elementPhpType((string) $container->elementType);
        $nullable = $this->isPointerType((string) $container->elementType);

        $lines = [
            sprintf('%s %s;', $containerType, $nativeVarName),
            sprintf('if (%s != NULL) {', $sourceVarName),
        ];
        if ($sourceIsZval) {
            $lines[] = sprintf('    if (Z_TYPE_P(%s) != IS_ARRAY) {', $sourceVarName);
            $lines[] = '        zend_type_error("Expected PHP array for Qt container conversion.");';
            $lines[] = '        ' . $failureStatement;
            $lines[] = '    }';
        }
        $lines[] = sprintf('    HashTable *%s_ht = Z_ARRVAL_P(%s);', $nativeVarName, $sourceVarName);
        $lines[] = sprintf('    zval *%s;', $entryVar);
        $lines[] = sprintf('    ZEND_HASH_FOREACH_VAL(%s_ht, %s) {', $nativeVarName, $entryVar);

        $lines = array_merge($lines, $this->sequenceInputValueLines($container, $phpType, $entryVar, $valueVar, $stringVar, $nullable, $failureStatement));
        $lines[] = sprintf('        %s.append(%s);', $nativeVarName, $valueVar);
        $lines[] = '    } ZEND_HASH_FOREACH_END();';
        $lines[] = '}';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function sequenceInputValueLines(
        ContainerType $container,
        string $phpType,
        string $entryVar,
        string $valueVar,
        string $stringVar,
        bool $nullable,
        string $failureStatement,
    ): array {
        $elementType = (string) $container->elementType;
        $lines = [];

        if ($elementType === 'QVariant') {
            $lines[] = sprintf('        QVariant %s;', $valueVar);
            $lines[] = sprintf('        if (!qt_zval_to_variant(%s, &%s)) {', $entryVar, $valueVar);
            $lines[] = '            ' . $failureStatement;
            $lines[] = '        }';

            return $lines;
        }

        if ($phpType === 'string') {
            $lines[] = sprintf('        if (Z_TYPE_P(%s) != IS_STRING) {', $entryVar);
            $lines[] = '            zend_type_error("Expected array of strings.");';
            $lines[] = '            ' . $failureStatement;
            $lines[] = '        }';
            $lines[] = sprintf('        zend_string *%s = zval_get_string(%s);', $stringVar, $entryVar);
            $lines[] = sprintf('        %s %s = %s;', $this->localContainerNativeType($elementType), $valueVar, $this->phpStringToNativeExpr($elementType, $stringVar));
            $lines[] = sprintf('        zend_string_release(%s);', $stringVar);

            return $lines;
        }

        if (in_array($phpType, ['int', 'float', 'bool'], true)) {
            $matchExpr = $this->zvalTypeMatchExpr($entryVar, $phpType);
            $nativeExpr = $this->zvalToNativeExpr($phpType, $elementType, $entryVar, false);
            $lines[] = sprintf('        if (!(%s)) {', $matchExpr);
            $lines[] = sprintf('            zend_type_error("Expected array of %ss.");', $phpType);
            $lines[] = '            ' . $failureStatement;
            $lines[] = '        }';
            $lines[] = sprintf('        %s %s = %s;', $this->localContainerNativeType($elementType), $valueVar, $nativeExpr);

            return $lines;
        }

        $objectCheck = $nullable
            ? sprintf('(Z_TYPE_P(%1$s) == IS_NULL || (Z_TYPE_P(%1$s) == IS_OBJECT && instanceof_function(Z_OBJCE_P(%1$s), %2$s)))', $entryVar, $this->ceVarName($phpType))
            : sprintf('(Z_TYPE_P(%1$s) == IS_OBJECT && instanceof_function(Z_OBJCE_P(%1$s), %2$s))', $entryVar, $this->ceVarName($phpType));
        $lines[] = sprintf('        if (!(%s)) {', $objectCheck);
        $lines[] = sprintf('            zend_type_error("Expected array of %s objects.");', $phpType);
        $lines[] = '            ' . $failureStatement;
        $lines[] = '        }';
        $nativeExpr = $nullable
            ? sprintf('(Z_TYPE_P(%1$s) == IS_NULL ? NULL : %2$s(Z_OBJ_P(%1$s))->native_ptr)', $entryVar, $this->fromObjFuncName($phpType))
            : $this->phpObjectToNativeExpr($phpType, $elementType, $entryVar, false);
        $lines[] = sprintf('        %s %s = %s;', $this->localContainerNativeType($elementType), $valueVar, $nativeExpr);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function phpArrayToMapLines(ContainerType $container, string $sourceVarName, string $nativeVarName, bool $sourceIsZval, string $failureStatement): array
    {
        $containerType = $this->normalizeCppType($container->rawType);
        $keyType = (string) $container->keyType;
        $valueType = (string) $container->valueType;
        $keyPhpType = $this->containerBridge()->elementPhpType($keyType);
        $valuePhpType = $this->containerBridge()->elementPhpType($valueType);
        $valueNullable = $this->isPointerType($valueType);
        $keyVar = $nativeVarName . '_key';
        $valueVar = $nativeVarName . '_value';
        $stringVar = $nativeVarName . '_str';

        $lines = [
            sprintf('%s %s;', $containerType, $nativeVarName),
            sprintf('if (%s != NULL) {', $sourceVarName),
        ];
        if ($sourceIsZval) {
            $lines[] = sprintf('    if (Z_TYPE_P(%s) != IS_ARRAY) {', $sourceVarName);
            $lines[] = '        zend_type_error("Expected PHP array for Qt container conversion.");';
            $lines[] = '        ' . $failureStatement;
            $lines[] = '    }';
        }
        $lines[] = sprintf('    HashTable *%s_ht = Z_ARRVAL_P(%s);', $nativeVarName, $sourceVarName);
        $lines[] = sprintf('    zend_string *%s_key_str;', $nativeVarName);
        $lines[] = sprintf('    zend_ulong %s_key_num;', $nativeVarName);
        $lines[] = sprintf('    zval *%s_entry;', $nativeVarName);
        $lines[] = sprintf('    ZEND_HASH_FOREACH_KEY_VAL(%s_ht, %s_key_num, %s_key_str, %s_entry) {', $nativeVarName, $nativeVarName, $nativeVarName, $nativeVarName);

        if ($keyPhpType === 'int') {
            $lines[] = sprintf('        %s %s;', $this->localContainerNativeType($keyType), $keyVar);
            $lines[] = sprintf('        if (%1$s_key_str == NULL) { %2$s = %3$s; } else {', $nativeVarName, $keyVar, $this->phpIntToNativeExpr($keyType, sprintf('(zend_long)%s_key_num', $nativeVarName)));
            $lines[] = '            zend_long _qt_key_long = 0;';
            $lines[] = '            double _qt_key_double = 0;';
            $lines[] = sprintf('            if (is_numeric_string(ZSTR_VAL(%1$s_key_str), ZSTR_LEN(%1$s_key_str), &_qt_key_long, &_qt_key_double, false) != IS_LONG) {', $nativeVarName);
            $lines[] = '                zend_type_error("Expected integer array keys.");';
            $lines[] = '                ' . $failureStatement;
            $lines[] = '            }';
            $lines[] = sprintf('            %s = %s;', $keyVar, $this->phpIntToNativeExpr($keyType, '_qt_key_long'));
            $lines[] = '        }';
        } else {
            $lines[] = sprintf('        %s %s;', $this->localContainerNativeType($keyType), $keyVar);
            $lines[] = sprintf('        if (%1$s_key_str != NULL) {', $nativeVarName);
            $lines[] = sprintf('            %s = %s;', $keyVar, $this->phpStringToNativeExpr($keyType, sprintf('%s_key_str', $nativeVarName)));
            $lines[] = '        } else {';
            $lines[] = sprintf('            %s = %s;', $keyVar, $this->integerMapKeyToStringExpr($keyType, sprintf('%s_key_num', $nativeVarName)));
            $lines[] = '        }';
        }

        if ($valueType === 'QVariant') {
            $lines[] = sprintf('        QVariant %s;', $valueVar);
            $lines[] = sprintf('        if (!qt_zval_to_variant(%s_entry, &%s)) {', $nativeVarName, $valueVar);
            $lines[] = '            ' . $failureStatement;
            $lines[] = '        }';
        } elseif ($valuePhpType === 'string') {
            $lines[] = sprintf('        if (Z_TYPE_P(%s_entry) != IS_STRING) {', $nativeVarName);
            $lines[] = '            zend_type_error("Expected string map values.");';
            $lines[] = '            ' . $failureStatement;
            $lines[] = '        }';
            $lines[] = sprintf('        zend_string *%s = zval_get_string(%s_entry);', $stringVar, $nativeVarName);
            $lines[] = sprintf('        %s %s = %s;', $this->localContainerNativeType($valueType), $valueVar, $this->phpStringToNativeExpr($valueType, $stringVar));
            $lines[] = sprintf('        zend_string_release(%s);', $stringVar);
        } elseif (in_array($valuePhpType, ['int', 'float', 'bool'], true)) {
            $lines[] = sprintf('        if (!(%s)) {', $this->zvalTypeMatchExpr(sprintf('%s_entry', $nativeVarName), $valuePhpType));
            $lines[] = sprintf('            zend_type_error("Expected %s map values.");', $valuePhpType);
            $lines[] = '            ' . $failureStatement;
            $lines[] = '        }';
            $lines[] = sprintf('        %s %s = %s;', $this->localContainerNativeType($valueType), $valueVar, $this->zvalToNativeExpr($valuePhpType, $valueType, sprintf('%s_entry', $nativeVarName), false));
        } else {
            $valueCheck = $valueNullable
                ? sprintf('(Z_TYPE_P(%1$s_entry) == IS_NULL || (Z_TYPE_P(%1$s_entry) == IS_OBJECT && instanceof_function(Z_OBJCE_P(%1$s_entry), %2$s)))', $nativeVarName, $this->ceVarName($valuePhpType))
                : sprintf('(Z_TYPE_P(%1$s_entry) == IS_OBJECT && instanceof_function(Z_OBJCE_P(%1$s_entry), %2$s))', $nativeVarName, $this->ceVarName($valuePhpType));
            $lines[] = sprintf('        if (!(%s)) {', $valueCheck);
            $lines[] = sprintf('            zend_type_error("Expected %s map values.");', $valuePhpType);
            $lines[] = '            ' . $failureStatement;
            $lines[] = '        }';
            $nativeExpr = $valueNullable
                ? sprintf('(Z_TYPE_P(%1$s_entry) == IS_NULL ? NULL : %2$s(Z_OBJ_P(%1$s_entry))->native_ptr)', $nativeVarName, $this->fromObjFuncName($valuePhpType))
                : $this->phpObjectToNativeExpr($valuePhpType, $valueType, sprintf('%s_entry', $nativeVarName), false);
            $lines[] = sprintf('        %s %s = %s;', $this->localContainerNativeType($valueType), $valueVar, $nativeExpr);
        }

        $lines[] = sprintf('        %s.insert(%s, %s);', $nativeVarName, $keyVar, $valueVar);
        $lines[] = '    } ZEND_HASH_FOREACH_END();';
        $lines[] = '}';

        return $lines;
    }

    private function sequenceContainerToPhpBlock(string $zvalPtrExpr, ContainerType $container, string $sourceExpr, ?int $suffix): string
    {
        $itemVar = $suffix === null ? '_qt_item' : sprintf('_qt_item_%d', $suffix);
        $valueVar = $suffix === null ? '_qt_value' : sprintf('_qt_value_%d', $suffix);
        $lines = [
            sprintf('array_init_size(%s, (uint32_t)%s.size());', $zvalPtrExpr, $sourceExpr),
            sprintf('for (const auto &%s : %s) {', $itemVar, $sourceExpr),
            sprintf('    zval %s;', $valueVar),
            '    ZVAL_NULL(&' . $valueVar . ');',
        ];
        foreach ($this->nativeElementToZvalLines($container->elementType ?? '', $itemVar, '&' . $valueVar, $suffix) as $line) {
            $lines[] = '    ' . $line;
        }
        $lines[] = sprintf('    add_next_index_zval(%s, &%s);', $zvalPtrExpr, $valueVar);
        $lines[] = '}';

        return implode("\n    ", $lines);
    }

    private function mapContainerToPhpBlock(string $zvalPtrExpr, ContainerType $container, string $sourceExpr, ?int $suffix): string
    {
        $itVar = $suffix === null ? '_qt_it' : sprintf('_qt_it_%d', $suffix);
        $valueVar = $suffix === null ? '_qt_value' : sprintf('_qt_value_%d', $suffix);
        $keyType = (string) $container->keyType;
        $keyPhpType = $this->containerBridge()->elementPhpType($keyType);
        $lines = [
            sprintf('array_init_size(%s, (uint32_t)%s.size());', $zvalPtrExpr, $sourceExpr),
            sprintf('for (auto %1$s = %2$s.cbegin(); %1$s != %2$s.cend(); ++%1$s) {', $itVar, $sourceExpr),
            sprintf('    zval %s;', $valueVar),
            '    ZVAL_NULL(&' . $valueVar . ');',
        ];
        foreach ($this->nativeElementToZvalLines($container->valueType ?? '', sprintf('%s.value()', $itVar), '&' . $valueVar, $suffix) as $line) {
            $lines[] = '    ' . $line;
        }
        if ($keyPhpType === 'int') {
            $lines[] = sprintf('    add_index_zval(%s, (zend_long)%s.key(), &%s);', $zvalPtrExpr, $itVar, $valueVar);
        } else {
            $keyStringVar = $suffix === null ? '_qt_key_utf8' : sprintf('_qt_key_utf8_%d', $suffix);
            if ($this->normalizeCppType($keyType) === 'QByteArray') {
                $lines[] = sprintf('    QByteArray %s = %s.key();', $keyStringVar, $itVar);
            } else {
                $lines[] = sprintf('    QByteArray %s = %s.key().toUtf8();', $keyStringVar, $itVar);
            }
            $lines[] = sprintf('    add_assoc_zval_ex(%s, %s.constData(), %s.size(), &%s);', $zvalPtrExpr, $keyStringVar, $keyStringVar, $valueVar);
        }
        $lines[] = '}';

        return implode("\n    ", $lines);
    }

    /**
     * @return list<string>
     */
    private function nativeElementToZvalLines(string $cppType, string $sourceExpr, string $zvalPtrExpr, ?int $suffix): array
    {
        $phpType = $this->containerBridge()->elementPhpType($cppType);
        $strategy = $this->returnStrategyForCpp($phpType, $cppType);

        if ($cppType === 'QVariant') {
            return [sprintf('qt_variant_to_zval(%s, %s);', $zvalPtrExpr, $sourceExpr)];
        }

        if ($strategy === 'scalar') {
            return [match ($phpType) {
                'int' => sprintf('ZVAL_LONG(%s, %s);', $zvalPtrExpr, $this->nativeScalarToPhpExpr($phpType, $cppType, $sourceExpr)),
                'float' => sprintf('ZVAL_DOUBLE(%s, %s);', $zvalPtrExpr, $this->nativeScalarToPhpExpr($phpType, $cppType, $sourceExpr)),
                'bool' => sprintf('ZVAL_BOOL(%s, %s);', $zvalPtrExpr, $this->nativeScalarToPhpExpr($phpType, $cppType, $sourceExpr)),
                default => sprintf('ZVAL_NULL(%s);', $zvalPtrExpr),
            }];
        }

        if ($strategy === 'string') {
            return $this->nativeStringToPhpZvalLines($zvalPtrExpr, $cppType, $sourceExpr, $suffix);
        }

        if ($strategy === 'value_object') {
            return [
                sprintf('object_init_ex(%s, %s);', $zvalPtrExpr, $this->ceVarName($phpType)),
                sprintf('%s(%s)->native_ptr = new %s(%s);', $this->zMacroName($phpType), $zvalPtrExpr, $phpType, $sourceExpr),
            ];
        }

        if ($strategy === 'qobject_pointer') {
            if ($this->isValueType($phpType)) {
                return [
                    sprintf('if (%s != NULL) {', $sourceExpr),
                    sprintf('    object_init_ex(%s, %s);', $zvalPtrExpr, $this->ceVarName($phpType)),
                    sprintf('    %s(%s)->native_ptr = new %s(*%s);', $this->zMacroName($phpType), $zvalPtrExpr, $phpType, $sourceExpr),
                    '} else {',
                    sprintf('    ZVAL_NULL(%s);', $zvalPtrExpr),
                    '}',
                ];
            }

            return [sprintf('%s(%s, %s, %s, true);', $this->wrapNativeFuncName($phpType), $zvalPtrExpr, $this->writableObjectPointerExpr($cppType, $phpType, $sourceExpr), $this->ceVarName($phpType))];
        }

        return [sprintf('ZVAL_NULL(%s);', $zvalPtrExpr)];
    }

    private function integerMapKeyToStringExpr(string $cppType, string $sourceExpr): string
    {
        $base = $this->normalizeCppType($cppType);

        return match ($base) {
            'QByteArray' => sprintf('QByteArray::number((qlonglong)%s)', $sourceExpr),
            default => sprintf('QString::number((qlonglong)%s)', $sourceExpr),
        };
    }

    private function localContainerNativeType(string $cppType): string
    {
        $type = trim($cppType);
        $type = preg_replace('/\bconst\b/', '', $type) ?? $type;
        $type = trim(preg_replace('/\s+/', ' ', $type) ?? $type);
        $type = rtrim($type, '& ');

        return trim($type);
    }

    /**
     * @return list<string>
     */
    private function nativeStringToPhpZvalLines(string $zvalPtrExpr, string $cppType, string $sourceExpr, ?int $suffix): array
    {
        $base = $this->normalizeCppType($cppType);

        if ($base === 'QByteArray') {
            return [sprintf('ZVAL_STRINGL(%s, %s.constData(), %s.size());', $zvalPtrExpr, $sourceExpr, $sourceExpr)];
        }

        if ($base === 'std::string' || $base === 'std::string_view') {
            return [sprintf('ZVAL_STRINGL(%s, %s.data(), %s.size());', $zvalPtrExpr, $sourceExpr, $sourceExpr)];
        }

        if ($base === 'std::filesystem::path') {
            $pathVar = $suffix === null ? '_qt_path' : sprintf('_qt_path_%d', $suffix);

            return [
                sprintf('std::string %s = %s.string();', $pathVar, $sourceExpr),
                sprintf('ZVAL_STRINGL(%s, %s.data(), %s.size());', $zvalPtrExpr, $pathVar, $pathVar),
            ];
        }

        if ($base === 'char') {
            if ($this->isPointerType($cppType)) {
                return [
                    sprintf('if (%s != NULL) {', $sourceExpr),
                    sprintf('    ZVAL_STRING(%s, %s);', $zvalPtrExpr, $sourceExpr),
                    '} else {',
                    sprintf('    ZVAL_NULL(%s);', $zvalPtrExpr),
                    '}',
                ];
            }

            return [sprintf('ZVAL_STRINGL(%s, &%s, 1);', $zvalPtrExpr, $sourceExpr)];
        }

        $utf8Var = $suffix === null ? '_qt_utf8' : sprintf('_qt_utf8_%d', $suffix);

        return [
            sprintf('QByteArray %s = %s.toUtf8();', $utf8Var, $sourceExpr),
            sprintf('ZVAL_STRINGL(%s, %s.constData(), %s.size());', $zvalPtrExpr, $utf8Var, $utf8Var),
        ];
    }

    private function shouldMaterializeReferenceLocal(string $phpType, string $cppType): bool
    {
        if ($phpType === 'array') {
            return false;
        }

        if (!$this->isObjectType($phpType)) {
            return true;
        }

        return $this->isValueType($this->normalizeCppType($cppType));
    }

    private function localValueType(string $phpType, string $cppType): string
    {
        return match ($phpType) {
            'int', 'float' => $this->cppCastType($cppType),
            'bool' => 'bool',
            default => $this->normalizeCppType($cppType),
        };
    }

    private function nonNullableObjectRvalueExpr(string $normalizedType, string $baseExpr): string
    {
        return match ($normalizedType) {
            'QJSPrimitiveValue' => sprintf('QJSPrimitiveValue(*%s)', $baseExpr),
            'QJSManagedValue' => sprintf('QJSManagedValue(%1$s->toJSValue(), %1$s->engine())', $baseExpr),
            default => sprintf('%s(*%s)', $normalizedType, $baseExpr),
        };
    }

    private function charPointerArraySetupBlock(
        string $sourceVarName,
        string $nativeVarName,
        ?string $persistentStorageVar = null,
        ?string $pairedCountVarName = null,
    ): string {
        $storageExpr = $persistentStorageVar !== null
            ? $persistentStorageVar . '->argv_storage'
            : $nativeVarName . '_storage';
        $pointersExpr = $persistentStorageVar !== null
            ? $persistentStorageVar . '->argv_pointers'
            : $nativeVarName . '_pointers';
        $lines = [];

        if ($persistentStorageVar === null) {
            $lines[] = sprintf('std::vector<QByteArray> %s;', $storageExpr);
            $lines[] = sprintf('std::vector<char *> %s;', $pointersExpr);
        }

        $lines[] = sprintf('char ** %s = NULL;', $nativeVarName);
        $lines[] = sprintf('%s.clear();', $storageExpr);
        $lines[] = sprintf('%s.clear();', $pointersExpr);
        $lines[] = sprintf('if (%s != NULL) {', $sourceVarName);
        $lines[] = sprintf('    HashTable *%s_ht = Z_ARRVAL_P(%s);', $nativeVarName, $sourceVarName);
        $lines[] = sprintf('    zval *%s_entry;', $nativeVarName);
        $lines[] = sprintf('    ZEND_HASH_FOREACH_VAL(%s_ht, %s_entry) {', $nativeVarName, $nativeVarName);
        $lines[] = sprintf('        zend_string *%s_str = zval_get_string(%s_entry);', $nativeVarName, $nativeVarName);
        $lines[] = sprintf('        %s.emplace_back(ZSTR_VAL(%s_str), (int)ZSTR_LEN(%s_str));', $storageExpr, $nativeVarName, $nativeVarName);
        $lines[] = sprintf('        zend_string_release(%s_str);', $nativeVarName);
        $lines[] = '    } ZEND_HASH_FOREACH_END();';
        $lines[] = '}';
        $lines[] = sprintf('if (%s.empty()) {', $storageExpr);
        $lines[] = sprintf('    %s.emplace_back("php", 3);', $storageExpr);
        $lines[] = '}';
        $lines[] = sprintf('%s.reserve(%s.size() + 1);', $pointersExpr, $storageExpr);
        $lines[] = sprintf('for (QByteArray &%s_item : %s) {', $nativeVarName, $storageExpr);
        $lines[] = sprintf('    %s.push_back(%s_item.data());', $pointersExpr, $nativeVarName);
        $lines[] = '}';
        $lines[] = sprintf('%s.push_back(NULL);', $pointersExpr);
        $lines[] = sprintf('%s = %s.data();', $nativeVarName, $pointersExpr);

        if ($pairedCountVarName !== null) {
            $lines[] = sprintf('%s = (int)%s.size();', $pairedCountVarName, $storageExpr);
        }

        return implode("\n    ", $lines);
    }

    private function isChronoDurationType(string $cppType): bool
    {
        $normalized = $this->normalizeCppType($cppType);

        if (str_starts_with($normalized, 'std::chrono::duration<')) {
            return true;
        }

        foreach ([
            'std::chrono::nanoseconds',
            'std::chrono::microseconds',
            'std::chrono::milliseconds',
            'std::chrono::seconds',
            'std::chrono::minutes',
            'std::chrono::hours',
            'std::chrono::days',
            'std::chrono::weeks',
            'std::chrono::months',
            'std::chrono::years',
        ] as $durationType) {
            if ($normalized === $durationType) {
                return true;
            }
        }

        return false;
    }

    private function requiresAccessShimTypeErasure(string $cppType): bool
    {
        $castType = $this->cppCastType($cppType);

        return str_contains($castType, '::') || str_starts_with($castType, 'QFlags<');
    }

    private function normalizedSignalType(string $cppType): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $cppType) ?? $cppType);
        $normalized = preg_replace('/\bconst\b\s*/', '', $normalized) ?? $normalized;
        $normalized = str_replace([' &', '&', ' *'], ['', '', '*'], $normalized);
        $normalized = preg_replace('/\s*\*\s*/', '*', $normalized) ?? $normalized;
        $normalized = preg_replace('/^(class|struct|enum)\s+/', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function signalTypeSuffixPart(string $cppType, string $phpType): string
    {
        if (isset(self::ZEND_TYPE_MAP[$phpType])) {
            return ucfirst($phpType);
        }

        $normalized = $this->normalizedSignalType($cppType);
        $normalized = str_replace(['::', '*'], ['', 'Ptr'], $normalized);
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $normalized) ?? $normalized;

        return $normalized !== '' ? ucfirst($normalized) : 'Value';
    }

    /**
     * @param list<OverloadParamContext> $params
     * @return list<OverloadParamContext>
     */
    public function signalCallbackParams(array $params): array
    {
        if ($params === []) {
            return $params;
        }

        $lastIndex = count($params) - 1;
        if ($this->normalizedSignalType($params[$lastIndex]->cppType) === 'QPrivateSignal') {
            array_pop($params);
        }

        return $params;
    }
}
