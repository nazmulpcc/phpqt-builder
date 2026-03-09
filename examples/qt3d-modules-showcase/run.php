<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Qt\Core\QCoreApplication;
use Qt\Core\QRectF;
use Qt\Core\QTimer;
use Qt\Gui\QColor;
use Qt\Gui\QGuiApplication;
use Qt\Gui\QSurface;
use Qt\Gui\QVector3D;
use Qt\Gui\QWindow;
use Qt\Qt3DCore\QAspectEngine;
use Qt\Qt3DCore\QEntity;
use Qt\Qt3DExtras\QOrbitCameraController;
use Qt\Qt3DExtras\QCuboidMesh;
use Qt\Qt3DExtras\QPhongMaterial;
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
use Qt\Qt3DCore\QTransform;

if (!class_exists(QAspectEngine::class)) {
    example_fail('Qt3DCore classes are unavailable in this build. Rebuild with Qt3DCore support.');
}
if (!class_exists(QRenderSettings::class)) {
    example_fail('Qt3DRender classes are unavailable in this build. Rebuild with Qt3DRender support.');
}
if (!class_exists(QOrbitCameraController::class)) {
    example_fail('Qt3DExtras classes are unavailable in this build. Rebuild with Qt3DExtras support.');
}
if (!class_exists(QInputAspect::class)) {
    example_fail('Qt3DInput classes are unavailable in this build. Rebuild with Qt3DInput support.');
}

final class Qt3DModulesShowcaseWindow extends QWindow
{
    private ?QAspectEngine $engine = null;
    private ?QEntity $root = null;
    private ?QTransform $cubeTransform = null;
    private ?QTimer $spinTimer = null;
    private float $spinAngle = 0.0;

    public function __construct()
    {
        parent::__construct();

        $this->setTitle('Qt3D Modules Showcase');
        $this->setSurfaceType(QSurface::OpenGLSurface);
        $this->resize(960, 600);

        $this->buildScene();
    }

    private function buildScene(): void
    {
        $this->engine = new QAspectEngine();
        $this->engine->setRunMode(QAspectEngine::Automatic);
        $this->engine->registerAspect('render');
        $this->engine->registerAspect('logic');

        $inputAspect = new QInputAspect($this->engine);
        $this->engine->registerAspect($inputAspect);

        $this->root = new QEntity();

        $camera = new QCamera($this->root);
        $camera->setProjectionType(QCameraLens::PerspectiveProjection);
        $camera->setFieldOfView(45.0);
        $camera->setAspectRatio(max(1.0, $this->width()) / max(1.0, $this->height()));
        $camera->setNearPlane(0.1);
        $camera->setFarPlane(1000.0);
        $camera->setPosition(new QVector3D(0.0, 0.0, 14.0));
        $camera->setViewCenter(new QVector3D(0.0, 0.0, 0.0));

        $renderSettings = new QRenderSettings($this->root);
        $surfaceSelector = new QRenderSurfaceSelector();
        $viewport = new QViewport($surfaceSelector);
        $clearBuffers = new QClearBuffers($viewport);
        $cameraSelector = new QCameraSelector($clearBuffers);

        $surfaceSelector->setSurface($this);
        $viewport->setNormalizedRect(new QRectF(0.0, 0.0, 1.0, 1.0));
        $clearBuffers->setBuffers(QClearBuffers::ColorDepthBuffer);
        $clearBuffers->setClearColor(QColor::fromRgbF(0.08, 0.10, 0.14, 1.0));
        $cameraSelector->setCamera($camera);
        $renderSettings->setActiveFrameGraph($surfaceSelector);
        $this->root->addComponent($renderSettings);

        $inputSettings = new QInputSettings($this->root);
        $inputSettings->setEventSource($this);
        $this->root->addComponent($inputSettings);

        $orbit = new QOrbitCameraController($this->root);
        $orbit->setCamera($camera);
        $orbit->setLinearSpeed(40.0);
        $orbit->setLookSpeed(180.0);
        $orbit->setZoomInLimit(2.0);

        $logicalDevice = new QLogicalDevice($this->root);
        $logicalDevice->addAction(new QAction($this->root));
        $logicalDevice->addAxis(new QAxis($this->root));

        $cubeEntity = new QEntity($this->root);
        $cubeMesh = new QCuboidMesh($cubeEntity);
        $cubeMesh->setXExtent(2.2);
        $cubeMesh->setYExtent(2.2);
        $cubeMesh->setZExtent(2.2);

        $cubeMaterial = new QPhongMaterial($cubeEntity);
        $cubeMaterial->setDiffuse(QColor::fromRgbF(0.20, 0.75, 0.95, 1.0));
        $cubeMaterial->setAmbient(QColor::fromRgbF(0.08, 0.28, 0.36, 1.0));
        $cubeMaterial->setSpecular(QColor::fromRgbF(0.95, 0.95, 0.95, 1.0));
        $cubeMaterial->setShininess(32.0);

        $this->cubeTransform = new QTransform($cubeEntity);
        $this->cubeTransform->setTranslation(new QVector3D(0.0, 0.0, 0.0));

        $cubeEntity->addComponent($cubeMesh);
        $cubeEntity->addComponent($cubeMaterial);
        $cubeEntity->addComponent($this->cubeTransform);

        $this->spinTimer = new QTimer($this);
        $this->spinTimer->onTimeout(function (): void {
            if ($this->cubeTransform === null) {
                return;
            }

            $this->spinAngle += 1.8;
            if ($this->spinAngle >= 360.0) {
                $this->spinAngle -= 360.0;
            }
            $this->cubeTransform->setRotationY($this->spinAngle);
        });
        $this->spinTimer->start(16);

        $this->engine->setRootEntity($this->root);

        example_line('[qt3d-showcase] modules wired: Qt3DCore + Qt3DRender + Qt3DExtras + Qt3DInput');
        example_line('[qt3d-showcase] visual check: cyan cube should be visible and slowly spinning');
        example_line('[qt3d-showcase] interaction check: drag to orbit, wheel/trackpad scroll to zoom');
    }
}

example_section('Qt3D Modules Showcase');

$app = new QGuiApplication();
QCoreApplication::setApplicationName('Qt3D Modules Showcase');

$window = new Qt3DModulesShowcaseWindow();
$window->show();

$autoQuitSeconds = example_auto_quit_seconds();
if ($autoQuitSeconds > 0) {
    $timer = new QTimer();
    $timer->setSingleShot(true);
    $timer->onTimeout(static function (): void {
        QCoreApplication::quit();
    });
    $timer->start($autoQuitSeconds * 1000);
    example_line(sprintf('[qt3d-showcase] auto-quit in %d second(s)', $autoQuitSeconds));
}

exit($app->exec());
