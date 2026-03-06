<?php

declare(strict_types=1);

if (!extension_loaded('qtcore') || !extension_loaded('qtgui') || !extension_loaded('qtwidgets')) {
    fwrite(STDERR, "The qtcore, qtgui, and qtwidgets extensions are not loaded.\n");
    exit(1);
}

$widgetClass = \Qt\Widgets\QWidget::class;
$qobjectClass = \Qt\Core\QObject::class;

if (!class_exists($widgetClass) || !class_exists($qobjectClass)) {
    echo 'PHPQT_RESULT=' . json_encode([
        'reason' => 'Split QtCore/QtWidgets classes are unavailable in this build.',
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(77);
}

$app = new \Qt\Widgets\QApplication();
$widget = new \Qt\Widgets\QWidget();

echo 'PHPQT_RESULT=' . json_encode([
    'application_class' => get_class($app),
    'widget_class' => get_class($widget),
    'inherits_qobject' => $widget instanceof \Qt\Core\QObject,
], JSON_THROW_ON_ERROR) . PHP_EOL;
