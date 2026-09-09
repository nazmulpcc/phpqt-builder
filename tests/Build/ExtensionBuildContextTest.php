<?php

declare(strict_types=1);

use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Qt\QtInstallation;

it('keeps parents before children even when dependency cycles exist', function (): void {
    $installation = new QtInstallation(
        '/opt/homebrew',
        'Darwin',
        [],
        [],
        [],
        null,
        [],
    );

    $context = new ExtensionBuildContext(
        'qt',
        '0.1.0',
        'build',
        'build/ext',
        $installation,
        ['QtCore'],
        generatedClasses: ['QAbstractItemModel', 'QObject', 'QModelIndex'],
        generatedClassParents: [
            'QAbstractItemModel' => 'QObject',
            'QObject' => null,
            'QModelIndex' => null,
        ],
        generatedClassDependencies: [
            'QAbstractItemModel' => ['QObject', 'QModelIndex'],
            'QObject' => ['QAbstractItemModel'],
            'QModelIndex' => ['QAbstractItemModel'],
        ],
    );

    $minits = $context->classMinits();

    expect($minits)->toContain('qt_qobject', 'qt_qabstractitemmodel', 'qt_qmodelindex');
    expect(array_search('qt_qobject', $minits, true))
        ->toBeLessThan(array_search('qt_qabstractitemmodel', $minits, true));
});

it('includes qtest support files when qtest support is enabled', function (): void {
    $installation = new QtInstallation(
        '/opt/homebrew',
        'Darwin',
        [],
        [],
        [],
        null,
        [],
    );

    $context = new ExtensionBuildContext(
        'qt',
        '0.1.0',
        'build',
        'build/ext',
        $installation,
        ['QtCore', 'QtGui', 'QtWidgets', 'QtTest'],
        includeQTestSupport: true,
    );

    expect($context->classHeaders())->toContain('classes/qt_qtest.h')
        ->and($context->classSources())->toContain('classes/qt_qtest.cpp')
        ->and($context->classMinits())->toContain('qt_qtest');

    $derived = $context->withGeneratedClasses(['QObject']);
    expect($derived->includeQTestSupport)->toBeTrue();

    $disabled = $context->withGeneratedClasses(['QObject'], includeQTestSupport: false);
    expect($disabled->includeQTestSupport)->toBeFalse();
});
