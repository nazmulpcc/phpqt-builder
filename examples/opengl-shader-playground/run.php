<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';
require __DIR__ . '/ShaderPlaygroundWidget.php';
require __DIR__ . '/PlaygroundUi.php';

use Qt\Core\QCoreApplication;
use Qt\Core\QTimer;
use Qt\Gui\QSurfaceFormat;
use Qt\Widgets\QApplication;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

example_section('OpenGL Shader Playground');

QCoreApplication::setApplicationName('OpenGL Shader Playground');

$app = new QApplication();
$window = new QWidget();
$window->setWindowTitle('OpenGL Shader Playground');
$window->setMinimumSize(1180, 760);
$window->setStyleSheet(playground_stylesheet());

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
$pointerDebug = new QLabel('Pointer: waiting for mouse movement');
$pointerDebug->setObjectName('status');
$pointerDebug->setWordWrap(true);

$hint = new QLabel('Move or click inside the canvas to trigger a tight ripple around the pointer. Presets retune the same shader, not separate scenes.');
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
$sideLayout->addWidget($pointerDebug);
$sideLayout->addWidget($hint);
$sideLayout->addStretch(1);

$glWidget->setStatusSink(static function (string $message, bool $ready) use ($status): void {
    $status->setText($message);
    $status->setStyleSheet($ready
        ? ''
        : 'QLabel#status { background: rgba(56, 14, 14, 230); border: 1px solid #c85d5d; color: #ffe8e8; padding: 12px 14px; border-radius: 16px; }');
});
$glWidget->setPointerSink(static function (int $x, int $y, float $u, float $v) use ($pointerDebug): void {
    $pointerDebug->setText(sprintf(
        'Pointer px: %d, %d' . PHP_EOL . 'Pointer uv: %.3f, %.3f',
        $x,
        $y,
        $u,
        $v
    ));
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
