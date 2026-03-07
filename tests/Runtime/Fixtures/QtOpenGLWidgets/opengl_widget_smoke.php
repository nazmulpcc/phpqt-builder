<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Core\QCoreApplication;
use Qt\Core\QTimer;
use Qt\Gui\QSurfaceFormat;
use Qt\OpenGL\QOpenGLBuffer;
use Qt\OpenGL\QOpenGLFunctions_2_0;
use Qt\OpenGL\QOpenGLShader;
use Qt\OpenGL\QOpenGLShaderProgram;
use Qt\OpenGL\QOpenGLVertexArrayObject;
use Qt\OpenGLWidgets\QOpenGLWidget;
use Qt\Widgets\QApplication;

qt_runtime_require_class('Qt\\OpenGLWidgets\\QOpenGLWidget', 'QtOpenGLWidgets is unavailable in this build.');
qt_runtime_require_class('Qt\\OpenGL\\QOpenGLShaderProgram', 'QtOpenGL shader support is unavailable in this build.');

final class RuntimeOpenGLSmokeWidget extends QOpenGLWidget
{
    private const int GL_COLOR_BUFFER_BIT = 0x00004000;
    private const int GL_FLOAT = 0x1406;
    private const int GL_TRIANGLES = 0x0004;

    private const string VERTEX_SHADER = <<<'GLSL'
attribute vec2 position;
attribute vec2 uv;
varying vec2 v_uv;

void main()
{
    v_uv = uv;
    gl_Position = vec4(position, 0.0, 1.0);
}
GLSL;

    private const string FRAGMENT_SHADER = <<<'GLSL'
#ifdef GL_ES
precision mediump float;
#endif

uniform float u_time;
varying vec2 v_uv;

void main()
{
    vec2 p = v_uv - 0.5;
    float wave = sin((p.x * 9.0) + u_time * 2.0) * cos((p.y * 7.0) - u_time * 1.4);
    vec3 base = vec3(0.05, 0.09, 0.16);
    vec3 tint = vec3(0.15, 0.65, 0.96) + wave * vec3(0.55, 0.18, 0.32);
    gl_FragColor = vec4(base + tint * 0.65, 1.0);
}
GLSL;

    public int $initializeHits = 0;
    public int $paintHits = 0;
    public bool $shaderReady = false;
    public string $error = '';

    private ?QOpenGLFunctions_2_0 $gl = null;
    private ?QOpenGLShaderProgram $program = null;
    private ?QOpenGLBuffer $vertexBuffer = null;
    private ?QOpenGLVertexArrayObject $vertexArray = null;
    private bool $useVertexArray = false;
    private float $startedAt = 0.0;

    public function __construct()
    {
        parent::__construct();
        $this->startedAt = microtime(true);
    }

    protected function initializeGL(): void
    {
        $this->initializeHits++;
        $this->gl = new QOpenGLFunctions_2_0();
        if (!$this->gl->initializeOpenGLFunctions()) {
            $this->error = 'OpenGL function table initialization failed.';

            return;
        }

        $program = new QOpenGLShaderProgram($this);
        if (!$program->addShaderFromSourceCode(QOpenGLShader::Vertex, self::VERTEX_SHADER)) {
            $this->error = 'Vertex shader failed: ' . trim($program->log());

            return;
        }

        if (!$program->addShaderFromSourceCode(QOpenGLShader::Fragment, self::FRAGMENT_SHADER)) {
            $this->error = 'Fragment shader failed: ' . trim($program->log());

            return;
        }

        $program->bindAttributeLocation('position', 0);
        $program->bindAttributeLocation('uv', 1);

        if (!$program->link()) {
            $this->error = 'Shader link failed: ' . trim($program->log());

            return;
        }

        $buffer = new QOpenGLBuffer(QOpenGLBuffer::VertexBuffer);
        if (!$buffer->create() || !$buffer->bind()) {
            $this->error = 'Vertex buffer creation failed.';

            return;
        }

        $buffer->allocate(pack(
            'f*',
            -1.0, -1.0, 0.0, 0.0,
             1.0, -1.0, 1.0, 0.0,
             1.0,  1.0, 1.0, 1.0,
            -1.0, -1.0, 0.0, 0.0,
             1.0,  1.0, 1.0, 1.0,
            -1.0,  1.0, 0.0, 1.0,
        ), 96);

        $vao = new QOpenGLVertexArrayObject($this);
        $this->useVertexArray = $vao->create();

        $this->program = $program;
        $this->vertexBuffer = $buffer;
        $this->vertexArray = $vao;

        if ($this->useVertexArray) {
            $vao->bind();
        }

        $program->bind();
        $program->enableAttributeArray(0);
        $program->setAttributeBuffer(0, self::GL_FLOAT, 0, 2, 16);
        $program->enableAttributeArray(1);
        $program->setAttributeBuffer(1, self::GL_FLOAT, 8, 2, 16);
        $program->release();

        if ($this->useVertexArray) {
            $vao->release();
        }

        $buffer->release();
        $this->shaderReady = true;
    }

    protected function resizeGL(int $w, int $h): void
    {
        if ($this->gl instanceof QOpenGLFunctions_2_0) {
            $this->gl->glViewport(0, 0, max(1, $w), max(1, $h));
        }
    }

    protected function paintGL(): void
    {
        $this->paintHits++;

        if (
            !$this->shaderReady
            || !$this->gl instanceof QOpenGLFunctions_2_0
            || !$this->program instanceof QOpenGLShaderProgram
        ) {
            return;
        }

        $this->gl->glClearColor(0.02, 0.03, 0.06, 1.0);
        $this->gl->glClear(self::GL_COLOR_BUFFER_BIT);

        if (!$this->program->bind()) {
            $this->error = 'Program bind failed: ' . trim($this->program->log());
            $this->shaderReady = false;

            return;
        }

        $this->program->setUniformValue('u_time', (float) (microtime(true) - $this->startedAt));

        if ($this->useVertexArray && $this->vertexArray instanceof QOpenGLVertexArrayObject) {
            $this->vertexArray->bind();
        } elseif ($this->vertexBuffer instanceof QOpenGLBuffer) {
            $this->vertexBuffer->bind();
        }

        $this->gl->glDrawArrays(self::GL_TRIANGLES, 0, 6);

        if ($this->useVertexArray && $this->vertexArray instanceof QOpenGLVertexArrayObject) {
            $this->vertexArray->release();
        } elseif ($this->vertexBuffer instanceof QOpenGLBuffer) {
            $this->vertexBuffer->release();
        }

        $this->program->release();
    }
}

$app = new QApplication();
$widget = new RuntimeOpenGLSmokeWidget();

$format = new QSurfaceFormat();
$format->setRenderableType(QSurfaceFormat::OpenGL);
$format->setVersion(2, 0);
$format->setProfile(QSurfaceFormat::CompatibilityProfile);
$widget->setFormat($format);
$widget->resize(320, 240);
$widget->show();

$frameBudget = new QTimer($widget);
$frameBudget->setInterval(40);
$frameBudget->onTimeout(static function () use ($widget): void {
    if ($widget->paintHits >= 2 || $widget->error !== '') {
        QCoreApplication::quit();
    }
});
$frameBudget->start();

$escapeTimer = new QTimer($widget);
$escapeTimer->setSingleShot(true);
$escapeTimer->onTimeout(static function () use ($widget): void {
    if ($widget->error === '') {
        $widget->error = 'Timed out waiting for OpenGL paint.';
    }
    QCoreApplication::quit();
});
$escapeTimer->start(1500);

QApplication::exec();

if (!$widget->isValid() && $widget->initializeHits === 0 && $widget->paintHits === 0) {
    qt_runtime_skip('QOpenGLWidget is not supported on the active platform plugin.');
}

qt_runtime_result([
    'is_valid' => $widget->isValid(),
    'initialize_hits' => $widget->initializeHits,
    'paint_hits' => $widget->paintHits,
    'shader_ready' => $widget->shaderReady,
    'error' => $widget->error,
]);
