<?php

declare(strict_types=1);

namespace QtBuilder\Parsing;

/**
 * Maps C++ type strings (as reported by libclang) to PHP type names.
 *
 * Strips qualifiers (const, &, *), recognises Qt value types that map
 * to PHP scalars (QString -> string, etc.), and falls back to the
 * bare class name for Qt object types.
 */
class CppToPhpTypeMapper
{
    /**
     * C++ types that map directly to PHP scalar types.
     *
     * @var array<string, string>
     */
    private const array SCALAR_MAP = [
        'bool' => 'bool',
        'int' => 'int',
        'unsigned int' => 'int',
        'short' => 'int',
        'unsigned short' => 'int',
        'long' => 'int',
        'unsigned long' => 'int',
        'long long' => 'int',
        'unsigned long long' => 'int',
        'qint8' => 'int',
        'qint16' => 'int',
        'qint32' => 'int',
        'qint64' => 'int',
        'quint8' => 'int',
        'quint16' => 'int',
        'quint32' => 'int',
        'quint64' => 'int',
        'qintptr' => 'int',
        'quintptr' => 'int',
        'qsizetype' => 'int',
        'qptrdiff' => 'int',
        'qlonglong' => 'int',
        'qulonglong' => 'int',
        'WId' => 'int',
        'float' => 'float',
        'double' => 'float',
        'qreal' => 'float',
        'void' => 'void',
    ];

    /**
     * C++ types that map to PHP's string type.
     *
     * @var list<string>
     */
    private const array STRING_TYPES = [
        'std::string',
        'std::string_view',
        'QString',
        'QByteArray',
        'QLatin1String',
        'QLatin1StringView',
        'QStringView',
        'QAnyStringView',
        'QUtf8StringView',
        'char',
    ];

    /**
     * C++ container types that map to PHP's array type.
     *
     * @var list<string>
     */
    private const array ARRAY_TYPES = [
        'QList',
        'QVector',
        'QStringList',
        'QVariantList',
        'QVariantMap',
        'QHash',
        'QMap',
        'QSet',
    ];

    /**
     * Map a raw C++ type string to a PHP type name.
     */
    public function map(string $cppType): string
    {
        $normalized = $this->normalize($cppType);

        // Direct scalar match
        if (isset(self::SCALAR_MAP[$normalized])) {
            return self::SCALAR_MAP[$normalized];
        }

        // String types
        foreach (self::STRING_TYPES as $strType) {
            if ($normalized === $strType) {
                return 'string';
            }
        }

        // Array/container types (match prefix for template specialisations like QList<QAction *>)
        foreach (self::ARRAY_TYPES as $arrType) {
            if ($normalized === $arrType || str_starts_with($normalized, $arrType . '<')) {
                return 'array';
            }
        }

        // void * and similar opaque pointers
        if ($normalized === 'void *' || $normalized === 'void **') {
            return 'mixed';
        }

        // initializer_list -> array
        if (str_starts_with($normalized, 'std::initializer_list')) {
            return 'array';
        }

        // Qualified nested types may be enums or nested classes.
        // Enum lowering is handled earlier when enough header context exists.
        if (str_contains($normalized, '::') && !str_ends_with($normalized, '*')) {
            $tail = substr($normalized, (int) strrpos($normalized, '::') + 2);

            if ($tail !== false && $tail !== '' && $this->looksLikeQualifiedEnumName($tail)) {
                return 'int';
            }

            return $tail !== false && $tail !== '' ? $tail : 'mixed';
        }

        // If it still looks like a known Qt/class type, return the bare name.
        // This covers QWidget, QObject, QEvent, QRect, QSize, QPoint, etc.
        if ($normalized !== '' && ctype_upper($normalized[0])) {
            return $normalized;
        }

        return 'mixed';
    }

    /**
     * Strip const, &, *, and whitespace to get the base type name.
     *
     * "const QWidget *"  -> "QWidget"
     * "const QString &"  -> "QString"
     * "int"              -> "int"
     * "void **"          -> "void **"  (special-cased above)
     * "QList<QAction *>" -> "QList<QAction *>" (kept for container match)
     */
    private function normalize(string $type): string
    {
        $type = trim($type);

        // Preserve void pointer variants for the special case above.
        if ($type === 'void *' || $type === 'void **') {
            return $type;
        }

        // Strip all standalone const qualifiers.
        $type = preg_replace('/\bconst\b/', '', $type) ?? $type;
        $type = trim(preg_replace('/\s+/', ' ', $type) ?? $type);

        // Strip trailing reference
        if (str_ends_with($type, ' &') || str_ends_with($type, '&')) {
            $type = rtrim(rtrim($type, '&'));
        }

        // Strip trailing pointer(s) but keep template args intact
        // "QWidget *" / "QWidget*" -> "QWidget", but "QList<QAction *>" stays
        if (!str_contains($type, '<')) {
            while (str_ends_with($type, '*')) {
                $type = rtrim(substr($type, 0, -1));
            }
        }

        return trim($type);
    }

    private function looksLikeQualifiedEnumName(string $name): bool
    {
        foreach (['Result', 'Private', 'Data', 'Pointer', 'Iterator', 'Ref', 'Helper'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
