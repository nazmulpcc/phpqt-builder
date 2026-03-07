<?php

declare(strict_types=1);

use Qt\Orientation;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QSlider;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

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

function playground_stylesheet(): string
{
    return <<<'CSS'
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
CSS;
}
