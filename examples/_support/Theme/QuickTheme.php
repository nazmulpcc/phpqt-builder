<?php

declare(strict_types=1);

namespace Examples\Support\Theme;

use Qt\Core\QObject;

final class QuickTheme
{
    public static function create(string $mode = 'dark'): QObject
    {
        $mode = $mode === 'light' ? 'light' : 'dark';

        $theme = new QObject();
        $theme->setProperty('mode', $mode);
        $theme->setProperty('background', $mode === 'light' ? '#eef3fb' : '#0a1221');
        $theme->setProperty('surface', $mode === 'light' ? '#ffffff' : '#101a2f');
        $theme->setProperty('surfaceAlt', $mode === 'light' ? '#f4f7fd' : '#16223a');
        $theme->setProperty('border', $mode === 'light' ? '#d8e2f0' : '#22314f');
        $theme->setProperty('primary', '#2d72ff');
        $theme->setProperty('primaryText', '#ffffff');
        $theme->setProperty('text', $mode === 'light' ? '#10223f' : '#f6f8ff');
        $theme->setProperty('mutedText', $mode === 'light' ? '#60748f' : '#93a7c5');
        $theme->setProperty('danger', '#db5d5d');
        $theme->setProperty('success', '#1ea567');

        return $theme;
    }
}
