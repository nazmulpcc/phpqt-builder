<?php

declare(strict_types=1);

namespace QtBuilder\Support;

final class OpenGLNumericPointerArrayRegistry
{
    /**
     * @var array<string, array{php_type: 'int'|'float', native_type: string}>
     */
    private const array POINTER_TYPES = [
        'const GLfloat *' => ['php_type' => 'float', 'native_type' => 'GLfloat'],
        'const GLdouble *' => ['php_type' => 'float', 'native_type' => 'GLdouble'],
        'const GLint *' => ['php_type' => 'int', 'native_type' => 'GLint'],
        'const GLshort *' => ['php_type' => 'int', 'native_type' => 'GLshort'],
        'const GLushort *' => ['php_type' => 'int', 'native_type' => 'GLushort'],
        'const GLuint *' => ['php_type' => 'int', 'native_type' => 'GLuint'],
    ];

    /**
     * @return array{php_type: 'int'|'float', native_type: string}|null
     */
    public static function resolve(string $cppType): ?array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $cppType) ?? $cppType);

        return self::POINTER_TYPES[$normalized] ?? null;
    }

    public static function supports(string $cppType): bool
    {
        return self::resolve($cppType) !== null;
    }
}
