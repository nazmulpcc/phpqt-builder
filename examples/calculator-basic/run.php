<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Qt\Widgets\QApplication;
use Qt\Widgets\QGridLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;
use Qt\Gui\QKeySequence;
use Qt\Gui\QShortcut;

final class CalculatorEngine
{
    private string $display = '0';
    private ?float $stored = null;
    private string $pendingOperator = '';
    private bool $freshInput = true;
    private string $topLine = '';
    private bool $error = false;

    public function clear(): void
    {
        $this->display = '0';
        $this->stored = null;
        $this->pendingOperator = '';
        $this->freshInput = true;
        $this->topLine = '';
        $this->error = false;
    }

    public function inputDigit(string $digit): void
    {
        if ($this->error) {
            $this->clear();
        }

        if ($this->freshInput) {
            $this->display = $digit;
            $this->freshInput = false;
            return;
        }

        if ($this->display === '0') {
            $this->display = $digit;
            return;
        }

        $this->display .= $digit;
    }

    public function inputDot(): void
    {
        if ($this->error) {
            $this->clear();
        }

        if ($this->freshInput) {
            $this->display = '0.';
            $this->freshInput = false;
            return;
        }

        if (!str_contains($this->display, '.')) {
            $this->display .= '.';
        }
    }

    public function toggleSign(): void
    {
        if ($this->error || $this->display === '0') {
            return;
        }

        if (str_starts_with($this->display, '-')) {
            $this->display = substr($this->display, 1);
            return;
        }

        $this->display = '-' . $this->display;
    }

    public function percent(): void
    {
        if ($this->error) {
            return;
        }

        $value = $this->displayValue();
        if ($this->stored !== null && $this->pendingOperator !== '') {
            $value = $this->stored * ($value / 100.0);
        } else {
            $value /= 100.0;
        }

        $this->display = $this->formatNumber($value);
        $this->freshInput = true;
    }

    public function setOperator(string $operator): void
    {
        if ($this->error) {
            return;
        }

        $value = $this->displayValue();

        if ($this->stored === null) {
            $this->stored = $value;
        } elseif (!$this->freshInput && $this->pendingOperator !== '') {
            $result = $this->calculate($this->stored, $value, $this->pendingOperator);
            if ($result === null) {
                $this->setError();
                return;
            }
            $this->stored = $result;
            $this->display = $this->formatNumber($result);
        }

        $this->pendingOperator = $operator;
        $this->topLine = $this->formatNumber((float) $this->stored) . ' ' . $operator;
        $this->freshInput = true;
    }

    public function evaluate(): void
    {
        if ($this->error || $this->stored === null || $this->pendingOperator === '') {
            return;
        }

        $value = $this->displayValue();
        $result = $this->calculate($this->stored, $value, $this->pendingOperator);
        if ($result === null) {
            $this->setError();
            return;
        }

        $this->topLine = sprintf('%s %s %s =', $this->formatNumber($this->stored), $this->pendingOperator, $this->formatNumber($value));
        $this->display = $this->formatNumber($result);
        $this->stored = null;
        $this->pendingOperator = '';
        $this->freshInput = true;
    }

    public function displayText(): string
    {
        return $this->display;
    }

    public function topLineText(): string
    {
        return $this->topLine;
    }

    private function displayValue(): float
    {
        return (float) $this->display;
    }

    private function setError(): void
    {
        $this->display = 'Error';
        $this->topLine = '';
        $this->stored = null;
        $this->pendingOperator = '';
        $this->freshInput = true;
        $this->error = true;
    }

    private function calculate(float $left, float $right, string $operator): ?float
    {
        return match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '×' => $left * $right,
            '÷' => abs($right) < 1e-12 ? null : $left / $right,
            default => $right,
        };
    }

    private function formatNumber(float $value): string
    {
        if (!is_finite($value)) {
            return 'Error';
        }

        if (abs($value) < 1e-12) {
            return '0';
        }

        $text = sprintf('%.12f', $value);
        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}

/**
 * @param callable():void $onClick
 */
function calculator_button(string $text, string $role, callable $onClick): QPushButton
{
    $button = new QPushButton($text);
    $button->setMinimumSize(66, 56);
    $button->setProperty('role', $role);
    $button->onClicked($onClick);

    return $button;
}

/**
 * @param list<QShortcut> $shortcuts
 * @param callable():void $handler
 */
function calculator_bind_shortcut(array &$shortcuts, QWidget $window, string $sequence, callable $handler): void
{
    $shortcut = new QShortcut($window);
    $shortcut->setKey(new QKeySequence($sequence));
    $shortcut->connect('activated()', static function () use ($handler): void {
        $handler();
    });
    $shortcuts[] = $shortcut;
}

example_section('Calculator (macOS-like)');

$app = new QApplication();
$window = new QWidget();
$window->setWindowTitle('Calculator');
$window->setFixedSize(340, 520);
$window->setStyleSheet(<<<'CSS'
QWidget {
    background: #1b1b1d;
    color: #ffffff;
    font-family: -apple-system, "SF Pro Text", "Helvetica Neue", sans-serif;
    font-size: 18px;
}
QWidget#displayCard {
    background: #1b1b1d;
    border: 0;
}
QLabel#topLine {
    color: #96969e;
    font-size: 18px;
}
QLabel#display {
    color: #ffffff;
    font-size: 56px;
    font-weight: 300;
}
QPushButton {
    border: none;
    border-radius: 28px;
    background: #505050;
    color: #ffffff;
    font-size: 30px;
    font-weight: 400;
}
QPushButton:hover {
    background: #656567;
}
QPushButton:pressed {
    background: #7a7a7b;
}
QPushButton[role="utility"] {
    background: #d4d4d2;
    color: #111111;
}
QPushButton[role="utility"]:hover {
    background: #dfdfde;
}
QPushButton[role="utility"]:pressed {
    background: #c4c4c2;
}
QPushButton[role="operator"] {
    background: #ff9f0a;
    color: #ffffff;
}
QPushButton[role="operator"]:hover {
    background: #ffb03b;
}
QPushButton[role="operator"]:pressed {
    background: #e28d00;
}
QPushButton#zero {
    text-align: left;
    padding-left: 24px;
}
CSS);

$engine = new CalculatorEngine();

$root = new QVBoxLayout();
$root->setContentsMargins(14, 16, 14, 14);
$root->setSpacing(12);

$displayCard = new QWidget();
$displayCard->setObjectName('displayCard');
$displayLayout = new QVBoxLayout();
$displayLayout->setContentsMargins(6, 8, 6, 8);
$displayLayout->setSpacing(2);

$topLine = new QLabel('');
$topLine->setObjectName('topLine');
$topLine->setAlignment(130);

$display = new QLabel('0');
$display->setObjectName('display');
$display->setAlignment(130);

$displayLayout->addWidget($topLine);
$displayLayout->addWidget($display);
$displayCard->setLayout($displayLayout);

$grid = new QGridLayout();
$grid->setContentsMargins(0, 0, 0, 0);
$grid->setHorizontalSpacing(10);
$grid->setVerticalSpacing(10);

$refresh = static function () use ($engine, $display, $topLine): void {
    $display->setText($engine->displayText());
    $topLine->setText($engine->topLineText());
};

/** @var list<QShortcut> $shortcuts */
$shortcuts = [];
foreach (['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit) {
    calculator_bind_shortcut($shortcuts, $window, $digit, static function () use ($engine, $digit, $refresh): void {
        $engine->inputDigit($digit);
        $refresh();
    });
}
foreach (['.', ','] as $dotKey) {
    calculator_bind_shortcut($shortcuts, $window, $dotKey, static function () use ($engine, $refresh): void {
        $engine->inputDot();
        $refresh();
    });
}
calculator_bind_shortcut($shortcuts, $window, '+', static function () use ($engine, $refresh): void {
    $engine->setOperator('+');
    $refresh();
});
calculator_bind_shortcut($shortcuts, $window, '-', static function () use ($engine, $refresh): void {
    $engine->setOperator('-');
    $refresh();
});
calculator_bind_shortcut($shortcuts, $window, '*', static function () use ($engine, $refresh): void {
    $engine->setOperator('×');
    $refresh();
});
calculator_bind_shortcut($shortcuts, $window, '/', static function () use ($engine, $refresh): void {
    $engine->setOperator('÷');
    $refresh();
});
calculator_bind_shortcut($shortcuts, $window, '%', static function () use ($engine, $refresh): void {
    $engine->percent();
    $refresh();
});
foreach (['Return', 'Enter', '='] as $evalKey) {
    calculator_bind_shortcut($shortcuts, $window, $evalKey, static function () use ($engine, $refresh): void {
        $engine->evaluate();
        $refresh();
    });
}
foreach (['Escape', 'Backspace'] as $clearKey) {
    calculator_bind_shortcut($shortcuts, $window, $clearKey, static function () use ($engine, $refresh): void {
        $engine->clear();
        $refresh();
    });
}

$grid->addWidget(calculator_button('AC', 'utility', static function () use ($engine, $refresh): void {
    $engine->clear();
    $refresh();
}), 0, 0);
$grid->addWidget(calculator_button('+/-', 'utility', static function () use ($engine, $refresh): void {
    $engine->toggleSign();
    $refresh();
}), 0, 1);
$grid->addWidget(calculator_button('%', 'utility', static function () use ($engine, $refresh): void {
    $engine->percent();
    $refresh();
}), 0, 2);
$grid->addWidget(calculator_button('÷', 'operator', static function () use ($engine, $refresh): void {
    $engine->setOperator('÷');
    $refresh();
}), 0, 3);

foreach (['7', '8', '9'] as $i => $digit) {
    $grid->addWidget(calculator_button($digit, 'number', static function () use ($engine, $digit, $refresh): void {
        $engine->inputDigit($digit);
        $refresh();
    }), 1, $i);
}
$grid->addWidget(calculator_button('×', 'operator', static function () use ($engine, $refresh): void {
    $engine->setOperator('×');
    $refresh();
}), 1, 3);

foreach (['4', '5', '6'] as $i => $digit) {
    $grid->addWidget(calculator_button($digit, 'number', static function () use ($engine, $digit, $refresh): void {
        $engine->inputDigit($digit);
        $refresh();
    }), 2, $i);
}
$grid->addWidget(calculator_button('-', 'operator', static function () use ($engine, $refresh): void {
    $engine->setOperator('-');
    $refresh();
}), 2, 3);

foreach (['1', '2', '3'] as $i => $digit) {
    $grid->addWidget(calculator_button($digit, 'number', static function () use ($engine, $digit, $refresh): void {
        $engine->inputDigit($digit);
        $refresh();
    }), 3, $i);
}
$grid->addWidget(calculator_button('+', 'operator', static function () use ($engine, $refresh): void {
    $engine->setOperator('+');
    $refresh();
}), 3, 3);

$zero = calculator_button('0', 'number', static function () use ($engine, $refresh): void {
    $engine->inputDigit('0');
    $refresh();
});
$zero->setObjectName('zero');
$grid->addWidget($zero, 4, 0, 1, 2);
$grid->addWidget(calculator_button('.', 'number', static function () use ($engine, $refresh): void {
    $engine->inputDot();
    $refresh();
}), 4, 2);
$grid->addWidget(calculator_button('=', 'operator', static function () use ($engine, $refresh): void {
    $engine->evaluate();
    $refresh();
}), 4, 3);

$root->addWidget($displayCard);
$root->addLayout($grid);
$window->setLayout($root);
$window->show();

example_line('calculator ready');
QApplication::exec();
