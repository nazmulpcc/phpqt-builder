<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\SvgWidgets\QSvgWidget;
use Qt\Widgets\QApplication;
use Qt\Widgets\QWidget;

qt_runtime_require_class(QSvgWidget::class, 'QtSvgWidgets classes are unavailable in this build.');

$app = new QApplication();

$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="150">'
    . '<circle cx="150" cy="75" r="60" fill="green"/>'
    . '</svg>';

$file = tempnam(sys_get_temp_dir(), 'phpqt_svg_') . '.svg';
file_put_contents($file, $svg);

$widget = new QSvgWidget($file);
$widget->resize(300, 150);
$renderer = $widget->renderer();

unlink($file);

qt_runtime_result([
    'is_widget'      => $widget instanceof QWidget,
    'renderer_valid' => $renderer->isValid(),
    'width'          => $widget->width(),
    'height'         => $widget->height(),
]);
