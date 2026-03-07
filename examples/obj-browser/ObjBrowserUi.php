<?php

declare(strict_types=1);

use Qt\Widgets\QLabel;

function obj_browser_stylesheet(): string
{
    return <<<'CSS'
QWidget {
    background: #07111d;
    color: #f5f8ff;
    font-family: "Avenir Next", "Segoe UI", sans-serif;
}
QWidget#shell {
    background: qlineargradient(x1:0, y1:0, x2:1, y2:1,
        stop:0 #0a1828,
        stop:0.52 #090f1b,
        stop:1 #120d18);
}
QWidget#sidePanel {
    background: rgba(11, 20, 34, 220);
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
QLabel[role="infoKey"] {
    color: #8fb3d0;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1px;
}
QLabel[role="infoValue"] {
    background: rgba(10, 23, 38, 190);
    border: 1px solid #244766;
    border-radius: 12px;
    color: #e9f3ff;
    padding: 10px 12px;
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
QPushButton[variant="secondary"] {
    background: qlineargradient(x1:0, y1:0, x2:1, y2:1,
        stop:0 #2a3046,
        stop:1 #45365d);
    border: 1px solid #aa9fe0;
    color: #f5f0ff;
}
QPushButton:hover {
    background: #41d1ff;
}
CSS;
}

function obj_browser_info_value(string $text): QLabel
{
    $label = new QLabel($text);
    $label->setProperty('role', 'infoValue');
    $label->setWordWrap(true);

    return $label;
}

