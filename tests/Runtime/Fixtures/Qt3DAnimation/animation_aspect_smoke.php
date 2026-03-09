<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Gui\QGuiApplication;
use Qt\Qt3DAnimation\QAnimationAspect;
use Qt\Qt3DCore\QAspectEngine;
use Qt\Qt3DCore\QEntity;

qt_runtime_require_class(QAnimationAspect::class, 'Qt3DAnimation classes are unavailable in this build.');
qt_runtime_require_class(QAspectEngine::class, 'Qt3DCore is unavailable in this build.');

$app = new QGuiApplication();

$engine = new QAspectEngine();
$engine->setRunMode(QAspectEngine::Manual);

$animationAspect = new QAnimationAspect($engine);
$engine->registerAspect($animationAspect);

$root = new QEntity();
$engine->setRootEntity($root);

qt_runtime_result([
    'aspect_count'       => count($engine->aspects()),
    'has_animation'      => count($engine->aspects()) >= 1,
    'run_mode'           => $engine->runMode(),
    'root_is_entity'     => $engine->rootEntity() instanceof QEntity,
]);
