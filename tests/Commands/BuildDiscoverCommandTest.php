<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Commands;

use PHPUnit\Framework\TestCase;
use QtBuilder\Commands\BuildCommand;
use QtBuilder\Commands\BuildDiscoverCommand;
use QtBuilder\Tests\Support\FakeExtensionBootstrapper;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildDiscoverCommandTest extends TestCase
{
    public function testDiscoverWritesReusableBuildMetadata(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
        $buildRoot = sys_get_temp_dir() . '/qtbuilder-discover-' . bin2hex(random_bytes(4));
        $outputDir = $buildRoot . '/ext';
        $metadataDir = $buildRoot . '/generated';

        $command = new BuildDiscoverCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('Running 2 parallel discovery worker(s)...', $tester->getDisplay());
        self::assertFileExists($metadataDir . '/discovery_cache.json');
        self::assertFileExists($metadataDir . '/accepted_candidates.json');
        self::assertFileExists($metadataDir . '/allowed_classes.json');
        self::assertFileDoesNotExist($outputDir . '/config.m4');

        $cache = json_decode((string) file_get_contents($metadataDir . '/discovery_cache.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(6, $cache['candidate_count']);
        self::assertCount(5, $cache['accepted_candidates']);
        self::assertCount(1, $cache['skipped_classes']);
        self::assertSame(
            ['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree'],
            array_column($cache['accepted_candidates'], 'class'),
        );

        $allowedClasses = json_decode((string) file_get_contents($metadataDir . '/allowed_classes.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree'], $allowedClasses);
    }

    public function testDiscoverCacheIsConsumedByBuildCommand(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
        $buildRoot = sys_get_temp_dir() . '/qtbuilder-discover-build-' . bin2hex(random_bytes(4));
        $outputDir = $buildRoot . '/ext';
        $bootstrapper = new FakeExtensionBootstrapper();

        $discover = new CommandTester(new BuildDiscoverCommand(FakeSystemInformation::passing()));
        self::assertSame(Command::SUCCESS, $discover->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]), $discover->getDisplay());

        $build = new CommandTester(new BuildCommand(FakeSystemInformation::passing(), $bootstrapper));
        $exitCode = $build->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $build->getDisplay());
        self::assertStringContainsString('Using cached build metadata:', $build->getDisplay());
        self::assertCount(1, $bootstrapper->contexts);
    }
}
