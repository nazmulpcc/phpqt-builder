<?php

declare(strict_types=1);

namespace Examples\Support\Widgets;

use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLineEdit;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QWidget;

final class SearchBar extends QWidget
{
    private QLineEdit $input;

    public function __construct(string $placeholder = 'Search')
    {
        parent::__construct();

        $layout = new QHBoxLayout();
        $layout->setContentsMargins(0, 0, 0, 0);
        $layout->setSpacing(8);

        $this->input = new QLineEdit();
        $this->input->setPlaceholderText($placeholder);

        $clear = new QPushButton('Clear');
        $clear->setProperty('variant', 'secondary');
        $clear->onClicked(function (): void {
            $this->input->setText('');
        });

        $layout->addWidget($this->input);
        $layout->addWidget($clear);
        $this->setLayout($layout);
    }

    public function input(): QLineEdit
    {
        return $this->input;
    }
}
