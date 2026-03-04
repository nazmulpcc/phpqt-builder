<?php

declare(strict_types=1);

use QtBuilder\Parsing\ClangArgumentBuilder;

it('includes the qt feature override header', function (): void {
    $builder = new ClangArgumentBuilder();
    $args = $builder->build();

    $overrideHeader = realpath(dirname(__DIR__, 2) . '/templates/clang/qt_feature_overrides.h');
    expect($overrideHeader)->not->toBeFalse();
    expect($args)->toContain('-include', $overrideHeader);
});
