<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Charts\QChart;
use Qt\Charts\QLineSeries;
use Qt\Charts\QValueAxis;
use Qt\Widgets\QApplication;

qt_runtime_require_class(QChart::class, 'QtCharts classes are unavailable in this build.');

$app = new QApplication();

$chart = new QChart();
$chart->setTitle('Sales Overview');

$series = new QLineSeries();
$series->setName('Revenue');
$series->append(0.0, 120.0);
$series->append(1.0, 175.0);
$series->append(2.0, 210.0);
$series->append(3.0, 195.0);

$chart->addSeries($series);

$axisX = new QValueAxis();
$axisX->setMinFloat(0.0);
$axisX->setMaxFloat(3.0);
$axisX->setTickCount(4);

$axisY = new QValueAxis();
$axisY->setMinFloat(0.0);
$axisY->setMaxFloat(300.0);

$chart->addAxis($axisX, 0x40); // Qt::AlignBottom
$chart->addAxis($axisY, 0x1);  // Qt::AlignLeft

// Assert on the concrete series object directly — QChart::series() returns
// QAbstractSeries which is abstract and cannot be instantiated by the extension.
qt_runtime_result([
    'title'       => $chart->title(),
    'series_name' => $series->name(),
    'point_count' => $series->count(),
    'axis_x_min'  => $axisX->min(),
    'axis_x_max'  => $axisX->max(),
    'axis_y_max'  => $axisY->max(),
    'tick_count'  => $axisX->tickCount(),
]);
