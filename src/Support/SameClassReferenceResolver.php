<?php

declare(strict_types=1);

namespace QtBuilder\Support;

final class SameClassReferenceResolver
{
    public static function isSameClassReference(
        string $returnType,
        string $className,
        bool $isStatic = false,
        ?string $declaringClass = null,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
    ): bool {
        if ($isStatic) {
            return false;
        }

        $trimmed = trim($returnType);
        if (!str_contains($trimmed, '&') || str_contains($trimmed, '*')) {
            return false;
        }

        $normalized = self::normalizeType($trimmed);
        if ($normalized === '') {
            return false;
        }

        $resolvedPhpClass = $classTypeResolver?->resolvePhpClassIdentity($normalized, $resolutionContext);
        if ($resolvedPhpClass !== null && $resolvedPhpClass === $className) {
            return true;
        }

        if ($declaringClass !== null && $declaringClass !== '') {
            $resolvedDeclaringClass = $classTypeResolver?->resolvePhpClassIdentity($declaringClass, $resolutionContext) ?? $declaringClass;
            if ($normalized === $declaringClass || $normalized === $resolvedDeclaringClass) {
                return true;
            }
        }

        if (str_contains($normalized, '::')) {
            $normalized = (string) substr($normalized, (int) strrpos($normalized, '::') + 2);
        }

        $shortClassName = str_contains($className, '::')
            ? (string) substr($className, (int) strrpos($className, '::') + 2)
            : $className;

        return $normalized === $className || $normalized === $shortClassName;
    }

    public static function normalizeType(string $cppType): string
    {
        $type = trim($cppType);
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
}
