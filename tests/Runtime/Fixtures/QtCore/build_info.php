<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

qt_runtime_require_class(\Qt\BuildInfo::class, 'Qt\\BuildInfo is unavailable in this build.');

$qtCoreInfo = \Qt\BuildInfo::moduleInfo('QtCore');
$qtWidgetsInfo = \Qt\BuildInfo::moduleInfo('QtWidgets');
$manifest = \Qt\BuildInfo::manifest();

qt_runtime_result([
    'build_mode' => \Qt\BuildInfo::buildMode(),
    'qt_version' => \Qt\BuildInfo::qtVersion(),
    'extension_version' => \Qt\BuildInfo::extensionVersion(),
    'built_modules' => \Qt\BuildInfo::builtModules(),
    'loaded_modules' => \Qt\BuildInfo::loadedModules(),
    'has_qtcore' => \Qt\BuildInfo::hasModule('QtCore'),
    'is_qtcore_loaded' => \Qt\BuildInfo::isLoaded('QtCore'),
    'has_qtwidgets' => \Qt\BuildInfo::hasModule('QtWidgets'),
    'is_qtwidgets_loaded' => \Qt\BuildInfo::isLoaded('QtWidgets'),
    'qtcore_info' => $qtCoreInfo,
    'qtwidgets_info' => $qtWidgetsInfo,
    'manifest' => $manifest,
]);
