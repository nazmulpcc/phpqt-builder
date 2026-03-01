<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

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
     * Returns true if the PHP type string is a union type (contains |).
     */
    public function isUnionType(string $phpType): bool
    {
        return str_contains($phpType, '|');
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
            return sprintf('Z_PARAM_LONG(% s)', $varName);
        }

        // For objects with optional, accept null too
        if ($ceVar !== null) {
            return sprintf('Z_PARAM_OBJECT_OF_CLASS_OR_NULL(%s, %s)', $varName, $ceVar);
        }

        return $this->zppMacro($phpType, $varName, $ceVar);
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

        // Object type — determine if value or pointer
        if ($this->isValueType($phpType)) {
            return 'value_object';
        }

        return 'qobject_pointer';
    }

    // ------------------------------------------------------------------
    // C++ <-> PHP conversion expressions
    // ------------------------------------------------------------------

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
    public function phpToNativeExpr(string $phpType, string $cppType, string $varName, bool $varIsZval = false): string
    {
        // If the variable is a zval* but the overload expects a scalar,
        // we need to extract the value from the zval first.
        if ($varIsZval) {
            return $this->zvalToNativeExpr($phpType, $cppType, $varName);
        }

        return match ($phpType) {
            'int' => sprintf('(%s)%s', $this->cppCastType($cppType), $varName),
            'float' => sprintf('(%s)%s', $this->cppCastType($cppType), $varName),
            'bool' => $varName,
            'string' => $this->phpStringToNativeExpr($cppType, $varName),
            default => $this->isObjectType($phpType)
                ? $this->phpObjectToNativeExpr($phpType, $cppType, $varName)
                : $varName,
        };
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
    public function zvalToNativeExpr(string $phpType, string $cppType, string $varName): string
    {
        return match ($phpType) {
            'int' => sprintf('(%s)Z_LVAL_P(%s)', $this->cppCastType($cppType), $varName),
            'float' => sprintf('(%s)Z_DVAL_P(%s)', $this->cppCastType($cppType), $varName),
            'bool' => sprintf('Z_TYPE_P(%s) == IS_TRUE', $varName),
            'string' => $this->phpStringToNativeExpr($cppType, sprintf('Z_STR_P(%s)', $varName)),
            default => $this->isObjectType($phpType)
                ? $this->phpObjectToNativeExpr($phpType, $cppType, $varName)
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
    public function phpObjectToNativeExpr(string $phpType, string $cppType, string $varName): string
    {
        $fromObj = $this->fromObjFuncName($phpType);
        $baseExpr = sprintf('%s(Z_OBJ_P(%s))->native_ptr', $fromObj, $varName);

        // If C++ expects a pointer, pass the pointer directly
        $normalized = $this->normalizeCppType($cppType);
        if (str_contains($cppType, '*') && !str_contains($cppType, '&')) {
            return $baseExpr;
        }

        // Otherwise (const ref, value), dereference
        return '*' . $baseExpr;
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

        if ($base === 'QByteArray') {
            return sprintf('RETURN_STRINGL(%s.constData(), %s.size())', $varName, $varName);
        }

        // Default: assume QString-like, convert via UTF-8
        return sprintf(
            "QByteArray _utf8 = %s.toUtf8();\n    RETURN_STRINGL(_utf8.constData(), _utf8.size())",
            $varName,
        );
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

        return match ($normalized) {
            'short', 'unsigned short', 'qint8', 'qint16', 'quint8', 'quint16' => $normalized,
            'float' => 'float',
            'double', 'qreal' => 'double',
            'long', 'unsigned long' => $normalized,
            'long long', 'unsigned long long', 'qint64', 'quint64', 'qlonglong', 'qulonglong' => $normalized,
            default => 'int',
        };
    }

    /**
     * Normalize a C++ type by stripping const, &, *.
     */
    private function normalizeCppType(string $type): string
    {
        $type = trim($type);

        if (str_starts_with($type, 'const ')) {
            $type = substr($type, 6);
        }

        $type = rtrim($type, '& ');

        if (str_ends_with($type, ' *') && !str_contains($type, '<')) {
            $type = rtrim(rtrim($type, '*'));
        }

        return trim($type);
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

        if ($base === 'char') {
            return sprintf('ZSTR_VAL(%s)', $varName);
        }

        // Default: QString
        return sprintf('QString::fromUtf8(ZSTR_VAL(%s), (int)ZSTR_LEN(%s))', $varName, $varName);
    }
}
