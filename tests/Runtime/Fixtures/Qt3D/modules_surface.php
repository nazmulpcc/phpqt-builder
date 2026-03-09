<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Core\QCoreApplication;
use Qt\Core\QObject;
use Qt\Core\QRectF;
use Qt\Gui\QColor;
use Qt\Gui\QGuiApplication;
use Qt\Gui\QSurface;
use Qt\Gui\QVector3D;
use Qt\Gui\QWindow;
use Qt\Qt3DCore\QAspectEngine;
use Qt\Qt3DCore\QEntity;
use Qt\Qt3DExtras\QOrbitCameraController;
use Qt\Qt3DInput\QAction;
use Qt\Qt3DInput\QAxis;
use Qt\Qt3DInput\QInputAspect;
use Qt\Qt3DInput\QInputSettings;
use Qt\Qt3DInput\QLogicalDevice;
use Qt\Qt3DRender\QCamera;
use Qt\Qt3DRender\QCameraLens;
use Qt\Qt3DRender\QCameraSelector;
use Qt\Qt3DRender\QClearBuffers;
use Qt\Qt3DRender\QRenderSettings;
use Qt\Qt3DRender\QRenderSurfaceSelector;
use Qt\Qt3DRender\QViewport;

qt_runtime_require_class(QAspectEngine::class, 'Qt3DCore is unavailable in this build.');
qt_runtime_require_class(QRenderSettings::class, 'Qt3DRender is unavailable in this build.');
qt_runtime_require_class(QOrbitCameraController::class, 'Qt3DExtras is unavailable in this build.');
qt_runtime_require_class(QInputAspect::class, 'Qt3DInput is unavailable in this build.');

$app = new QGuiApplication();

$window = new QWindow();
$window->setSurfaceType(QSurface::OpenGLSurface);
$window->resize(640, 360);

$engine = new QAspectEngine();
$engine->setRunMode(QAspectEngine::Automatic);
$engine->registerAspect('render');

$inputAspect = new QInputAspect($engine);
$engine->registerAspect($inputAspect);

$root = new QEntity();

$camera = new QCamera($root);
$camera->setProjectionType(QCameraLens::PerspectiveProjection);
$camera->setFieldOfView(45.0);
$camera->setAspectRatio(16.0 / 9.0);
$camera->setNearPlane(0.1);
$camera->setFarPlane(1000.0);
$camera->setPosition(new QVector3D(0.0, 0.0, 12.0));
$camera->setViewCenter(new QVector3D(0.0, 0.0, 0.0));

$renderSettings = new QRenderSettings($root);
$surfaceSelector = new QRenderSurfaceSelector();
$viewport = new QViewport($surfaceSelector);
$clearBuffers = new QClearBuffers($viewport);
$cameraSelector = new QCameraSelector($clearBuffers);

$surfaceSelector->setSurface($window);
$viewport->setNormalizedRect(new QRectF(0.0, 0.0, 1.0, 1.0));
$clearBuffers->setBuffers(QClearBuffers::ColorDepthBuffer);
$clearBuffers->setClearColor(QColor::fromRgbF(0.1, 0.12, 0.18, 1.0));
$cameraSelector->setCamera($camera);
$renderSettings->setActiveFrameGraph($surfaceSelector);
$root->addComponent($renderSettings);

$inputSettings = new QInputSettings($root);
$inputSettings->setEventSource($window);

$orbitController = new QOrbitCameraController($root);
$orbitController->setCamera($camera);
$orbitController->setLinearSpeed(20.0);
$orbitController->setLookSpeed(180.0);
$orbitController->setZoomInLimit(2.5);

$action = new QAction($root);
$axis = new QAxis($root);
$logicalDevice = new QLogicalDevice($root);
$logicalDevice->addAction($action);
$logicalDevice->addAxis($axis);

$engine->setRootEntity($root);

for ($i = 0; $i < 3; $i++) {
    QCoreApplication::processEvents();
}

$clearColor = $clearBuffers->clearColor();

qt_runtime_result([
    'run_mode' => $engine->runMode(),
    'aspect_count' => count($engine->aspects()),
    'root_is_entity' => $engine->rootEntity() instanceof QEntity,
    'camera_fov' => $camera->fieldOfView(),
    'camera_aspect' => $camera->aspectRatio(),
    'clear_buffers' => $clearBuffers->buffers(),
    'clear_color_blue' => $clearColor->blueF(),
    'input_event_source_is_object' => $inputSettings->eventSource() instanceof QObject,
    'input_event_source_class' => get_class($inputSettings->eventSource()),
    'orbit_linear_speed' => $orbitController->linearSpeed(),
    'orbit_look_speed' => $orbitController->lookSpeed(),
    'orbit_zoom_limit' => $orbitController->zoomInLimit(),
    'logical_actions_count' => count($logicalDevice->actions()),
    'logical_axes_count' => count($logicalDevice->axes()),
]);
