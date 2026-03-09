<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Svg\QSvgRenderer;

qt_runtime_require_class(QSvgRenderer::class, 'QtSvg classes are unavailable in this build.');

$empty = new QSvgRenderer();

$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100">'
    . '<rect x="10" y="10" width="180" height="80" fill="blue"/>'
    . '</svg>';

$file = tempnam(sys_get_temp_dir(), 'phpqt_svg_') . '.svg';
file_put_contents($file, $svg);

$renderer = new QSvgRenderer($file);
$size = $renderer->defaultSize();

unlink($file);

qt_runtime_result([
    'empty_is_valid'  => $empty->isValid(),
    'loaded_is_valid' => $renderer->isValid(),
    'default_width'   => $size->width(),
    'default_height'  => $size->height(),
]);
