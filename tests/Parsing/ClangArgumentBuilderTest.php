<?php

declare(strict_types=1);

use QtBuilder\Parsing\ClangArgumentBuilder;

it('includes the qt feature override header', function (): void {
    $builder = new ClangArgumentBuilder();
    $normalizePath = static fn(string $path): string => str_replace('\\', '/', $path);
    $args = array_map($normalizePath, $builder->build());

    $overrideHeader = realpath(dirname(__DIR__, 2) . '/templates/clang/qt_feature_overrides.h');
    expect($overrideHeader)->not->toBeFalse();
    expect($args)->toContain('-include', $normalizePath($overrideHeader));
});

it('places explicit include paths before injected override headers', function (): void {
    $includeDir = sys_get_temp_dir() . '/qtb-clang-args-' . bin2hex(random_bytes(4));
    mkdir($includeDir, 0755, true);

    $builder = new ClangArgumentBuilder([$includeDir]);
    $normalizePath = static fn(string $path): string => str_replace('\\', '/', $path);
    $args = array_map($normalizePath, $builder->build());

    $includeIndex = array_search('-I' . $normalizePath($includeDir), $args, true);
    $overrideIndex = array_search('-include', $args, true);

    expect($includeIndex)->not->toBeFalse();
    expect($overrideIndex)->not->toBeFalse();
    expect((int) $includeIndex)->toBeLessThan((int) $overrideIndex);
});

it('skips auto-discovered qt include roots when explicit qt includes are provided', function (): void {
    $builderWithoutExplicitQt = new ClangArgumentBuilder();
    $defaultArgs = $builderWithoutExplicitQt->build();
    $defaultQtIncludes = array_values(array_filter(
        $defaultArgs,
        static fn(string $arg): bool => str_starts_with($arg, '-I') && preg_match('#/qt6(/|$)#i', $arg) === 1,
    ));

    if ($defaultQtIncludes === []) {
        test()->markTestSkipped('No auto-discovered system Qt includes on this host.');
    }

    $qtRoot = sys_get_temp_dir() . '/qtb-explicit-qt-' . bin2hex(random_bytes(4));
    mkdir($qtRoot . '/QtCore', 0755, true);
    mkdir($qtRoot . '/QtGui', 0755, true);

    $builderWithExplicitQt = new ClangArgumentBuilder([$qtRoot]);
    $argsWithExplicitQt = $builderWithExplicitQt->build();

    foreach ($defaultQtIncludes as $autoQtInclude) {
        expect($argsWithExplicitQt)->not->toContain($autoQtInclude);
    }
});
