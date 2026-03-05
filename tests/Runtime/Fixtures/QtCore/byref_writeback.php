<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QBitArray::class, 'QBitArray is unavailable in this build.');

if (!method_exists(\Qt\Core\QBitArray::class, 'fromBits') || !method_exists(\Qt\Core\QBitArray::class, 'toUInt32')) {
    qt_runtime_skip('QBitArray by-ref methods are unavailable in this build.');
}

$bits = \Qt\Core\QBitArray::fromBits("\x2a", 8);
$ok = null;
$value = $bits->toUInt32(0, $ok);

qt_runtime_result([
    'value' => $value,
    'ok_type' => get_debug_type($ok),
    'ok_value' => $ok,
    'ok_is_bool' => is_bool($ok),
]);
