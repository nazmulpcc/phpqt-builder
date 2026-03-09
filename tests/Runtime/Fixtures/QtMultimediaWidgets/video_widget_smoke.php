<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\MultimediaWidgets\QVideoWidget;
use Qt\Widgets\QApplication;
use Qt\Widgets\QWidget;

qt_runtime_require_class(QVideoWidget::class, 'QtMultimediaWidgets classes are unavailable in this build.');

$app = new QApplication();

$widget = new QVideoWidget();
$widget->resize(640, 360);

qt_runtime_result([
    'is_widget'          => $widget instanceof QWidget,
    'width'              => $widget->width(),
    'height'             => $widget->height(),
    'aspect_ratio_mode'  => $widget->aspectRatioMode(),
    'is_fullscreen'      => $widget->isFullScreen(),
]);
