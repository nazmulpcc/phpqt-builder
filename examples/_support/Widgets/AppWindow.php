<?php

declare(strict_types=1);

namespace Examples\Support\Widgets;

use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class AppWindow extends QWidget
{
    private QVBoxLayout $rootLayout;
    private QVBoxLayout $bodyLayout;

    public function __construct(string $title, string $subtitle = '')
    {
        parent::__construct();

        $this->setObjectName('appCard');

        $this->rootLayout = new QVBoxLayout();
        $this->rootLayout->setContentsMargins(22, 22, 22, 22);
        $this->rootLayout->setSpacing(16);

        $header = new QVBoxLayout();
        $header->setSpacing(4);

        $titleLabel = new QLabel($title);
        $titleLabel->setProperty('role', 'heading');
        $header->addWidget($titleLabel);

        if ($subtitle !== '') {
            $subtitleLabel = new QLabel($subtitle);
            $subtitleLabel->setProperty('role', 'subheading');
            $header->addWidget($subtitleLabel);
        }

        $this->bodyLayout = new QVBoxLayout();
        $this->bodyLayout->setSpacing(14);

        $this->rootLayout->addLayout($header);
        $this->rootLayout->addLayout($this->bodyLayout);
        $this->setLayout($this->rootLayout);
    }

    public function bodyLayout(): QVBoxLayout
    {
        return $this->bodyLayout;
    }

    public function addSection(QWidget $widget): void
    {
        $this->bodyLayout->addWidget($widget);
    }

    public function addRow(QHBoxLayout $layout): void
    {
        $this->bodyLayout->addLayout($layout);
    }
}
