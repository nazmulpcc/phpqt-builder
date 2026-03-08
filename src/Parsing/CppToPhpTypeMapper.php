<?php

declare(strict_types=1);

namespace QtBuilder\Parsing;

use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\ModuleNamespace;
use QtBuilder\Support\OpenGLNumericPointerArrayRegistry;
use QtBuilder\Support\TypeResolutionContext;

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
        'GLboolean' => 'bool',
        'int' => 'int',
        'GLenum' => 'int',
        'GLuint' => 'int',
        'GLuint64' => 'int',
        'GLint' => 'int',
        'GLintptr' => 'int',
        'GLsizei' => 'int',
        'GLsizeiptr' => 'int',
        'GLbitfield' => 'int',
        'GLshort' => 'int',
        'GLushort' => 'int',
        'GLbyte' => 'int',
        'GLubyte' => 'int',
        'uint' => 'int',
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
        'GLfloat' => 'float',
        'GLdouble' => 'float',
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
        'QModelIndexList',
        'QHash',
        'QMap',
        'QSet',
    ];

    /**
     * Map a raw C++ type string to a PHP type name.
     */
    public function map(
        string $cppType,
        ?string $ownerClass = null,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): string
    {
        $trimmed = trim($cppType);

        if ($this->isCharPointerArrayType($trimmed)) {
            return 'array';
        }

        if ($this->isSupportedNumericArrayPointerType($trimmed)) {
            return 'array';
        }

        if ($this->isOpenGLRawInputBufferType($trimmed, $ownerClass) || $this->isOpenGLStringReturnType($trimmed, $ownerClass)) {
            return 'string';
        }

        if ($this->isVoidPointerType($trimmed)) {
            return 'mixed';
        }

        $normalized = $this->normalize($cppType);

        $smartPointerTarget = $this->resolveSmartPointerAliasTarget($trimmed, $smartPointerAliases);
        if ($smartPointerTarget !== null) {
            return $this->map($smartPointerTarget, $ownerClass, $classTypeResolver, $resolutionContext, []);
        }

        if ($classTypeResolver !== null) {
            $ownerPhpNamespace = $this->ownerPhpNamespace($ownerClass, $resolutionContext);
            $resolvedPhpType = $classTypeResolver->resolvePhpType($trimmed, $resolutionContext, $ownerPhpNamespace);
            if ($resolvedPhpType !== null) {
                return $resolvedPhpType;
            }
        }

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

        // Qt flags are exposed as ints in PHP.
        if (str_starts_with($normalized, 'QFlags<')) {
            return 'int';
        }

        if ($this->isChronoDurationType($normalized)) {
            return 'int';
        }

        if ($this->isQtGlobalEnumLike($normalized)) {
            return 'int';
        }

        if ($normalized === 'Qt::Disambiguated_t') {
            return 'mixed';
        }

        if (str_starts_with($normalized, 'std::')) {
            return 'mixed';
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
     * @param array<string, string> $smartPointerAliases
     */
    private function resolveSmartPointerAliasTarget(string $cppType, array $smartPointerAliases): ?string
    {
        if ($smartPointerAliases === []) {
            return null;
        }

        $trimmed = trim($cppType);
        if (preg_match('/^(?:const\s+)?(?<alias>[A-Za-z_][A-Za-z0-9_]*)\s*(?:[&*]\s*)?$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $alias = trim((string) ($matches['alias'] ?? ''));
        if ($alias === '') {
            return null;
        }

        return $smartPointerAliases[$alias] ?? null;
    }

    private function isCharPointerArrayType(string $cppType): bool
    {
        $normalized = preg_replace('/\bconst\b/', '', $cppType) ?? $cppType;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        return preg_match('/^char\s*\*\s*\*$/', $normalized) === 1;
    }

    private function isVoidPointerType(string $cppType): bool
    {
        $normalized = preg_replace('/\bconst\b/', '', $cppType) ?? $cppType;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        return preg_match('/^(?:GL)?void(\s*\*)+$/', $normalized) === 1;
    }

    private function isSupportedNumericArrayPointerType(string $cppType): bool
    {
        return OpenGLNumericPointerArrayRegistry::supports($cppType);
    }

    private function isOpenGLRawInputBufferType(string $cppType, ?string $ownerClass): bool
    {
        if (!$this->isOpenGLScopedOwner($ownerClass)) {
            return false;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $cppType) ?? $cppType);

        return preg_match('/^const (?:(?:GL)?void|GLubyte)\s*\*$/', $normalized) === 1;
    }

    private function isOpenGLStringReturnType(string $cppType, ?string $ownerClass): bool
    {
        if (!$this->isOpenGLScopedOwner($ownerClass)) {
            return false;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $cppType) ?? $cppType);

        return preg_match('/^const GLubyte\s*\*$/', $normalized) === 1;
    }

    private function isOpenGLScopedOwner(?string $ownerClass): bool
    {
        if (!is_string($ownerClass) || $ownerClass === '') {
            return false;
        }

        return str_starts_with($ownerClass, 'QOpenGL');
    }

    private function ownerPhpNamespace(?string $ownerClass, ?TypeResolutionContext $resolutionContext): ?string
    {
        if ($resolutionContext?->module !== null) {
            return ModuleNamespace::forQtModule($resolutionContext->module);
        }

        if (!is_string($ownerClass) || trim($ownerClass) === '') {
            return null;
        }

        if (!str_starts_with($ownerClass, '\\')) {
            return null;
        }

        $parts = explode('\\', ltrim($ownerClass, '\\'));
        array_pop($parts);

        return $parts !== [] ? implode('\\', $parts) : null;
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
        if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $name) !== 1) {
            return false;
        }

        if (str_ends_with($name, '_t')) {
            return false;
        }

        foreach (['Result', 'Private', 'Data', 'Pointer', 'Iterator', 'Ref', 'Helper', 'Connection', 'Provider', 'Callback'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return false;
            }
        }

        return true;
    }

    private function isChronoDurationType(string $type): bool
    {
        if (str_starts_with($type, 'std::chrono::duration<')) {
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
            if ($type === $durationType) {
                return true;
            }
        }

        return false;
    }

    private function isQtGlobalEnumLike(string $type): bool
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
}
