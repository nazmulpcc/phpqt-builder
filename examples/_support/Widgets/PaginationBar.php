<?php

declare(strict_types=1);

namespace Examples\Support\Widgets;

use Qt\Widgets\QComboBox;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QWidget;

final class PaginationBar extends QWidget
{
    private QLabel $summary;
    private QPushButton $previous;
    private QPushButton $next;
    private QComboBox $pageSize;

    public function __construct()
    {
        parent::__construct();

        $layout = new QHBoxLayout();
        $layout->setContentsMargins(0, 0, 0, 0);
        $layout->setSpacing(10);

        $this->summary = new QLabel('');
        $this->summary->setProperty('role', 'caption');

        $this->pageSize = new QComboBox();
        foreach ([25, 50, 100, 250] as $size) {
            $this->pageSize->addItem((string) $size);
        }

        $this->previous = new QPushButton('Previous');
        $this->previous->setProperty('variant', 'secondary');
        $this->next = new QPushButton('Next');

        $layout->addWidget($this->summary);
        $layout->addStretch(1);
        $layout->addWidget(new QLabel('Rows'));
        $layout->addWidget($this->pageSize);
        $layout->addWidget($this->previous);
        $layout->addWidget($this->next);

        $this->setLayout($layout);
    }

    public function summary(): QLabel
    {
        return $this->summary;
    }

    public function previousButton(): QPushButton
    {
        return $this->previous;
    }

    public function nextButton(): QPushButton
    {
        return $this->next;
    }

    public function pageSizeBox(): QComboBox
    {
        return $this->pageSize;
    }
}
