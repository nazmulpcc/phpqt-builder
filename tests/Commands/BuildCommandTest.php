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
        $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-' . bin2hex(random_bytes(4));
        $outputDir = $buildRoot . '/ext';
        $metadataDir = $buildRoot . '/generated';

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
        self::assertFileExists($metadataDir . '/build_summary.json');
        self::assertFileExists($metadataDir . '/allowed_classes.json');
        self::assertFileExists($metadataDir . '/discovery_cache.json');
        self::assertFileExists($metadataDir . '/accepted_candidates.json');

        $summary = json_decode((string) file_get_contents($metadataDir . '/build_summary.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(5, $summary['generated_classes']);
        self::assertSame(1, $summary['skipped_classes']);

        $classmap = json_decode((string) file_get_contents($metadataDir . '/classmap.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree'], array_column($classmap, 'class'));

        $allowedClasses = json_decode((string) file_get_contents($metadataDir . '/allowed_classes.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree'], $allowedClasses);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qtree.stub.php');
        self::assertStringContainsString('QNode|null $node = null', $stub);
    }

    public function testBuildReusesExistingDiscoveryCache(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
        $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-cache-' . bin2hex(random_bytes(4));
        $outputDir = $buildRoot . '/ext';
        $metadataDir = $buildRoot . '/generated';

        $command = new BuildCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]));

        $cache = json_decode((string) file_get_contents($metadataDir . '/discovery_cache.json'), true, 512, JSON_THROW_ON_ERROR);
        $cache['candidate_count'] = 1;
        $cache['accepted_candidates'] = [[
            'module' => 'QtCore',
            'class' => 'QPoint',
            'public_header' => $fixtureRoot . '/include/QtCore/QPoint',
            'parse_header' => $fixtureRoot . '/include/QtCore/qpoint.h',
        ]];
        $cache['allowed_classes'] = ['QPoint'];
        file_put_contents($metadataDir . '/discovery_cache.json', json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($metadataDir . '/accepted_candidates.json', json_encode($cache['accepted_candidates'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($metadataDir . '/allowed_classes.json', json_encode($cache['allowed_classes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $tester = new CommandTester(new BuildCommand(FakeSystemInformation::passing()));
        $exitCode = $tester->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('Using cached build metadata:', $tester->getDisplay());
        self::assertStringContainsString('discovery_cache.json', $tester->getDisplay());
        self::assertStringContainsString('accepted_candidates.json', $tester->getDisplay());
        self::assertStringContainsString('allowed_classes.json', $tester->getDisplay());

        $summary = json_decode((string) file_get_contents($metadataDir . '/build_summary.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $summary['generated_classes']);
        self::assertSame(1, $summary['candidate_classes']);

        $classmap = json_decode((string) file_get_contents($metadataDir . '/classmap.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QPoint'], array_column($classmap, 'class'));
    }
}
