<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final class BuildTarget
{
    public const string DESKTOP = 'desktop';
    public const string IOS = 'ios';
    public const string ANDROID = 'android';

    public static function normalize(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '', self::DESKTOP => self::DESKTOP,
            self::IOS => self::IOS,
            self::ANDROID => self::ANDROID,
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported build target "%s". Expected one of: %s, %s, %s.',
                $value,
                self::DESKTOP,
                self::IOS,
                self::ANDROID,
            )),
        };
    }
}
