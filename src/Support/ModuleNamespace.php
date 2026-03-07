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
}
