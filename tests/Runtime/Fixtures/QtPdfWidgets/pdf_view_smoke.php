<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Pdf\QPdfDocument;
use Qt\PdfWidgets\QPdfView;
use Qt\Widgets\QApplication;
use Qt\Widgets\QWidget;

qt_runtime_require_class(QPdfView::class, 'QtPdfWidgets classes are unavailable in this build.');

$app = new QApplication();

$doc  = new QPdfDocument();
$view = new QPdfView();
$view->setDocument($doc);
$view->resize(800, 600);

qt_runtime_result([
    'is_widget'   => $view instanceof QWidget,
    'width'       => $view->width(),
    'height'      => $view->height(),
    'page_mode'   => $view->pageMode(),
]);
