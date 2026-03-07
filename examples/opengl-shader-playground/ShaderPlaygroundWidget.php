<?php

declare(strict_types=1);

use Qt\Core\QEvent;
use Qt\Core\QTimer;
use Qt\Gui\QCursor;
use Qt\Gui\QMouseEvent;
use Qt\Gui\QOpenGLContext;
use Qt\OpenGL\QOpenGLBuffer;
use Qt\OpenGL\QOpenGLFunctions_2_0;
use Qt\OpenGL\QOpenGLShader;
use Qt\OpenGL\QOpenGLShaderProgram;
use Qt\OpenGL\QOpenGLVertexArrayObject;
use Qt\OpenGLWidgets\QOpenGLWidget;
use Qt\Widgets\QWidget;

final class ShaderPlaygroundWidget extends QOpenGLWidget
{
    private const int GL_COLOR_BUFFER_BIT = 0x00004000;
    private const int GL_FLOAT = 0x1406;
    private const int GL_TRIANGLES = 0x0004;

    private ?QOpenGLFunctions_2_0 $gl = null;
    private ?QOpenGLShaderProgram $program = null;
    private ?QOpenGLBuffer $vertexBuffer = null;
    private ?QOpenGLVertexArrayObject $vertexArray = null;
    private ?QTimer $timer = null;
    private $statusSink = null;
    private $pointerSink = null;
    private float $intensity = 1.15;
    private float $scale = 0.95;
    private float $hueShift = 0.08;
    private float $pointerX = 0.5;
    private float $pointerY = 0.5;
    private int $pointerPixelX = 0;
    private int $pointerPixelY = 0;
    private float $startTime;
    private float $fpsWindowStartedAt;
    private int $framesSinceStatus = 0;
    private int $viewportWidth = 1;
    private int $viewportHeight = 1;
    private string $statusMessage = 'Booting OpenGL context...';
    private bool $shaderReady = false;
    private bool $useVertexArray = false;

    public function __construct(?QWidget $parent = null)
    {
        parent::__construct($parent);

        $this->startTime = microtime(true);
        $this->fpsWindowStartedAt = $this->startTime;

        $this->setMouseTracking(true);
        $this->setUpdateBehavior(self::PartialUpdate);

        $timer = new QTimer($this);
        $timer->setInterval(16);
        $timer->onTimeout(function (): void {
            $this->updatePointerFromCursor();
            $this->update();
        });
        $timer->start();
        $this->timer = $timer;
    }

    public function setStatusSink(callable $sink): void
    {
        $this->statusSink = $sink;
        $sink($this->statusMessage, $this->shaderReady);
    }

    public function setPointerSink(callable $sink): void
    {
        $this->pointerSink = $sink;
        $sink($this->pointerPixelX, $this->pointerPixelY, $this->pointerX, $this->pointerY);
    }

    public function setIntensity(float $value): void
    {
        $this->intensity = max(0.25, min(2.5, $value));
        $this->update();
    }

    public function setScale(float $value): void
    {
        $this->scale = max(0.35, min(2.4, $value));
        $this->update();
    }

    public function setHueShift(float $value): void
    {
        $this->hueShift = max(-0.5, min(0.5, $value));
        $this->update();
    }

    public function applyPreset(string $preset): void
    {
        if ($preset === 'calm') {
            $this->setIntensity(0.82);
            $this->setScale(0.72);
            $this->setHueShift(-0.08);

            return;
        }

        $this->setIntensity(1.68);
        $this->setScale(1.18);
        $this->setHueShift(0.24);
    }

    protected function initializeGL(): void
    {
        $this->makeCurrent();

        $this->gl = new QOpenGLFunctions_2_0();
        if (!$this->gl->initializeOpenGLFunctions()) {
            $this->fail('OpenGL 2.0 function table initialization failed.');

            return;
        }

        $context = $this->context();
        if (!$context instanceof QOpenGLContext || !$context->isValid()) {
            $this->fail('OpenGL context is invalid.');

            return;
        }

        $program = new QOpenGLShaderProgram($this);
        if (!$program->addShaderFromSourceFile(QOpenGLShader::Vertex, __DIR__ . '/shader.vert')) {
            $this->fail('Vertex shader compilation failed: ' . trim($program->log()));

            return;
        }

        if (!$program->addShaderFromSourceFile(QOpenGLShader::Fragment, __DIR__ . '/shader.frag')) {
            $this->fail('Fragment shader compilation failed: ' . trim($program->log()));

            return;
        }

        $program->bindAttributeLocation('position', 0);
        $program->bindAttributeLocation('uv', 1);

        if (!$program->link()) {
            $this->fail('Shader link failed: ' . trim($program->log()));

            return;
        }

        $vertexBuffer = new QOpenGLBuffer(QOpenGLBuffer::VertexBuffer);
        $vertexBuffer->setUsagePattern(QOpenGLBuffer::StaticDraw);
        if (!$vertexBuffer->create() || !$vertexBuffer->bind()) {
            $this->fail('Vertex buffer creation failed.');

            return;
        }

        $vertexData = pack(
            'f*',
            -1.0, -1.0, 0.0, 0.0,
             1.0, -1.0, 1.0, 0.0,
             1.0,  1.0, 1.0, 1.0,
            -1.0, -1.0, 0.0, 0.0,
             1.0,  1.0, 1.0, 1.0,
            -1.0,  1.0, 0.0, 1.0,
        );
        $vertexBuffer->allocate($vertexData, strlen($vertexData));

        $vertexArray = new QOpenGLVertexArrayObject($this);
        $this->useVertexArray = $vertexArray->create();

        $this->program = $program;
        $this->vertexBuffer = $vertexBuffer;
        $this->vertexArray = $vertexArray;

        if ($this->useVertexArray) {
            $vertexArray->bind();
            $this->configureAttributes();
            $vertexArray->release();
        } else {
            $this->configureAttributes();
        }

        $vertexBuffer->release();
        $program->release();

        $this->shaderReady = true;
        $format = $context->format();
        $this->setStatus(sprintf(
            'Shader ready · OpenGL %d.%d · %s mode',
            $format->majorVersion(),
            $format->minorVersion(),
            $this->useVertexArray ? 'VAO' : 'direct'
        ));
    }

    protected function resizeGL(int $w, int $h): void
    {
        $pixelRatio = 1.0;
        $windowHandle = $this->windowHandle();
        if ($windowHandle instanceof \Qt\Gui\QWindow) {
            $pixelRatio = max(1.0, $windowHandle->devicePixelRatio());
        }
        $screen = $this->screen();
        if ($screen instanceof \Qt\Gui\QScreen) {
            $pixelRatio = max($pixelRatio, max(1.0, $screen->devicePixelRatio()));
        }
        $this->viewportWidth = max(1, (int) round($w * $pixelRatio));
        $this->viewportHeight = max(1, (int) round($h * $pixelRatio));

        if ($this->gl instanceof QOpenGLFunctions_2_0) {
            $this->gl->glViewport(0, 0, $this->viewportWidth, $this->viewportHeight);
        }
    }

    protected function paintGL(): void
    {
        if (
            !$this->shaderReady
            || !$this->gl instanceof QOpenGLFunctions_2_0
            || !$this->program instanceof QOpenGLShaderProgram
        ) {
            return;
        }

        $this->framesSinceStatus++;

        $this->gl->glViewport(0, 0, $this->viewportWidth, $this->viewportHeight);
        $this->gl->glClearColor(0.03, 0.04, 0.08, 1.0);
        $this->gl->glClear(self::GL_COLOR_BUFFER_BIT);

        if (!$this->program->bind()) {
            $this->fail('Shader program bind failed: ' . trim($this->program->log()));

            return;
        }

        $time = microtime(true) - $this->startTime;
        $this->program->setUniformValue('u_time', (float) $time);
        $this->program->setUniformValue('u_resolution', (float) $this->viewportWidth, (float) $this->viewportHeight);
        $this->program->setUniformValue('u_pointer', $this->pointerX, $this->pointerY);
        $this->program->setUniformValue('u_intensity', $this->intensity);
        $this->program->setUniformValue('u_scale', $this->scale);
        $this->program->setUniformValue('u_hueShift', $this->hueShift);

        if ($this->useVertexArray && $this->vertexArray instanceof QOpenGLVertexArrayObject) {
            $this->vertexArray->bind();
        } else {
            $this->configureAttributes();
        }

        $this->gl->glDrawArrays(self::GL_TRIANGLES, 0, 6);

        if ($this->useVertexArray && $this->vertexArray instanceof QOpenGLVertexArrayObject) {
            $this->vertexArray->release();
        } elseif ($this->vertexBuffer instanceof QOpenGLBuffer) {
            $this->vertexBuffer->release();
        }

        $this->program->release();
        $this->updateStatusFrameRate();
    }

    protected function mouseMoveEvent(QMouseEvent $event): void
    {
        $this->updatePointerFromEvent($event);
        $this->update();
    }

    protected function mousePressEvent(QMouseEvent $event): void
    {
        $this->updatePointerFromEvent($event);
        $this->update();
    }

    public function event(QEvent $event): bool
    {
        $type = $event->type();

        if (
            $event instanceof QMouseEvent
            && ($type === QEvent::MouseMove || $type === QEvent::MouseButtonPress || $type === QEvent::MouseButtonRelease)
        ) {
            $this->updatePointerFromEvent($event);
        }

        return parent::event($event);
    }

    private function configureAttributes(): void
    {
        if (
            !$this->program instanceof QOpenGLShaderProgram
            || !$this->vertexBuffer instanceof QOpenGLBuffer
            || !$this->vertexBuffer->bind()
        ) {
            return;
        }

        $this->program->enableAttributeArray(0);
        $this->program->setAttributeBuffer(0, self::GL_FLOAT, 0, 2, 16);
        $this->program->enableAttributeArray(1);
        $this->program->setAttributeBuffer(1, self::GL_FLOAT, 8, 2, 16);
    }

    private function updateStatusFrameRate(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->fpsWindowStartedAt;
        if ($elapsed < 0.45) {
            return;
        }

        $fps = $this->framesSinceStatus / max($elapsed, 0.0001);
        $this->framesSinceStatus = 0;
        $this->fpsWindowStartedAt = $now;

        $this->setStatus(sprintf(
            'Shader ready · %.1f FPS · intensity %.2f · scale %.2f · hue %+0.2f',
            $fps,
            $this->intensity,
            $this->scale,
            $this->hueShift
        ));
    }

    private function fail(string $message): void
    {
        $this->shaderReady = false;
        $this->setStatus($message);
        fwrite(STDERR, "[opengl-shader-playground] {$message}\n");
    }

    private function setStatus(string $message): void
    {
        $this->statusMessage = $message;

        if (is_callable($this->statusSink)) {
            ($this->statusSink)($message, $this->shaderReady);
        }
    }

    private function updatePointerFromEvent(QMouseEvent $event): void
    {
        $this->applyPointerCoordinates($event->x(), $event->y());
    }

    private function updatePointerFromCursor(): void
    {
        $global = QCursor::pos();
        $local = $this->mapFromGlobal($global);

        $x = $local->x();
        $y = $local->y();

        if ($x < 0 || $y < 0 || $x > $this->width() || $y > $this->height()) {
            return;
        }

        $this->applyPointerCoordinates($x, $y);
    }

    private function applyPointerCoordinates(int $x, int $y): void
    {
        $width = max(1, $this->width());
        $height = max(1, $this->height());

        $this->pointerPixelX = $x;
        $this->pointerPixelY = $y;
        $this->pointerX = max(0.0, min(1.0, $x / $width));
        $this->pointerY = max(0.0, min(1.0, 1.0 - ($y / $height)));

        if (is_callable($this->pointerSink)) {
            ($this->pointerSink)($this->pointerPixelX, $this->pointerPixelY, $this->pointerX, $this->pointerY);
        }
    }
}
