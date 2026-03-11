<?php

declare(strict_types=1);

namespace QtBuilder\Support;

final class ModuleNamespace
{
    public static function forQtModule(string $module): string
    {
        $suffix = preg_replace('/^Qt/', '', $module) ?? $module;
        if ($suffix === '' || preg_match('/^[0-9]/', $suffix) === 1) {
            return 'Qt\\' . $module;
        }

        return 'Qt\\' . $suffix;
    }

    public static function forQualifiedCppClass(string $module, ?string $qualifiedName): string
    {
        $moduleNamespace = self::forQtModule($module);
        $qualifiedName = is_string($qualifiedName) ? trim($qualifiedName) : '';
        if ($qualifiedName === '' || !str_contains($qualifiedName, '::')) {
            return $moduleNamespace;
        }

        $parts = array_values(array_filter(
            explode('::', ltrim($qualifiedName, ':')),
            static fn(string $part): bool => $part !== '',
        ));
        if (count($parts) < 2) {
            return $moduleNamespace;
        }

        array_pop($parts);
        if ($parts === []) {
            return $moduleNamespace;
        }

        if ($parts[0] === $module) {
            array_shift($parts);
        }

        if ($parts === []) {
            return $moduleNamespace;
        }

        return $moduleNamespace . '\\' . implode('\\', $parts);
    }
}
