<?php

declare(strict_types=1);

namespace QtBuilder\Support;

final class CppName
{
    public static function unqualify(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^(?:::)?(?:(?:[A-Za-z_][A-Za-z0-9_]*)::)+([A-Za-z_][A-Za-z0-9_]*)$/', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $trimmed;
    }
}
