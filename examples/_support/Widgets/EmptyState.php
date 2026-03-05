<?php

declare(strict_types=1);

namespace Examples\Support\Widgets;

use Qt\Widgets\QLabel;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class EmptyState extends QWidget
{
    public function __construct(string $title, string $message)
    {
        parent::__construct();

        $layout = new QVBoxLayout();
        $layout->setContentsMargins(16, 16, 16, 16);

        $titleLabel = new QLabel($title);
        $titleLabel->setProperty('role', 'heading');
        $layout->addWidget($titleLabel);

        $messageLabel = new QLabel($message);
        $messageLabel->setWordWrap(true);
        $messageLabel->setProperty('role', 'caption');
        $layout->addWidget($messageLabel);

        $this->setLayout($layout);
    }
}
