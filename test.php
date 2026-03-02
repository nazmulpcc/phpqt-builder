<?php

use Qt\Widgets\QApplication;

$app = new QApplication;

$widget = new \Qt\Widgets\QMainWindow();
$widget->setWindowTitle("Hello World");
$widget->resize(new \Qt\Core\QSize(400, 300));
$widget->show();

$app->exec();