<?php

declare(strict_types=1);

use QtBuilder\Qt\QtInstallation;

it('disables runtime notify functor connect for qt 6.8 lts', function (): void {
    $installation = new QtInstallation(
        rootPath: '/opt/qt',
        osFamily: 'Darwin',
        includeRoots: [],
        libraryRoots: [],
        moduleHeaderRoots: [],
        qtVersion: '6.8.3',
        qtVersionMajor: 6,
        qtVersionMinor: 8,
        qtVersionPatch: 3,
    );

    expect($installation->supportsRuntimeNotifyFunctorConnect())->toBeFalse();
});

it('enables runtime notify functor connect for qt 6.10 and newer', function (): void {
    $installation = new QtInstallation(
        rootPath: '/opt/qt',
        osFamily: 'Linux',
        includeRoots: [],
        libraryRoots: [],
        moduleHeaderRoots: [],
        qtVersion: '6.10.2',
        qtVersionMajor: 6,
        qtVersionMinor: 10,
        qtVersionPatch: 2,
    );

    expect($installation->supportsRuntimeNotifyFunctorConnect())->toBeTrue();
});

it('falls back conservatively when the qt version is unknown', function (): void {
    $installation = new QtInstallation(
        rootPath: '/opt/qt',
        osFamily: 'Linux',
        includeRoots: [],
        libraryRoots: [],
        moduleHeaderRoots: [],
    );

    expect($installation->supportsRuntimeNotifyFunctorConnect())->toBeFalse();
});
