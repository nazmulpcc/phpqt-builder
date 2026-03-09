<?php

declare(strict_types=1);

use QtBuilder\Qt\QtInstallation;
use QtBuilder\Scanning\ModuleHeaderScanner;

it('skips alias-only forwarding headers while keeping real classes', function (): void {
    $moduleRoot = qt_temp_dir('qtbuilder-scan-module-');
    mkdir($moduleRoot . '/QtFoo', 0777, true);

    file_put_contents($moduleRoot . '/QtFoo/QRealClass', "#include \"qrealclass.h\"\n");
    file_put_contents($moduleRoot . '/QtFoo/qrealclass.h', "class QRealClass { public: int v() const; };\n");

    file_put_contents($moduleRoot . '/QtFoo/QAliasType', "#include \"qaliastype.h\"\n");
    file_put_contents($moduleRoot . '/QtFoo/qaliastype.h', "typedef unsigned short QAliasType;\n");

    $installation = new QtInstallation(
        rootPath: $moduleRoot,
        osFamily: 'Linux',
        includeRoots: [$moduleRoot],
        libraryRoots: [],
        moduleHeaderRoots: ['QtFoo' => $moduleRoot . '/QtFoo'],
    );

    $scanner = new ModuleHeaderScanner();
    $candidates = $scanner->scan($installation, 'QtFoo');

    expect(array_map(static fn($candidate): string => $candidate->className, $candidates))
        ->toBe(['QRealClass']);
});
