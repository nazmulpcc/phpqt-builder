<?php

declare(strict_types=1);

namespace Examples\Support\Widgets;

use Qt\Widgets\QLabel;

final class StatusBarMessage extends QLabel
{
    public function __construct()
    {
        parent::__construct('');
        $this->setProperty('role', 'caption');
    }

    public function info(string $message): void
    {
        $this->setText($message);
    }
}
