<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Qt\Core\QCoreApplication;
use Qt\Core\QRectF;
use Qt\Core\QSize;
use Qt\Core\QTimer;
use Qt\Core\QUrl;
use Qt\Gui\QColor;
use Qt\Gui\QGuiApplication;
use Qt\Gui\QMouseEvent;
use Qt\Gui\QSurface;
use Qt\Gui\QSurfaceFormat;
use Qt\Gui\QWheelEvent;
use Qt\Gui\QWindow;
use Qt\Gui\QVector3D;
use Qt\Qt3DCore\QAspectEngine;
use Qt\Qt3DCore\QEntity;
use Qt\Qt3DRender\QCamera;
use Qt\Qt3DRender\QCameraSelector;
use Qt\Qt3DRender\QClearBuffers;
use Qt\Qt3DRender\QRenderSettings;
use Qt\Qt3DRender\QRenderSurfaceSelector;
use Qt\Qt3DRender\QSceneLoader;
use Qt\Qt3DRender\QViewport;

if (!class_exists(\Qt\Qt3DCore\QAspectEngine::class)) {
    example_fail('Qt3DCore classes are unavailable in this build. Rebuild with Qt3DCore and Qt3DRender support.');
}

final class Qt3DSceneWindow extends QWindow
{
    private readonly string $scenePath;
    private readonly string $sceneLabel;

    private ?QAspectEngine $engine = null;
    private ?QEntity $rootEntity = null;
    private ?QEntity $sceneEntity = null;
    private ?QRenderSettings $renderSettings = null;
    private ?QRenderSurfaceSelector $surfaceSelector = null;
    private ?QViewport $viewport = null;
    private ?QClearBuffers $clearBuffers = null;
    private ?QCameraSelector $cameraSelector = null;
    private ?QCamera $camera = null;
    private ?QSceneLoader $sceneLoader = null;

    private float $yawDegrees = 28.0;
    private float $pitchDegrees = -18.0;
    private float $distance = 16.0;
    private float $targetY = 1.4;

    private bool $dragging = false;
    private float $lastMouseX = 0.0;
    private float $lastMouseY = 0.0;
    private string $statusLine = 'Booting scene loader';
    private bool $autoQuitOnTerminalStatus = false;
    private bool $sceneInitialized = false;
    private bool $shutdownRequested = false;
    private ?QTimer $shutdownTimer = null;
    private ?QTimer $quitTimer = null;
    private ?QTimer $autoQuitTimer = null;

    public function __construct(string $scenePath)
    {
        parent::__construct();

        $this->scenePath = $scenePath;
        $this->sceneLabel = basename($scenePath);

        $this->configureWindow();
        $this->updateStatus('Loading scene', true);
    }

    public function cleanUp()
    {
        $this->shutdownTimer = null;
        $this->quitTimer = null;
        $this->autoQuitTimer = null;
        $this->engine = null;
        $this->rootEntity = null;
        $this->sceneEntity = null;
        $this->renderSettings = null;
        $this->surfaceSelector = null;
        $this->viewport = null;
        $this->clearBuffers = null;
        $this->cameraSelector = null;
        $this->camera = null;
        $this->sceneLoader = null;
    }

    protected function exposeEvent(\Qt\Gui\QExposeEvent $event): void
    {
        parent::exposeEvent($event);
        if (!$this->sceneInitialized && $this->isExposed()) {
            $this->initializeScene();
        }
        $this->syncSurfaceMetrics();
    }

    protected function resizeEvent(\Qt\Gui\QResizeEvent $event): void
    {
        parent::resizeEvent($event);
        $this->syncSurfaceMetrics();
        $this->refreshCamera();
    }

    protected function closeEvent(\Qt\Gui\QCloseEvent $event): void
    {
        if ($this->shutdownRequested) {
            $event->accept();
            return;
        }

        $event->ignore();
        $this->requestShutdown();
    }

    protected function mousePressEvent(QMouseEvent $event): void
    {
        if ($event->button() === \Qt\MouseButton::LeftButton) {
            $position = $event->position();
            $this->dragging = true;
            $this->lastMouseX = $position->x();
            $this->lastMouseY = $position->y();
        }

        parent::mousePressEvent($event);
    }

    protected function mouseReleaseEvent(QMouseEvent $event): void
    {
        if ($event->button() === \Qt\MouseButton::LeftButton) {
            $this->dragging = false;
        }

        parent::mouseReleaseEvent($event);
    }

    protected function mouseMoveEvent(QMouseEvent $event): void
    {
        if (!$this->dragging) {
            parent::mouseMoveEvent($event);
            return;
        }

        $position = $event->position();
        $dx = $position->x() - $this->lastMouseX;
        $dy = $position->y() - $this->lastMouseY;

        $this->yawDegrees += $dx * 0.42;
        $this->pitchDegrees += $dy * 0.28;
        $this->pitchDegrees = max(-75.0, min(15.0, $this->pitchDegrees));

        $this->lastMouseX = $position->x();
        $this->lastMouseY = $position->y();

        $this->refreshCamera();

        parent::mouseMoveEvent($event);
    }

    protected function wheelEvent(QWheelEvent $event): void
    {
        $delta = $event->angleDelta()->y();
        $this->distance -= $delta * 0.01;
        $this->distance = max(4.0, min(45.0, $this->distance));
        $this->refreshCamera();

        parent::wheelEvent($event);
    }

    private function configureWindow(): void
    {
        $this->setTitle('Qt3D Scene Loader');
        $this->setSurfaceType(QSurface::OpenGLSurface);
        $this->setMinimumSize(new QSize(960, 620));
        $this->resize(1280, 780);
        if ($this->screen() === null) {
            $primaryScreen = QGuiApplication::primaryScreen();
            if ($primaryScreen !== null) {
                $this->setScreen($primaryScreen);
            }
        }

        $format = new QSurfaceFormat();
        $format->setRenderableType(QSurfaceFormat::OpenGL);
        $format->setVersion(2, 0);
        $format->setProfile(QSurfaceFormat::CompatibilityProfile);
        $format->setDepthBufferSize(24);
        $format->setStencilBufferSize(8);
        $format->setSwapBehavior(QSurfaceFormat::DoubleBuffer);
        $format->setSwapInterval(1);
        $this->setFormat($format);
    }

    private function initializeScene(): void
    {
        if ($this->sceneInitialized) {
            return;
        }

        if ($this->screen() === null) {
            $primaryScreen = QGuiApplication::primaryScreen();
            if ($primaryScreen !== null) {
                $this->setScreen($primaryScreen);
            }
        }

        example_line(sprintf(
            '[qt3d] initializeScene screen=%s exposed=%s size=%dx%d',
            $this->screen() === null ? 'null' : 'set',
            $this->isExposed() ? 'yes' : 'no',
            $this->width(),
            $this->height()
        ));

        $this->create();
        $this->buildScene();
        $this->refreshCamera();
        $this->sceneLoader?->setSource(QUrl::fromLocalFile($this->scenePath));
        $this->sceneInitialized = true;
    }

    private function buildScene(): void
    {
        $this->engine = new QAspectEngine();
        $this->engine->setRunMode(QAspectEngine::Automatic);
        // Let Qt own the render aspect lifecycle instead of keeping a PHP-owned
        // QRenderAspect alive across window teardown.
        $this->engine->registerAspect('render');

        $this->rootEntity = new QEntity();

        $this->renderSettings = new QRenderSettings($this->rootEntity);
        $this->renderSettings->setRenderPolicy(QRenderSettings::Always);
        $this->rootEntity->addComponent($this->renderSettings);

        $this->surfaceSelector = new QRenderSurfaceSelector();
        $this->surfaceSelector->setSurface($this);

        $this->viewport = new QViewport($this->surfaceSelector);
        $this->viewport->setNormalizedRect(new QRectF(0.0, 0.0, 1.0, 1.0));

        $this->clearBuffers = new QClearBuffers($this->viewport);
        $this->clearBuffers->setBuffers(QClearBuffers::ColorDepthBuffer);
        $this->clearBuffers->setClearColor(new QColor('#07101d'));

        $this->cameraSelector = new QCameraSelector($this->clearBuffers);

        $this->camera = new QCamera($this->rootEntity);
        $this->camera->setNearPlane(0.1);
        $this->camera->setFarPlane(2000.0);
        $this->camera->setFieldOfView(42.0);
        $this->camera->setUpVector(new QVector3D(0.0, 1.0, 0.0));
        $this->cameraSelector->setCamera($this->camera);

        $this->renderSettings->setActiveFrameGraph($this->surfaceSelector);

        $this->sceneEntity = new QEntity($this->rootEntity);
        $this->sceneLoader = new QSceneLoader($this->sceneEntity);
        $this->sceneEntity->addComponent($this->sceneLoader);

        $this->sceneLoader->onStatusChanged(function (int $status): void {
            if ($status === QSceneLoader::Loading) {
                $this->updateStatus('Loading scene', true);
                return;
            }

            if ($status === QSceneLoader::Ready) {
                $entityCount = count($this->sceneLoader->entityNames());
                $this->updateStatus(sprintf('Scene ready (%d named node%s)', $entityCount, $entityCount === 1 ? '' : 's'), true);
                $this->camera->viewEntity($this->sceneEntity);
                $this->refreshCamera();
                if ($this->autoQuitOnTerminalStatus) {
                    $this->requestShutdown();
                }
                return;
            }

            if ($status === QSceneLoader::Error) {
                $this->updateStatus('Scene loader error', false);
                if ($this->autoQuitOnTerminalStatus) {
                    $this->requestShutdown();
                }
                return;
            }

            $this->updateStatus('Scene loader idle', true);
        });

        $this->engine->setRootEntity($this->rootEntity);
    }
    public function enableAutoQuitOnTerminalStatus(bool $enabled): void
    {
        $this->autoQuitOnTerminalStatus = $enabled;
    }

    public function scheduleAutoQuit(int $seconds): void
    {
        if ($seconds <= 0) {
            $this->autoQuitTimer = null;
            return;
        }

        $this->autoQuitTimer = new QTimer();
        $this->autoQuitTimer->setSingleShot(true);
        $this->autoQuitTimer->onTimeout(function (): void {
            $this->requestShutdown();
        });
        $this->autoQuitTimer->start($seconds * 1000);
    }

    private function syncSurfaceMetrics(): void
    {
        if ($this->surfaceSelector === null) {
            return;
        }

        $width = max(1, $this->width());
        $height = max(1, $this->height());
        $ratio = max(1.0, $this->devicePixelRatio());

        $this->surfaceSelector->setSurfacePixelRatio($ratio);
        $this->surfaceSelector->setExternalRenderTargetSize(new QSize($width, $height));
    }

    private function refreshCamera(): void
    {
        if ($this->camera === null) {
            return;
        }

        $width = max(1, $this->width());
        $height = max(1, $this->height());
        $aspect = $height > 0 ? ($width / $height) : 1.0;

        $yaw = deg2rad($this->yawDegrees);
        $pitch = deg2rad($this->pitchDegrees);
        $radius = $this->distance * cos($pitch);

        $target = new QVector3D(0.0, $this->targetY, 0.0);
        $position = new QVector3D(
            $radius * sin($yaw),
            $this->distance * sin($pitch) + $this->targetY,
            $radius * cos($yaw)
        );

        $this->camera->setAspectRatio((float) $aspect);
        $this->camera->setPosition($position);
        $this->camera->setViewCenter($target);
    }

    private function updateStatus(string $message, bool $healthy): void
    {
        $this->statusLine = $message;
        $prefix = $healthy ? 'Qt3D Scene Loader' : 'Qt3D Scene Loader (issue)';
        $this->setTitle(sprintf('%s • %s • %s', $prefix, $this->sceneLabel, $message));
        example_line(sprintf('[qt3d] %s', $message));
    }

    private function requestShutdown(): void
    {
        if ($this->shutdownRequested) {
            return;
        }

        $this->shutdownRequested = true;
        $this->hide();

        $this->shutdownTimer = new QTimer();
        $this->shutdownTimer->setSingleShot(true);
        $this->shutdownTimer->onTimeout(function (): void {
            if ($this->sceneLoader !== null) {
                $this->sceneLoader->setSource(new QUrl());
                $this->sceneLoader->deleteLater();
            }
            if ($this->sceneEntity !== null) {
                $this->sceneEntity->deleteLater();
            }
            if ($this->rootEntity !== null) {
                $this->rootEntity->deleteLater();
            }
            if ($this->engine !== null) {
                $this->engine->setRunMode(QAspectEngine::Manual);
                $this->engine->deleteLater();
            }

            $this->quitTimer = new QTimer();
            $this->quitTimer->setSingleShot(true);
            $this->quitTimer->onTimeout(static function (): void {
                QCoreApplication::quit();
            });
            $this->quitTimer->start(25);
        });
        $this->shutdownTimer->start(0);
    }
}

example_section('Qt3D Scene Loader');

$argc = 0;
$argvList = [];
$app = new QGuiApplication($argc, $argvList);
QCoreApplication::setApplicationName('Qt3D Scene Loader');
QGuiApplication::setQuitOnLastWindowClosed(false);

$scenePath = $argv[1] ?? (__DIR__ . '/../obj-browser/sample.obj');
$resolvedScenePath = realpath($scenePath);
if ($resolvedScenePath === false) {
    example_fail(sprintf('Scene file not found: %s', $scenePath));
}

$window = new Qt3DSceneWindow($resolvedScenePath);
// $app->onAboutToQuit(fn() => $window->cleanUp());
$autoQuitSeconds = example_auto_quit_seconds();
$window->enableAutoQuitOnTerminalStatus(false);
$window->scheduleAutoQuit($autoQuitSeconds);

$window->show();

example_line('qt3d scene loader ready');
QGuiApplication::exec();
