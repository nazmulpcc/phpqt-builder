<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class('Qt\\Widgets\\QHeaderView', 'QHeaderView is unavailable in this build.');

$reflection = new ReflectionClass('Qt\\Widgets\\QHeaderView');
$constants = $reflection->getConstants();

qt_runtime_result([
    'has_stretch' => array_key_exists('Stretch', $constants),
    'has_interactive' => array_key_exists('Interactive', $constants),
    'has_fixed' => array_key_exists('Fixed', $constants),
    'has_resize_to_contents' => array_key_exists('ResizeToContents', $constants),
    'stretch_value' => $constants['Stretch'] ?? null,
]);
