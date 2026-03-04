<?php

declare(strict_types=1);

if (!extension_loaded('qt')) {
    fwrite(STDERR, "The qt extension is not loaded.\n");
    exit(1);
}

/**
 * @param array<string, mixed> $payload
 */
function qt_runtime_result(array $payload): never
{
    echo 'PHPQT_RESULT=' . json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

function qt_runtime_skip(string $reason): never
{
    echo 'PHPQT_RESULT=' . json_encode(['reason' => $reason], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(77);
}

function qt_runtime_require_class(string $class, string $reason): void
{
    if (!class_exists($class)) {
        qt_runtime_skip($reason);
    }
}
