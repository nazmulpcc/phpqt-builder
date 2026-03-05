<?php

declare(strict_types=1);

namespace Examples\Support\Theme;

use Qt\Widgets\QApplication;
use Qt\Widgets\QWidget;

final class WidgetTheme
{
    public static function apply(QWidget $window, string $mode = 'dark'): void
    {
        $mode = $mode === 'light' ? 'light' : 'dark';
        $window->setStyleSheet(self::styleSheet($mode));

        if (method_exists(QApplication::class, 'setApplicationDisplayName')) {
            QApplication::setApplicationDisplayName('PHP Qt Builder Examples');
        }
    }

    public static function styleSheet(string $mode = 'dark'): string
    {
        if ($mode === 'light') {
            return <<<'CSS'
QWidget {
    background: #f5f7fb;
    color: #13233a;
    font-size: 14px;
}
QFrame#appCard, QWidget#appCard {
    background: #ffffff;
    border: 1px solid #d8e1f0;
    border-radius: 16px;
}
QLineEdit, QTextEdit, QTextBrowser, QPlainTextEdit, QComboBox, QSpinBox, QTableView, QListWidget, QTabWidget::pane {
    background: #ffffff;
    border: 1px solid #cdd7ea;
    border-radius: 10px;
    padding: 6px 8px;
}
QPushButton {
    background: #1e5eff;
    color: #ffffff;
    border: none;
    border-radius: 10px;
    padding: 8px 14px;
    font-weight: 600;
}
QPushButton[variant="secondary"] {
    background: #edf2fb;
    color: #173057;
}
QPushButton[variant="danger"] {
    background: #d64545;
    color: #ffffff;
}
QLabel[role="caption"] {
    color: #61748f;
}
QLabel[role="heading"] {
    font-size: 22px;
    font-weight: 700;
    color: #0b1f3a;
}
QLabel[role="subheading"] {
    color: #48617f;
}
CSS;
        }

        return <<<'CSS'
QWidget {
    background: #0d1526;
    color: #f3f7ff;
    font-size: 14px;
}
QFrame#appCard, QWidget#appCard {
    background: #111d33;
    border: 1px solid #22324d;
    border-radius: 16px;
}
QLineEdit, QTextEdit, QTextBrowser, QPlainTextEdit, QComboBox, QSpinBox, QTableView, QListWidget, QTabWidget::pane {
    background: #16233b;
    border: 1px solid #2c4064;
    border-radius: 10px;
    padding: 6px 8px;
}
QPushButton {
    background: #2d72ff;
    color: #ffffff;
    border: none;
    border-radius: 10px;
    padding: 8px 14px;
    font-weight: 600;
}
QPushButton[variant="secondary"] {
    background: #1c2d49;
    color: #dfe9ff;
}
QPushButton[variant="danger"] {
    background: #d15858;
    color: #ffffff;
}
QLabel[role="caption"] {
    color: #8fa5c3;
}
QLabel[role="heading"] {
    font-size: 22px;
    font-weight: 700;
    color: #ffffff;
}
QLabel[role="subheading"] {
    color: #aebfd9;
}
CSS;
    }
}
