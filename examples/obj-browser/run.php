<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';
require __DIR__ . '/ObjMaterialLibrary.php';
require __DIR__ . '/ObjLoader.php';
require __DIR__ . '/ObjBrowserWidget.php';
require __DIR__ . '/ObjBrowserUi.php';

use Qt\Core\QCoreApplication;
use Qt\Core\QTimer;
use Qt\Gui\QSurfaceFormat;
use Qt\Widgets\QApplication;
use Qt\Widgets\QFileDialog;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

example_section('OBJ Browser');

QCoreApplication::setApplicationName('OBJ Browser');

$app = new QApplication();
$window = new QWidget();
$window->setWindowTitle('OBJ Browser');
$window->setMinimumSize(1280, 820);
$window->setStyleSheet(obj_browser_stylesheet());

$shell = new QWidget($window);
$shell->setObjectName('shell');
$shellLayout = new QHBoxLayout($shell);
$shellLayout->setContentsMargins(26, 26, 26, 26);
$shellLayout->setSpacing(22);

$viewer = new ObjBrowserWidget();
$format = new QSurfaceFormat();
$format->setRenderableType(QSurfaceFormat::OpenGL);
$format->setVersion(2, 0);
$format->setProfile(QSurfaceFormat::CompatibilityProfile);
$format->setSamples(4);
$format->setSwapInterval(1);
$viewer->setFormat($format);
$viewer->setMinimumSize(820, 680);

$sidePanel = new QWidget();
$sidePanel->setObjectName('sidePanel');
$sidePanel->setFixedWidth(340);
$sideLayout = new QVBoxLayout($sidePanel);
$sideLayout->setContentsMargins(22, 22, 22, 22);
$sideLayout->setSpacing(14);

$eyebrow = new QLabel('PHP + QTOPENGLWIDGETS');
$eyebrow->setObjectName('eyebrow');
$title = new QLabel('OBJ Browser');
$title->setObjectName('title');
$subtitle = new QLabel('Load and inspect textured Wavefront OBJ models from PHP with a native OpenGL widget renderer.');
$subtitle->setObjectName('subtitle');
$subtitle->setWordWrap(true);

$section = new QLabel('MODEL INFO');
$section->setProperty('role', 'sectionTitle');
$fileKey = new QLabel('FILE');
$fileKey->setProperty('role', 'infoKey');
$fileValue = obj_browser_info_value('Bundled sample.obj');
$geometryKey = new QLabel('GEOMETRY');
$geometryKey->setProperty('role', 'infoKey');
$geometryValue = obj_browser_info_value('0 vertices · 0 triangles');
$materialKey = new QLabel('MATERIALS');
$materialKey->setProperty('role', 'infoKey');
$materialValue = obj_browser_info_value('0 materials');

$openButton = new QPushButton('Open...');
$resetButton = new QPushButton('Reset View');
$resetButton->setProperty('variant', 'secondary');

$buttonRow = new QWidget();
$buttonLayout = new QHBoxLayout($buttonRow);
$buttonLayout->setContentsMargins(0, 0, 0, 0);
$buttonLayout->setSpacing(10);
$buttonLayout->addWidget($openButton);
$buttonLayout->addWidget($resetButton);

$status = new QLabel('Viewer booting...');
$status->setObjectName('status');
$status->setWordWrap(true);
$warnings = new QLabel('Warnings: none');
$warnings->setObjectName('status');
$warnings->setWordWrap(true);

$hint = new QLabel('Drag to orbit the camera, use the mouse wheel to zoom, and load your own OBJ + MTL + diffuse texture set with the Open button.');
$hint->setObjectName('subtitle');
$hint->setWordWrap(true);

$sideLayout->addWidget($eyebrow);
$sideLayout->addWidget($title);
$sideLayout->addWidget($subtitle);
$sideLayout->addSpacing(8);
$sideLayout->addWidget($section);
$sideLayout->addWidget($fileKey);
$sideLayout->addWidget($fileValue);
$sideLayout->addWidget($geometryKey);
$sideLayout->addWidget($geometryValue);
$sideLayout->addWidget($materialKey);
$sideLayout->addWidget($materialValue);
$sideLayout->addSpacing(4);
$sideLayout->addWidget($buttonRow);
$sideLayout->addSpacing(8);
$sideLayout->addWidget($status);
$sideLayout->addWidget($warnings);
$sideLayout->addWidget($hint);
$sideLayout->addStretch(1);

$updateSummary = static function () use ($viewer, $fileValue, $geometryValue, $materialValue, $warnings): void {
    $summary = $viewer->sceneSummary();
    $fileValue->setText($summary['label'] . PHP_EOL . $summary['path']);
    $geometryValue->setText(sprintf('%d vertices · %d triangles', $summary['vertex_count'], $summary['triangle_count']));
    $materialValue->setText(sprintf('%d materials', $summary['material_count']));

    $warningList = $summary['warnings'];
    $warnings->setText($warningList === []
        ? 'Warnings: none'
        : 'Warnings:' . PHP_EOL . implode(PHP_EOL, array_map(static fn (string $warning): string => '• ' . $warning, array_slice($warningList, 0, 4))));
};

$viewer->setStatusSink(static function (string $message, bool $ready) use ($status): void {
    $status->setText($message);
    $status->setStyleSheet($ready
        ? ''
        : 'QLabel#status { background: rgba(56, 14, 14, 230); border: 1px solid #c85d5d; color: #ffe8e8; padding: 12px 14px; border-radius: 16px; }');
});

$openButton->onClicked(static function () use ($window, $viewer, $updateSummary): void {
    $selected = QFileDialog::getOpenFileName($window, 'Open OBJ Model', dirname(__DIR__, 2), 'Wavefront OBJ (*.obj)');
    if ($selected === '') {
        return;
    }

    if ($viewer->loadModel($selected)) {
        $updateSummary();
    }
});

$resetButton->onClicked(static function () use ($viewer): void {
    $viewer->resetView();
});

$samplePath = __DIR__ . '/sample.obj';
$viewer->loadModel($samplePath);
$updateSummary();

$app->onAboutToQuit(static function () use ($viewer): void {
    $viewer->shutdown();
});

$shellLayout->addWidget($viewer, 1);
$shellLayout->addWidget($sidePanel);

$windowLayout = new QVBoxLayout($window);
$windowLayout->setContentsMargins(0, 0, 0, 0);
$windowLayout->addWidget($shell);

$autoQuitSeconds = example_auto_quit_seconds();
if ($autoQuitSeconds > 0) {
    $quitTimer = new QTimer($window);
    $quitTimer->setSingleShot(true);
    $quitTimer->onTimeout(static function (): void {
        QCoreApplication::quit();
    });
    $quitTimer->start($autoQuitSeconds * 1000);
}

$window->show();

example_line('obj browser ready');
QApplication::exec();
