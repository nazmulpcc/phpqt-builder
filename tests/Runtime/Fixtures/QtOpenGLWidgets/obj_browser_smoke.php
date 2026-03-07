<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 4) . '/examples/obj-browser/ObjMaterialLibrary.php';
require dirname(__DIR__, 4) . '/examples/obj-browser/ObjLoader.php';
require dirname(__DIR__, 4) . '/examples/obj-browser/ObjBrowserWidget.php';

use Qt\Core\QCoreApplication;
use Qt\Core\QTimer;
use Qt\Gui\QSurfaceFormat;
use Qt\Widgets\QApplication;

qt_runtime_require_class('Qt\\OpenGLWidgets\\QOpenGLWidget', 'QtOpenGLWidgets is unavailable in this build.');
qt_runtime_require_class('Qt\\OpenGL\\QOpenGLShaderProgram', 'QtOpenGL shader support is unavailable in this build.');

$app = new QApplication();
$widget = new ObjBrowserWidget();

$format = new QSurfaceFormat();
$format->setRenderableType(QSurfaceFormat::OpenGL);
$format->setVersion(2, 0);
$format->setProfile(QSurfaceFormat::CompatibilityProfile);
$widget->setFormat($format);

$samplePath = dirname(__DIR__, 4) . '/examples/obj-browser/sample.obj';
$loaded = $widget->loadModel($samplePath);
$app->onAboutToQuit(static function () use ($widget): void {
    $widget->shutdown();
});
$widget->resize(360, 280);
$widget->show();

$frameBudget = new QTimer($widget);
$frameBudget->setInterval(40);
$frameBudget->onTimeout(static function () use ($widget): void {
    if ($widget->isSceneReady()) {
        QCoreApplication::quit();
    }
});
$frameBudget->start();

$escapeTimer = new QTimer($widget);
$escapeTimer->setSingleShot(true);
$escapeTimer->onTimeout(static function (): void {
    QCoreApplication::quit();
});
$escapeTimer->start(1500);

QApplication::exec();

if (!$widget->isValid()) {
    qt_runtime_skip('QOpenGLWidget is not supported on the active platform plugin.');
}

qt_runtime_result([
    'loaded' => $loaded,
    'scene_ready' => $widget->isSceneReady(),
    'triangle_count' => $widget->sceneSummary()['triangle_count'],
    'material_count' => $widget->sceneSummary()['material_count'],
    'status' => $widget->statusMessage(),
]);
