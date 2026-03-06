{!! '<?php' !!}

/** @generate-class-entries */

namespace Qt;

final class BuildInfo
{
    public const MODE_MONOLITHIC = 'monolithic';
    public const MODE_MODULAR = 'modular';

    public static function buildMode(): string {}

    public static function qtVersion(): string {}

    public static function extensionVersion(): string {}

    public static function builtModules(): array {}

    public static function loadedModules(): array {}

    public static function hasModule(string $module): bool {}

    public static function isLoaded(string $module): bool {}

    public static function moduleInfo(string $module): ?array {}

    public static function manifest(): array {}
}
