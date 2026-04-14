<?php

declare(strict_types=1);

use QtBuilder\Qt\QtInstallationResolver;
use QtBuilder\System\CommandResult;
use QtBuilder\Tests\Support\FakeSystemInformation;

it('prefers the explicit qt path version when building include roots', function (): void {
    $qtRoot = qt_temp_dir('qtbuilder-qt-root-');
    $includeRoot = $qtRoot . '/include';
    $qtCoreRoot = $includeRoot . '/QtCore';

    mkdir($qtCoreRoot, 0777, true);
    mkdir($qtCoreRoot . '/6.10.2/QtCore/private', 0777, true);
    file_put_contents(
        $qtCoreRoot . '/qconfig.h',
        <<<'H'
#define QT_VERSION_STR "6.10.2"
#define QT_VERSION_MAJOR 6
#define QT_VERSION_MINOR 10
#define QT_VERSION_PATCH 2
H,
    );

    $system = new FakeSystemInformation();
    $system->setExecutable('qtpaths6', '/usr/bin/qtpaths6');
    $system->setCommandResult(
        ['/usr/bin/qtpaths6', '--qt-version'],
        new CommandResult(0, "6.4.2\n", ''),
    );

    $resolver = new QtInstallationResolver($system);
    $installation = $resolver->resolve($qtRoot, ['QtCore']);
    $normalizePath = static fn(string $path): string => str_replace('\\', '/', $path);
    $includeRoots = array_map($normalizePath, $installation->includeRoots);

    expect($installation->qtVersion)->toBe('6.10.2')
        ->and($includeRoots)->toContain(
            $normalizePath($includeRoot),
            $normalizePath($qtCoreRoot),
            $normalizePath($qtCoreRoot . '/6.10.2'),
            $normalizePath($qtCoreRoot . '/6.10.2/QtCore'),
        );
});
