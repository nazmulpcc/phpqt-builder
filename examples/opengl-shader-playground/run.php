<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Qt\Core\QCoreApplication;
use Qt\Core\QTimer;
use Qt\Gui\QMouseEvent;
use Qt\Gui\QOpenGLContext;
use Qt\Gui\QSurfaceFormat;
use Qt\OpenGL\QOpenGLBuffer;
use Qt\OpenGL\QOpenGLFunctions_2_0;
use Qt\OpenGL\QOpenGLShader;
use Qt\OpenGL\QOpenGLShaderProgram;
use Qt\OpenGL\QOpenGLVertexArrayObject;
use Qt\OpenGLWidgets\QOpenGLWidget;
use Qt\Orientation;
use Qt\Widgets\QApplication;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QSlider;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class ShaderPlaygroundWidget extends QOpenGLWidget
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
uniform vec2 u_resolution;
uniform vec2 u_pointer;
uniform float u_intensity;
uniform float u_scale;
uniform float u_hueShift;

varying vec2 v_uv;

vec3 palette(float t)
{
    vec3 a = vec3(0.09, 0.12, 0.18);
    vec3 b = vec3(0.45, 0.28, 0.38);
    vec3 c = vec3(0.50, 0.42, 0.62);
    vec3 d = vec3(0.10, 0.20, 0.33) + vec3(u_hueShift * 0.25, u_hueShift * 0.13, -u_hueShift * 0.2);
    return a + b * cos(6.28318 * (c * t + d));
}

void main()
{
    vec2 uv = v_uv;
    vec2 p = (uv - 0.5) * vec2(u_resolution.x / max(u_resolution.y, 1.0), 1.0) * (1.4 + u_scale);
    vec2 pointer = (u_pointer - 0.5) * vec2(u_resolution.x / max(u_resolution.y, 1.0), 1.0);

    float t = u_time * (0.45 + u_intensity * 0.65);
    float radius = length(p);
    float ripple = sin((radius * 9.0) - (t * 3.4));
    float swirl = sin((p.x * 3.6 + t) + cos(p.y * 4.4 - t * 0.7));
    float warp = sin((p.y + pointer.x * 0.65) * 5.0 - t * 1.6) * cos((p.x - pointer.y * 0.45) * 4.0 + t * 1.2);
    float halo = exp(-5.5 * length(p - pointer * 0.8));

    float field = ripple * 0.32 + swirl * 0.28 + warp * 0.40 + halo * 0.75;
    vec3 color = palette(field + radius * 0.18 + t * 0.06);

    color += vec3(0.12, 0.22, 0.38) * halo * (0.8 + u_intensity * 0.45);
    color += vec3(0.85, 0.42, 0.18) * pow(max(halo, 0.0), 2.0) * 0.2;

    float vignette = smoothstep(1.4, 0.15, radius);
    color *= vignette;
    color = pow(max(color, vec3(0.0)), vec3(0.92));

    gl_FragColor = vec4(color, 1.0);
}
GLSL;

    private ?QOpenGLFunctions_2_0 $gl = null;
    private ?QOpenGLShaderProgram $program = null;
    private ?QOpenGLBuffer $vertexBuffer = null;
    private ?QOpenGLVertexArrayObject $vertexArray = null;
    private ?QTimer $timer = null;
    private $statusSink = null;
    private float $intensity = 1.15;
    private float $scale = 0.95;
    private float $hueShift = 0.08;
    private float $pointerX = 0.5;
    private float $pointerY = 0.5;
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
        if (!$program->addShaderFromSourceCode(QOpenGLShader::Vertex, self::VERTEX_SHADER)) {
            $this->fail('Vertex shader compilation failed: ' . trim($program->log()));

            return;
        }

        if (!$program->addShaderFromSourceCode(QOpenGLShader::Fragment, self::FRAGMENT_SHADER)) {
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
        $width = max(1, $this->width());
        $height = max(1, $this->height());

        $this->pointerX = max(0.0, min(1.0, $event->x() / $width));
        $this->pointerY = max(0.0, min(1.0, 1.0 - ($event->y() / $height)));
        $this->update();
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
}

/**
 * @return array{row: QWidget, slider: QSlider, value: QLabel}
 */
function playground_slider_row(string $labelText, int $min, int $max, int $value): array
{
    $row = new QWidget();
    $layout = new QVBoxLayout($row);
    $layout->setContentsMargins(0, 0, 0, 0);
    $layout->setSpacing(6);

    $top = new QWidget();
    $topLayout = new QHBoxLayout($top);
    $topLayout->setContentsMargins(0, 0, 0, 0);
    $topLayout->setSpacing(8);

    $label = new QLabel($labelText);
    $label->setProperty('role', 'controlLabel');
    $valueLabel = new QLabel((string) $value);
    $valueLabel->setProperty('role', 'valuePill');
    $valueLabel->setAlignment(0x0002 | 0x0080);

    $topLayout->addWidget($label);
    $topLayout->addStretch(1);
    $topLayout->addWidget($valueLabel);

    $slider = new QSlider();
    $slider->setOrientation(Orientation::Horizontal);
    $slider->setRange($min, $max);
    $slider->setValue($value);
    $slider->setTickPosition(QSlider::TicksBelow);
    $slider->setTickInterval(max(1, (int) floor(($max - $min) / 5)));

    $layout->addWidget($top);
    $layout->addWidget($slider);

    return [
        'row' => $row,
        'slider' => $slider,
        'value' => $valueLabel,
    ];
}

example_section('OpenGL Shader Playground');

QCoreApplication::setApplicationName('OpenGL Shader Playground');

$app = new QApplication();
$window = new QWidget();
$window->setWindowTitle('OpenGL Shader Playground');
$window->setMinimumSize(1180, 760);
$window->setStyleSheet(<<<'CSS'
QWidget {
    background: #06111f;
    color: #f5f8ff;
    font-family: "Avenir Next", "Segoe UI", sans-serif;
}
QWidget#shell {
    background: qlineargradient(x1:0, y1:0, x2:1, y2:1,
        stop:0 #081827,
        stop:0.55 #0a1020,
        stop:1 #120c19);
}
QWidget#sidePanel {
    background: rgba(11, 20, 34, 215);
    border: 1px solid #2e4c6d;
    border-radius: 22px;
}
QLabel#eyebrow {
    color: #73d7ff;
    font-size: 13px;
    letter-spacing: 1px;
    font-weight: 700;
}
QLabel#title {
    font-size: 28px;
    font-weight: 700;
}
QLabel#subtitle {
    color: #c9d9ef;
    font-size: 14px;
}
QLabel[role="sectionTitle"] {
    color: #ffd58b;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1px;
}
QLabel[role="controlLabel"] {
    color: #d8e6fb;
    font-size: 14px;
    font-weight: 600;
}
QLabel[role="valuePill"] {
    background: #13263a;
    border: 1px solid #3b627f;
    border-radius: 11px;
    color: #8ce4ff;
    padding: 4px 10px;
    min-width: 56px;
}
QLabel#status {
    background: rgba(12, 30, 46, 210);
    border: 1px solid #2b4f6b;
    border-radius: 16px;
    color: #dcecff;
    padding: 12px 14px;
}
QPushButton {
    background: qlineargradient(x1:0, y1:0, x2:1, y2:1,
        stop:0 #11c1ff,
        stop:1 #0f7bf5);
    border: 1px solid #63dbff;
    border-radius: 14px;
    color: #041522;
    padding: 10px 14px;
    font-size: 14px;
    font-weight: 700;
}
QPushButton[preset="secondary"] {
    background: qlineargradient(x1:0, y1:0, x2:1, y2:1,
        stop:0 #39214c,
        stop:1 #5f2f6e);
    border: 1px solid #c680ff;
    color: #fcf4ff;
}
QPushButton:hover {
    background: #41d1ff;
}
QSlider::groove:horizontal {
    background: #14263d;
    border: 1px solid #345c78;
    height: 7px;
    border-radius: 4px;
}
QSlider::handle:horizontal {
    background: #ff8f44;
    border: 1px solid #ffd2b2;
    width: 18px;
    margin: -7px 0;
    border-radius: 9px;
}
QSlider::sub-page:horizontal {
    background: qlineargradient(x1:0, y1:0, x2:1, y2:0,
        stop:0 #16c8ff,
        stop:1 #ff6e40);
    border-radius: 4px;
}
CSS);

$shell = new QWidget($window);
$shell->setObjectName('shell');
$shellLayout = new QHBoxLayout($shell);
$shellLayout->setContentsMargins(26, 26, 26, 26);
$shellLayout->setSpacing(22);

$glWidget = new ShaderPlaygroundWidget();
$format = new QSurfaceFormat();
$format->setRenderableType(QSurfaceFormat::OpenGL);
$format->setVersion(2, 0);
$format->setProfile(QSurfaceFormat::CompatibilityProfile);
$format->setSamples(4);
$format->setSwapInterval(1);
$glWidget->setFormat($format);
$glWidget->setMinimumSize(760, 620);

$sidePanel = new QWidget();
$sidePanel->setObjectName('sidePanel');
$sidePanel->setFixedWidth(320);
$sideLayout = new QVBoxLayout($sidePanel);
$sideLayout->setContentsMargins(22, 22, 22, 22);
$sideLayout->setSpacing(14);

$eyebrow = new QLabel('PHP + QTOPENGLWIDGETS');
$eyebrow->setObjectName('eyebrow');
$title = new QLabel('Shader Playground');
$title->setObjectName('title');
$subtitle = new QLabel('A live fragment shader rendered from PHP, with uniforms driven by Qt Widgets controls and mouse motion.');
$subtitle->setObjectName('subtitle');
$subtitle->setWordWrap(true);

$section = new QLabel('LIVE CONTROLS');
$section->setProperty('role', 'sectionTitle');

$intensityRow = playground_slider_row('Intensity', 35, 220, 115);
$scaleRow = playground_slider_row('Scale', 40, 180, 95);
$hueRow = playground_slider_row('Hue Shift', 0, 100, 58);

$presetPrimary = new QPushButton('Neon Pulse');
$presetSecondary = new QPushButton('Calm Drift');
$presetSecondary->setProperty('preset', 'secondary');

$buttonRow = new QWidget();
$buttonLayout = new QHBoxLayout($buttonRow);
$buttonLayout->setContentsMargins(0, 0, 0, 0);
$buttonLayout->setSpacing(10);
$buttonLayout->addWidget($presetPrimary);
$buttonLayout->addWidget($presetSecondary);

$status = new QLabel('OpenGL widget booting...');
$status->setObjectName('status');
$status->setWordWrap(true);

$hint = new QLabel('Move the pointer over the canvas to steer the glow field. Presets retune the same shader, not separate scenes.');
$hint->setObjectName('subtitle');
$hint->setWordWrap(true);

$sideLayout->addWidget($eyebrow);
$sideLayout->addWidget($title);
$sideLayout->addWidget($subtitle);
$sideLayout->addSpacing(8);
$sideLayout->addWidget($section);
$sideLayout->addWidget($intensityRow['row']);
$sideLayout->addWidget($scaleRow['row']);
$sideLayout->addWidget($hueRow['row']);
$sideLayout->addSpacing(4);
$sideLayout->addWidget($buttonRow);
$sideLayout->addSpacing(8);
$sideLayout->addWidget($status);
$sideLayout->addWidget($hint);
$sideLayout->addStretch(1);

$glWidget->setStatusSink(static function (string $message, bool $ready) use ($status): void {
    $status->setText($message);
    $status->setStyleSheet($ready
        ? ''
        : 'QLabel#status { background: rgba(56, 14, 14, 230); border: 1px solid #c85d5d; color: #ffe8e8; padding: 12px 14px; border-radius: 16px; }');
});

$syncIntensity = static function (int $value) use ($glWidget, $intensityRow): void {
    $float = $value / 100.0;
    $intensityRow['value']->setText(number_format($float, 2));
    $glWidget->setIntensity($float);
};
$syncScale = static function (int $value) use ($glWidget, $scaleRow): void {
    $float = $value / 100.0;
    $scaleRow['value']->setText(number_format($float, 2));
    $glWidget->setScale($float);
};
$syncHue = static function (int $value) use ($glWidget, $hueRow): void {
    $float = ($value - 50) / 100.0;
    $hueRow['value']->setText(sprintf('%+.2f', $float));
    $glWidget->setHueShift($float);
};

$intensityRow['slider']->onValueChanged($syncIntensity);
$scaleRow['slider']->onValueChanged($syncScale);
$hueRow['slider']->onValueChanged($syncHue);

$syncIntensity($intensityRow['slider']->value());
$syncScale($scaleRow['slider']->value());
$syncHue($hueRow['slider']->value());

$presetPrimary->onClicked(static function () use ($glWidget, $intensityRow, $scaleRow, $hueRow, $syncIntensity, $syncScale, $syncHue): void {
    $intensityRow['slider']->setValue(168);
    $scaleRow['slider']->setValue(118);
    $hueRow['slider']->setValue(74);
    $syncIntensity($intensityRow['slider']->value());
    $syncScale($scaleRow['slider']->value());
    $syncHue($hueRow['slider']->value());
    $glWidget->applyPreset('neon');
});
$presetSecondary->onClicked(static function () use ($glWidget, $intensityRow, $scaleRow, $hueRow, $syncIntensity, $syncScale, $syncHue): void {
    $intensityRow['slider']->setValue(82);
    $scaleRow['slider']->setValue(72);
    $hueRow['slider']->setValue(42);
    $syncIntensity($intensityRow['slider']->value());
    $syncScale($scaleRow['slider']->value());
    $syncHue($hueRow['slider']->value());
    $glWidget->applyPreset('calm');
});

$shellLayout->addWidget($glWidget, 1);
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

example_line('opengl shader playground ready');
QApplication::exec();
