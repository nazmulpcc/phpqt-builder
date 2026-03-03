<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Build;

use PHPUnit\Framework\TestCase;
use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Qt\QtInstallation;

final class ExtensionBuildContextTest extends TestCase
{
    public function testClassMinitsKeepParentsBeforeChildrenEvenWhenDependencyCyclesExist(): void
    {
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

        self::assertContains('qt_qobject', $minits);
        self::assertContains('qt_qabstractitemmodel', $minits);
        self::assertContains('qt_qmodelindex', $minits);
        self::assertLessThan(
            array_search('qt_qabstractitemmodel', $minits, true),
            array_search('qt_qobject', $minits, true),
        );
    }
}
