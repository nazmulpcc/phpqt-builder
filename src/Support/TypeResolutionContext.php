<?php

declare(strict_types=1);

namespace QtBuilder\Support;

final readonly class TypeResolutionContext
{
    public function __construct(
        public string $className,
        public ?string $qualifiedClassName = null,
        public ?string $module = null,
        public ?string $namespace = null,
    ) {}

    /**
     * @param array<string, mixed> $classData
     */
    public static function fromClassData(array $classData): self
    {
        $className = is_string($classData['name'] ?? null) ? trim((string) $classData['name']) : '';
        $qualifiedClassName = is_string($classData['qualified_name'] ?? null)
            ? trim((string) $classData['qualified_name'])
            : null;

        return self::fromNames($className, $qualifiedClassName);
    }

    public static function fromNames(string $className, ?string $qualifiedClassName = null): self
    {
        $qualifiedClassName = is_string($qualifiedClassName) && trim($qualifiedClassName) !== ''
            ? trim($qualifiedClassName)
            : null;

        $namespace = self::namespaceForQualifiedName($qualifiedClassName);
        $module = self::moduleForQualifiedName($qualifiedClassName);

        return new self(
            className: trim($className),
            qualifiedClassName: $qualifiedClassName,
            module: $module,
            namespace: $namespace,
        );
    }

    public static function namespaceForQualifiedName(?string $qualifiedName): ?string
    {
        if (!is_string($qualifiedName) || $qualifiedName === '' || !str_contains($qualifiedName, '::')) {
            return null;
        }

        $separator = (int) strrpos($qualifiedName, '::');
        if ($separator <= 0) {
            return null;
        }

        return substr($qualifiedName, 0, $separator) ?: null;
    }

    public static function moduleForQualifiedName(?string $qualifiedName): ?string
    {
        if (!is_string($qualifiedName) || $qualifiedName === '') {
            return null;
        }

        $trimmed = ltrim($qualifiedName, ':');
        $separator = strpos($trimmed, '::');
        if ($separator === false) {
            return null;
        }

        $module = substr($trimmed, 0, $separator);

        return $module !== '' ? $module : null;
    }
}
