<?php

declare(strict_types=1);

use Qt\Gui\QMatrix4x4;
use Qt\Gui\QMouseEvent;
use Qt\Gui\QOpenGLContext;
use Qt\Gui\QVector3D;
use Qt\Gui\QWheelEvent;
use Qt\OpenGL\QOpenGLBuffer;
use Qt\OpenGL\QOpenGLFunctions_2_0;
use Qt\OpenGL\QOpenGLShader;
use Qt\OpenGL\QOpenGLShaderProgram;
use Qt\OpenGL\QOpenGLVertexArrayObject;
use Qt\OpenGLWidgets\QOpenGLWidget;
use Qt\Widgets\QWidget;

final class ObjBrowserWidget extends QOpenGLWidget
{
    private const int GL_COLOR_BUFFER_BIT = 0x00004000;
    private const int GL_DEPTH_BUFFER_BIT = 0x00000100;
    private const int GL_FLOAT = 0x1406;
    private const int GL_TRIANGLES = 0x0004;
    private const int GL_DEPTH_TEST = 0x0B71;
    private const int GL_CULL_FACE = 0x0B44;
    private const int GL_BACK = 0x0405;
    private const int GL_CCW = 0x0901;
    private const int GL_LESS = 0x0201;
    private const int GL_TEXTURE0 = 0x84C0;
    private const int GL_TEXTURE_2D = 0x0DE1;
    private const int GL_TEXTURE_MIN_FILTER = 0x2801;
    private const int GL_TEXTURE_MAG_FILTER = 0x2800;
    private const int GL_TEXTURE_WRAP_S = 0x2802;
    private const int GL_TEXTURE_WRAP_T = 0x2803;
    private const int GL_LINEAR = 0x2601;
    private const int GL_REPEAT = 0x2901;
    private const int GL_RGBA = 0x1908;
    private const int GL_UNSIGNED_BYTE = 0x1401;

    private ?QOpenGLFunctions_2_0 $gl = null;
    private ?QOpenGLShaderProgram $program = null;
    private ?QOpenGLBuffer $vertexBuffer = null;
    private ?QOpenGLVertexArrayObject $vertexArray = null;
    /** @var list<?int> */
    private array $materialTextures = [];
    /** @var array<string, mixed>|null */
    private ?array $scene = null;
    /** @var array<string, mixed> */
    private array $sceneSummary = [
        'label' => 'No model loaded',
        'path' => '',
        'vertex_count' => 0,
        'triangle_count' => 0,
        'material_count' => 0,
        'warnings' => [],
    ];
    private bool $sceneUploaded = false;
    private bool $shaderReady = false;
    private bool $useVertexArray = false;
    private string $statusMessage = 'OpenGL viewer booting...';
    private $statusSink = null;
    private int $viewportWidth = 1;
    private int $viewportHeight = 1;
    private bool $dragging = false;
    /** @var array{x: int, y: int}|null */
    private ?array $lastPointer = null;
    private float $yawDegrees = -32.0;
    private float $pitchDegrees = 22.0;
    private float $distance = 4.0;
    /** @var array{float, float, float} */
    private array $target;

    public function __construct(?QWidget $parent = null)
    {
        parent::__construct($parent);

        $this->target = [0.0, 0.0, 0.0];
        $this->setMouseTracking(true);
        $this->setUpdateBehavior(self::PartialUpdate);
    }

    public function setStatusSink(callable $sink): void
    {
        $this->statusSink = $sink;
        $sink($this->statusMessage, $this->shaderReady);
    }

    public function sceneSummary(): array
    {
        return $this->sceneSummary;
    }

    public function isSceneReady(): bool
    {
        return $this->shaderReady && $this->sceneUploaded;
    }

    public function statusMessage(): string
    {
        return $this->statusMessage;
    }

    public function shutdown(): void
    {
        if ($this->gl instanceof QOpenGLFunctions_2_0) {
            $this->makeCurrent();
            $this->releaseSceneResources();
            $this->doneCurrent();
        }

        $this->scene = null;
    }

    public function loadModel(string $objPath): bool
    {
        try {
            $scene = ObjLoader::load($objPath);
        } catch (\Throwable $exception) {
            $this->setStatus($exception->getMessage(), false);
            return false;
        }

        $this->scene = $scene;
        $this->sceneSummary = [
            'label' => $scene['label'],
            'path' => $scene['path'],
            'vertex_count' => $scene['vertex_count'],
            'triangle_count' => $scene['triangle_count'],
            'material_count' => count($scene['materials']),
            'warnings' => $scene['warnings'],
        ];
        $this->resetView();

        if ($this->gl instanceof QOpenGLFunctions_2_0 && $this->shaderReady) {
            $this->makeCurrent();
            $this->rebuildSceneResources();
            $this->doneCurrent();
        }

        $this->setStatus($this->loadedStatusMessage(), true);
        $this->update();

        return true;
    }

    public function resetView(): void
    {
        if ($this->scene !== null) {
            $center = $this->scene['bounds']['center'];
            $this->target = [$center[0], $center[1], $center[2]];
            $this->distance = max(1.5, $this->scene['bounds']['radius'] * 2.8);
        } else {
            $this->target = [0.0, 0.0, 0.0];
            $this->distance = 4.0;
        }

        $this->yawDegrees = -32.0;
        $this->pitchDegrees = 22.0;
        $this->update();
    }

    protected function initializeGL(): void
    {
        $this->makeCurrent();

        $this->gl = new QOpenGLFunctions_2_0();
        if (!$this->gl->initializeOpenGLFunctions()) {
            $this->setStatus('OpenGL 2.0 function table initialization failed.', false);
            return;
        }

        $context = $this->context();
        if (!$context instanceof QOpenGLContext || !$context->isValid()) {
            $this->setStatus('OpenGL context is invalid.', false);
            return;
        }

        $program = new QOpenGLShaderProgram($this);
        if (!$program->addShaderFromSourceFile(QOpenGLShader::Vertex, __DIR__ . '/shader.vert')) {
            $this->setStatus('Vertex shader compilation failed: ' . trim($program->log()), false);
            return;
        }

        if (!$program->addShaderFromSourceFile(QOpenGLShader::Fragment, __DIR__ . '/shader.frag')) {
            $this->setStatus('Fragment shader compilation failed: ' . trim($program->log()), false);
            return;
        }

        $program->bindAttributeLocation('a_position', 0);
        $program->bindAttributeLocation('a_normal', 1);
        $program->bindAttributeLocation('a_uv', 2);

        if (!$program->link()) {
            $this->setStatus('Shader link failed: ' . trim($program->log()), false);
            return;
        }

        $this->program = $program;
        $this->shaderReady = true;

        $this->gl->glEnable(self::GL_DEPTH_TEST);
        $this->gl->glDepthFunc(self::GL_LESS);
        $this->gl->glEnable(self::GL_CULL_FACE);
        $this->gl->glCullFace(self::GL_BACK);
        $this->gl->glFrontFace(self::GL_CCW);

        $this->rebuildSceneResources();
        if ($this->scene !== null) {
            $this->setStatus($this->loadedStatusMessage(), true);
        } else {
            $this->setStatus('OpenGL ready. Load an OBJ model to begin.', true);
        }
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
            || !$this->sceneUploaded
            || !$this->gl instanceof QOpenGLFunctions_2_0
            || !$this->program instanceof QOpenGLShaderProgram
            || $this->scene === null
        ) {
            return;
        }

        $this->gl->glViewport(0, 0, $this->viewportWidth, $this->viewportHeight);
        $this->gl->glClearColor(0.035, 0.045, 0.07, 1.0);
        $this->gl->glClear(self::GL_COLOR_BUFFER_BIT | self::GL_DEPTH_BUFFER_BIT);

        if (!$this->program->bind()) {
            $this->setStatus('Shader program bind failed: ' . trim($this->program->log()), false);
            return;
        }

        $model = new QMatrix4x4();
        $model->setToIdentity();

        $view = new QMatrix4x4();
        $eye = $this->cameraEye();
        $view->lookAt($eye, new QVector3D($this->target[0], $this->target[1], $this->target[2]), new QVector3D(0.0, 1.0, 0.0));

        $projection = new QMatrix4x4();
        $projection->setToIdentity();
        $projection->perspective(45.0, $this->viewportWidth / max(1.0, (float) $this->viewportHeight), 0.05, max(50.0, $this->distance * 6.0));

        $this->program->setUniformValue('u_model', $model);
        $this->program->setUniformValue('u_view', $view);
        $this->program->setUniformValue('u_projection', $projection);
        $this->program->setUniformValue('u_lightDir', 0.45, 0.75, 0.35);
        $this->program->setUniformValue('u_diffuseMap', 0);

        if ($this->useVertexArray && $this->vertexArray instanceof QOpenGLVertexArrayObject) {
            $this->vertexArray->bind();
        } else {
            $this->configureAttributes();
        }

        foreach ($this->scene['batches'] as $batch) {
            $material = $this->scene['materials'][$batch['material_index']];
            $texture = $this->materialTextures[$batch['material_index']] ?? null;
            $useTexture = is_int($texture) && $texture > 0;

            $this->program->setUniformValue(
                'u_baseColor',
                (float) $material['diffuse'][0],
                (float) $material['diffuse'][1],
                (float) $material['diffuse'][2],
                (float) $material['alpha']
            );
            $this->program->setUniformValue('u_useTexture', $useTexture ? 1 : 0);

            if ($useTexture) {
                $this->gl->glActiveTexture(self::GL_TEXTURE0);
                $this->gl->glBindTexture(self::GL_TEXTURE_2D, $texture);
            }

            $this->gl->glDrawArrays(self::GL_TRIANGLES, $batch['first_vertex'], $batch['vertex_count']);

            if ($useTexture) {
                $this->gl->glBindTexture(self::GL_TEXTURE_2D, 0);
            }
        }

        if ($this->useVertexArray && $this->vertexArray instanceof QOpenGLVertexArrayObject) {
            $this->vertexArray->release();
        } elseif ($this->vertexBuffer instanceof QOpenGLBuffer) {
            $this->vertexBuffer->release();
        }

        $this->program->release();
    }

    protected function mousePressEvent(QMouseEvent $event): void
    {
        if ($event->button() === 1) {
            $this->dragging = true;
            $this->lastPointer = ['x' => $event->x(), 'y' => $event->y()];
        }

        parent::mousePressEvent($event);
    }

    protected function mouseReleaseEvent(QMouseEvent $event): void
    {
        if ($event->button() === 1) {
            $this->dragging = false;
        }

        parent::mouseReleaseEvent($event);
    }

    protected function mouseMoveEvent(QMouseEvent $event): void
    {
        $this->handleMouseMove($event);
        parent::mouseMoveEvent($event);
    }

    protected function wheelEvent(QWheelEvent $event): void
    {
        $delta = $event->angleDelta()->y();
        if ($delta !== 0) {
            $this->distance = max(0.6, $this->distance * ($delta > 0 ? 0.9 : 1.1));
            $this->update();
        }

        parent::wheelEvent($event);
    }

    private function handleMouseMove(QMouseEvent $event): void
    {
        if (!$this->dragging || $this->lastPointer === null) {
            return;
        }

        $deltaX = $event->x() - $this->lastPointer['x'];
        $deltaY = $event->y() - $this->lastPointer['y'];
        $this->lastPointer = ['x' => $event->x(), 'y' => $event->y()];

        $this->yawDegrees += $deltaX * 0.65;
        $this->pitchDegrees = max(-85.0, min(85.0, $this->pitchDegrees - ($deltaY * 0.45)));
        $this->update();
    }

    private function rebuildSceneResources(): void
    {
        $this->releaseSceneResources();

        if (
            !$this->shaderReady
            || !$this->program instanceof QOpenGLShaderProgram
            || $this->scene === null
        ) {
            $this->sceneUploaded = false;
            return;
        }

        $vertexBuffer = new QOpenGLBuffer(QOpenGLBuffer::VertexBuffer);
        $vertexBuffer->setUsagePattern(QOpenGLBuffer::StaticDraw);
        if (!$vertexBuffer->create() || !$vertexBuffer->bind()) {
            $this->setStatus('Vertex buffer creation failed.', false);
            return;
        }

        $packed = pack('f*', ...$this->scene['vertex_floats']);
        $vertexBuffer->allocate($packed, strlen($packed));

        $vertexArray = new QOpenGLVertexArrayObject($this);
        $this->useVertexArray = $vertexArray->create();

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
        $textureResult = $this->createMaterialTextures($this->scene['materials']);
        $this->materialTextures = $textureResult['textures'];
        $this->sceneSummary['warnings'] = array_values(array_unique(array_merge(
            $this->scene['warnings'],
            $textureResult['warnings']
        )));
        $this->sceneUploaded = true;
    }

    /**
     * @param list<array{name: string, diffuse: array{float, float, float}, alpha: float, texture_path: ?string}> $materials
     * @return array{textures: list<?int>, warnings: list<string>}
     */
    private function createMaterialTextures(array $materials): array
    {
        $textures = [];
        $warnings = [];

        foreach ($materials as $material) {
            $texturePath = $material['texture_path'];
            if (!is_string($texturePath) || $texturePath === '' || !is_file($texturePath)) {
                $textures[] = null;
                continue;
            }

            $decoded = $this->decodeTextureImage($texturePath);
            if ($decoded === null) {
                $warnings[] = sprintf('Unable to decode texture image: %s', basename($texturePath));
                $textures[] = null;
                continue;
            }

            $textureId = 0;
            $this->gl?->glGenTextures(1, $textureId);
            if ($textureId <= 0) {
                $warnings[] = sprintf('Failed to create texture for %s', basename($texturePath));
                $textures[] = null;
                continue;
            }

            $this->gl?->glActiveTexture(self::GL_TEXTURE0);
            $this->gl?->glBindTexture(self::GL_TEXTURE_2D, $textureId);
            $this->gl?->glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_MIN_FILTER, self::GL_LINEAR);
            $this->gl?->glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_MAG_FILTER, self::GL_LINEAR);
            $this->gl?->glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_WRAP_S, self::GL_REPEAT);
            $this->gl?->glTexParameteri(self::GL_TEXTURE_2D, self::GL_TEXTURE_WRAP_T, self::GL_REPEAT);
            $this->gl?->glTexImage2D(
                self::GL_TEXTURE_2D,
                0,
                self::GL_RGBA,
                $decoded['width'],
                $decoded['height'],
                0,
                self::GL_RGBA,
                self::GL_UNSIGNED_BYTE,
                $decoded['pixels']
            );
            $this->gl?->glBindTexture(self::GL_TEXTURE_2D, 0);

            $textures[] = $textureId;
        }

        return [
            'textures' => $textures,
            'warnings' => $warnings,
        ];
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

        $stride = 32;
        $this->program->enableAttributeArray(0);
        $this->program->setAttributeBuffer(0, self::GL_FLOAT, 0, 3, $stride);
        $this->program->enableAttributeArray(1);
        $this->program->setAttributeBuffer(1, self::GL_FLOAT, 12, 3, $stride);
        $this->program->enableAttributeArray(2);
        $this->program->setAttributeBuffer(2, self::GL_FLOAT, 24, 2, $stride);
    }

    private function releaseSceneResources(): void
    {
        $textureIds = array_values(array_filter($this->materialTextures, static fn (mixed $texture): bool => is_int($texture) && $texture > 0));
        if ($textureIds !== [] && $this->gl instanceof QOpenGLFunctions_2_0) {
            $this->gl->glDeleteTextures(count($textureIds), $textureIds);
        }
        $this->materialTextures = [];

        if ($this->vertexArray instanceof QOpenGLVertexArrayObject && $this->vertexArray->isCreated()) {
            $this->vertexArray->destroy();
        }
        $this->vertexArray = null;

        if ($this->vertexBuffer instanceof QOpenGLBuffer && $this->vertexBuffer->isCreated()) {
            $this->vertexBuffer->destroy();
        }
        $this->vertexBuffer = null;
        $this->useVertexArray = false;
        $this->sceneUploaded = false;
    }

    /**
     * @return array{width: int, height: int, pixels: string}|null
     */
    private function decodeTextureImage(string $texturePath): ?array
    {
        // The ideal Qt path here is QImage/QOpenGLTexture, but that wrapper path
        // is still unstable in this environment. Decode via GD and upload raw
        // RGBA bytes so the example remains runnable.
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $bytes = file_get_contents($texturePath);
        if ($bytes === false) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $pixels = '';

        for ($y = $height - 1; $y >= 0; $y--) {
            for ($x = 0; $x < $width; $x++) {
                $argb = imagecolorat($image, $x, $y);
                $red = ($argb >> 16) & 0xFF;
                $green = ($argb >> 8) & 0xFF;
                $blue = $argb & 0xFF;
                $alpha = ($argb & 0x7F000000) >> 24;
                $alphaByte = (int) round((127 - $alpha) * (255 / 127));
                $pixels .= chr($red) . chr($green) . chr($blue) . chr($alphaByte);
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'pixels' => $pixels,
        ];
    }

    private function cameraEye(): QVector3D
    {
        $yaw = deg2rad($this->yawDegrees);
        $pitch = deg2rad($this->pitchDegrees);
        $cosPitch = cos($pitch);

        return new QVector3D(
            $this->target[0] + ($this->distance * $cosPitch * sin($yaw)),
            $this->target[1] + ($this->distance * sin($pitch)),
            $this->target[2] + ($this->distance * $cosPitch * cos($yaw))
        );
    }

    private function loadedStatusMessage(): string
    {
        if ($this->scene === null) {
            return 'OpenGL ready. Load an OBJ model to begin.';
        }

        $warningCount = count($this->scene['warnings']);

        return sprintf(
            'Loaded %s · %d triangles · %d materials%s',
            $this->scene['label'],
            $this->scene['triangle_count'],
            count($this->scene['materials']),
            $warningCount > 0 ? sprintf(' · %d warning%s', $warningCount, $warningCount === 1 ? '' : 's') : ''
        );
    }

    private function setStatus(string $message, bool $ready): void
    {
        $this->statusMessage = $message;
        if (is_callable($this->statusSink)) {
            ($this->statusSink)($message, $ready);
        }
    }
}
