<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Qt\Core\QTimer;
use Qt\Gui\QColor;
use Qt\Widgets\QApplication;
use Qt\Widgets\QGraphicsDropShadowEffect;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

/**
 * @return array{widget: QWidget, shadow: QGraphicsDropShadowEffect, x: int, y: int, w: int, h: int, phase: float, amp_x: int, amp_y: int, depth_bias: float}
 */
function depth_card(
    QWidget $stage,
    string $title,
    string $body,
    string $accent,
    int $x,
    int $y,
    int $w,
    int $h,
    float $phase,
    int $ampX,
    int $ampY,
    float $depthBias,
): array {
    $card = new QWidget($stage);
    $card->setObjectName('depthCard');
    $card->setProperty('accent', $accent);
    $card->setGeometry($x, $y, $w, $h);

    $shadow = new QGraphicsDropShadowEffect($card);
    $shadow->setBlurRadius(34.0 + ($depthBias * 8.0));
    $shadow->setOffset(0.0, 16.0 + ($depthBias * 5.0));
    $shadow->setColor(new QColor(0, 0, 0, 180));
    $card->setGraphicsEffect($shadow);

    $layout = new QVBoxLayout();
    $layout->setContentsMargins(18, 16, 18, 16);
    $layout->setSpacing(10);

    $tag = new QLabel(strtoupper($accent));
    $tag->setProperty('role', 'tag');
    $titleLabel = new QLabel($title);
    $titleLabel->setProperty('role', 'cardTitle');
    $bodyLabel = new QLabel($body);
    $bodyLabel->setWordWrap(true);
    $bodyLabel->setProperty('role', 'cardBody');

    $layout->addWidget($tag);
    $layout->addWidget($titleLabel);
    $layout->addWidget($bodyLabel);
    $layout->addStretch(1);

    $card->setLayout($layout);

    return [
        'widget' => $card,
        'shadow' => $shadow,
        'x' => $x,
        'y' => $y,
        'w' => $w,
        'h' => $h,
        'phase' => $phase,
        'amp_x' => $ampX,
        'amp_y' => $ampY,
        'depth_bias' => $depthBias,
    ];
}

example_section('Depth Playground');

$app = new QApplication();
$window = new QWidget();
$window->setWindowTitle('Depth Playground | 2.5D demo');
$window->setFixedSize(980, 640);
$window->setStyleSheet(<<<'CSS'
QWidget {
    background: #0a0716;
    color: #f8fbff;
    font-family: "Avenir Next", "Segoe UI", sans-serif;
}
QWidget#shell {
    background: qlineargradient(x1:0, y1:0, x2:1, y2:1,
        stop:0 #1c1140,
        stop:0.5 #16234f,
        stop:1 #0f2f46);
    border: 1px solid #3656a8;
    border-radius: 26px;
}
QWidget#stage {
    background: qradialgradient(cx:0.28, cy:0.2, radius:1.4,
        stop:0 #2f2a6a,
        stop:0.45 #1b2c58,
        stop:1 #11243b);
    border: 1px solid #3e5fb0;
    border-radius: 20px;
}
QWidget#depthCard {
    background: #1d2a44;
    border-radius: 18px;
    border: 1px solid #4a67a1;
}
QWidget[accent="aurora"] {
    background: qlineargradient(x1:0, y1:0, x2:1, y2:1,
        stop:0 #2e4b9a,
        stop:1 #315dbe);
}
QWidget[accent="solar"] {
    background: qlineargradient(x1:0, y1:0, x2:1, y2:1,
        stop:0 #ad5a10,
        stop:1 #ea821b);
}
QWidget[accent="nova"] {
    background: qlineargradient(x1:0, y1:0, x2:1, y2:1,
        stop:0 #6c2c9d,
        stop:1 #9542d4);
}
QLabel#title {
    font-size: 34px;
    font-weight: 700;
}
QLabel#subtitle {
    color: #c0d7ff;
    font-size: 16px;
}
QLabel[role="tag"] {
    color: #fff1a6;
    font-weight: 700;
    letter-spacing: 1px;
}
QLabel[role="cardTitle"] {
    font-size: 24px;
    font-weight: 650;
}
QLabel[role="cardBody"] {
    color: #ebf2ff;
    font-size: 15px;
}
QLabel#hint {
    color: #bed5ff;
    font-size: 14px;
}
QPushButton {
    background: #14a0ff;
    border: 1px solid #4bc0ff;
    border-radius: 13px;
    color: #061422;
    padding: 8px 16px;
    font-size: 15px;
    font-weight: 600;
}
QPushButton[variant="secondary"] {
    background: #2d2d73;
    border: 1px solid #6a67d2;
    color: #eef0ff;
}
QPushButton:hover {
    background: #45b9ff;
}
QPushButton[variant="secondary"]:hover {
    background: #3b3ba0;
}
CSS);

$shell = new QWidget($window);
$shell->setObjectName('shell');
$shell->setGeometry(18, 18, 944, 604);

$root = new QVBoxLayout();
$root->setContentsMargins(22, 20, 22, 20);
$root->setSpacing(14);

$title = new QLabel('Depth Playground');
$title->setObjectName('title');
$subtitle = new QLabel('A 2.5D scene in pure Qt Widgets: layered cards, animated depth, and soft parallax drift.');
$subtitle->setObjectName('subtitle');

$stage = new QWidget();
$stage->setObjectName('stage');
$stage->setFixedSize(900, 430);

$cards = [];
$cards[] = depth_card(
    $stage,
    'Aurora Deck',
    'Foreground card with electric blues and aggressive motion to sell near-camera depth.',
    'aurora',
    78,
    182,
    300,
    188,
    0.2,
    13,
    10,
    1.0,
);
$cards[] = depth_card(
    $stage,
    'Solar Span',
    'Mid layer in warm orange, crossing behind the front card for a richer parallax read.',
    'solar',
    310,
    112,
    320,
    196,
    1.7,
    10,
    8,
    0.65,
);
$cards[] = depth_card(
    $stage,
    'Nova Grid',
    'Back layer in neon violet: softer drift and gentler blur to suggest distance.',
    'nova',
    600,
    62,
    250,
    170,
    3.0,
    8,
    6,
    0.35,
);

foreach ($cards as $card) {
    $card['widget']->show();
}

$hint = new QLabel('Try: Pulse Focus exaggerates perspective, Calm Drift relaxes movement.');
$hint->setObjectName('hint');

$toggle = new QPushButton('Pulse Focus');
$reset = new QPushButton('Calm Drift');
$reset->setProperty('variant', 'secondary');

$controls = new QHBoxLayout();
$controls->setSpacing(10);
$controls->addWidget($toggle);
$controls->addWidget($reset);
$controls->addStretch(1);

$root->addWidget($title);
$root->addWidget($subtitle);
$root->addWidget($stage);
$root->addWidget($hint);
$root->addLayout($controls);
$shell->setLayout($root);

$intensity = 1.0;
$toggle->onClicked(static function () use (&$intensity, $hint): void {
    $intensity = 1.85;
    $hint->setText('Pulse mode active: larger motion arcs and deeper shadows.');
});
$reset->onClicked(static function () use (&$intensity, $hint): void {
    $intensity = 1.0;
    $hint->setText('Calm drift active: subtle ambient movement.');
});

$timer = new QTimer($window);
$timer->setInterval(16);
$start = microtime(true);
$timer->connect('timeout()', static function () use (&$cards, &$intensity, $start): void {
    $t = (microtime(true) - $start);

    $depthOrder = [];

    foreach ($cards as $idx => $card) {
        $phase = (float) $card['phase'];
        $bias = (float) $card['depth_bias'];
        $osc = sin(($t * 1.05) + $phase);
        $drift = cos(($t * 0.75) + ($phase * 1.35));

        $depth = ($osc * 0.5 + 0.5) * $bias;
        $x = (int) round((int) $card['x'] + ($osc * (int) $card['amp_x'] * $intensity));
        $y = (int) round((int) $card['y'] + ($drift * (int) $card['amp_y'] * $intensity));

        $shadow = $card['shadow'];
        $shadow->setBlurRadius(24.0 + (26.0 * $depth * $intensity));
        $shadow->setYOffset(9.0 + (16.0 * $depth * $intensity));

        $card['widget']->move($x, $y);
        $depthOrder[] = ['widget' => $card['widget'], 'z' => $depth + $bias];
    }

    usort($depthOrder, static fn(array $a, array $b): int => $a['z'] <=> $b['z']);
    foreach ($depthOrder as $item) {
        $item['widget']->raise();
    }
});
$timer->start();

$window->show();
example_line('depth playground ready');
QApplication::exec();
