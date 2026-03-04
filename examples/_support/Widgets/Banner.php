<?php

declare(strict_types=1);

namespace Examples\Support\Widgets;

use Qt\Widgets\QLabel;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class Banner extends QWidget
{
    private QLabel $label;

    public function __construct()
    {
        parent::__construct();

        $this->setObjectName('appCard');
        $layout = new QVBoxLayout();
        $layout->setContentsMargins(12, 10, 12, 10);

        $this->label = new QLabel('');
        $layout->addWidget($this->label);
        $this->setLayout($layout);
        $this->hide();
    }

    public function showError(string $message): void
    {
        $this->setStyleSheet('background: #5b1e27; border: 1px solid #c25a69; border-radius: 12px;');
        $this->label->setText($message);
        $this->show();
    }

    public function showInfo(string $message): void
    {
        $this->setStyleSheet('background: #173057; border: 1px solid #4d79bc; border-radius: 12px;');
        $this->label->setText($message);
        $this->show();
    }

    public function clear(): void
    {
        $this->label->setText('');
        $this->hide();
    }
}
