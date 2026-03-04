<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QString::class, 'QtCore smoke classes are unavailable in this build.');

$string = new \Qt\Core\QString();

qt_runtime_result([
    'empty' => $string->isEmpty(),
    'size' => $string->size(),
    'length' => $string->length(),
    'std' => $string->toStdString(),
    'upper' => $string->toUpper(),
    'lower' => $string->toLower(),
    'trimmed' => $string->trimmed(),
]);
