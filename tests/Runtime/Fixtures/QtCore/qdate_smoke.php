<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QDate::class, 'QtCore smoke classes are unavailable in this build.');

$date = new \Qt\Core\QDate();

qt_runtime_result([
    'was_null' => $date->isNull(),
    'set_ok' => $date->setDate(2025, 3, 2),
    'year' => $date->year(),
    'month' => $date->month(),
    'day' => $date->day(),
    'valid' => $date->isValid(),
]);
