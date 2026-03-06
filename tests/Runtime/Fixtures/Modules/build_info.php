<?php

declare(strict_types=1);

if (!extension_loaded('qtcore')) {
    fwrite(STDERR, "The qtcore extension is not loaded.\n");
    exit(1);
}

if (!class_exists(\Qt\BuildInfo::class)) {
    echo 'PHPQT_RESULT=' . json_encode([
        'reason' => 'Qt\\BuildInfo is unavailable in this split build.',
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(77);
}

echo 'PHPQT_RESULT=' . json_encode([
    'build_mode' => \Qt\BuildInfo::buildMode(),
    'qt_version' => \Qt\BuildInfo::qtVersion(),
    'extension_version' => \Qt\BuildInfo::extensionVersion(),
    'built_modules' => \Qt\BuildInfo::builtModules(),
    'loaded_modules' => \Qt\BuildInfo::loadedModules(),
    'has_qtcore' => \Qt\BuildInfo::hasModule('QtCore'),
    'is_qtcore_loaded' => \Qt\BuildInfo::isLoaded('QtCore'),
    'has_qtgui' => \Qt\BuildInfo::hasModule('QtGui'),
    'is_qtgui_loaded' => \Qt\BuildInfo::isLoaded('QtGui'),
    'has_qtwidgets' => \Qt\BuildInfo::hasModule('QtWidgets'),
    'is_qtwidgets_loaded' => \Qt\BuildInfo::isLoaded('QtWidgets'),
    'qtcore_info' => \Qt\BuildInfo::moduleInfo('QtCore'),
    'qtgui_info' => \Qt\BuildInfo::moduleInfo('QtGui'),
    'qtwidgets_info' => \Qt\BuildInfo::moduleInfo('QtWidgets'),
    'manifest' => \Qt\BuildInfo::manifest(),
], JSON_THROW_ON_ERROR) . PHP_EOL;
