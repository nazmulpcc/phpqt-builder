<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Commands;

use PHPUnit\Framework\TestCase;
use QtBuilder\Commands\BuildCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildCommandTest extends TestCase
{
    public function testBuildGeneratesExtensionTreeFromFixtureQtRoot(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-build-' . bin2hex(random_bytes(4));

        $command = new BuildCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertFileExists($outputDir . '/config.m4');
        self::assertFileExists($outputDir . '/php_qt.h');
        self::assertFileExists($outputDir . '/qt.cpp');
        self::assertFileExists($outputDir . '/classes/qt_qpoint.cpp');
        self::assertFileExists($outputDir . '/classes/qt_qtree.cpp');
        self::assertFileExists($outputDir . '/classes/qt_qnode.cpp');
        self::assertFileExists($outputDir . '/classes/qt_qabstractitemmodel.cpp');
        self::assertFileExists($outputDir . '/generated/build_summary.json');
        self::assertFileExists($outputDir . '/generated/allowed_classes.json');

        $summary = json_decode((string) file_get_contents($outputDir . '/generated/build_summary.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(5, $summary['generated_classes']);
        self::assertSame(1, $summary['skipped_classes']);

        $classmap = json_decode((string) file_get_contents($outputDir . '/generated/classmap.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree'], array_column($classmap, 'class'));

        $allowedClasses = json_decode((string) file_get_contents($outputDir . '/generated/allowed_classes.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree'], $allowedClasses);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qtree.stub.php');
        self::assertStringContainsString('QNode|null $node = null', $stub);
    }
}
