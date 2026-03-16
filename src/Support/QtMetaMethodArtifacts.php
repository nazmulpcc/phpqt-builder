<?php

declare(strict_types=1);

namespace QtBuilder\Support;

final class QtMetaMethodArtifacts
{
    /**
     * Qt meta-object macros inject these declarations for moc/type-trait plumbing.
     * They are not callable public API and should be filtered before facts are cached.
     * If this list grows materially, move it into a dedicated Qt meta-artifact classifier.
     *
     * @var array<string, true>
     */
    private const array METHOD_NAMES = [
        'qt_check_for_QGADGET_macro' => true,
        'qt_static_metacall' => true,
        'qt_metacall' => true,
        'qt_metacast' => true,
    ];

    public static function isMethodArtifact(string $methodName): bool
    {
        return isset(self::METHOD_NAMES[$methodName]);
    }
}
