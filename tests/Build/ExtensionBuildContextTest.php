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
