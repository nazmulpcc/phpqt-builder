<?php

declare(strict_types=1);

namespace Examples\Support\Widgets;

use Qt\Widgets\QLabel;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class FormFieldRow extends QWidget
{
    public function __construct(string $label, QWidget $field, string $helper = '')
    {
        parent::__construct();

        $layout = new QVBoxLayout();
        $layout->setSpacing(6);

        $labelWidget = new QLabel($label);
        $layout->addWidget($labelWidget);
        $layout->addWidget($field);

        if ($helper !== '') {
            $helperWidget = new QLabel($helper);
            $helperWidget->setProperty('role', 'caption');
            $layout->addWidget($helperWidget);
        }

        $this->setLayout($layout);
    }
}
