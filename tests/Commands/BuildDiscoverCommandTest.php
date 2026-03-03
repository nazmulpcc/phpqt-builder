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
        $classCacheDir = $buildRoot . '/classes';

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
        self::assertStringContainsString('Class structure cache:', $tester->getDisplay());
        self::assertStringContainsString('0 hit(s), 5 miss(es)', $tester->getDisplay());
        self::assertStringContainsString('Discovery pass 1', $tester->getDisplay());
        self::assertFileExists($metadataDir . '/discovery_cache.json');
        self::assertFileExists($metadataDir . '/accepted_candidates.json');
        self::assertFileExists($metadataDir . '/allowed_classes.json');
        self::assertFileExists($classCacheDir . '/QAbstractItemModel.json');
        self::assertFileExists($classCacheDir . '/QPoint.json');
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

    public function testDiscoverReusesClassStructureCacheAfterGeneratedMetadataIsCleared(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
        $buildRoot = sys_get_temp_dir() . '/qtbuilder-discover-reuse-' . bin2hex(random_bytes(4));
        $outputDir = $buildRoot . '/ext';
        $metadataDir = $buildRoot . '/generated';
        $classCacheDir = $buildRoot . '/classes';

        $command = new BuildDiscoverCommand(FakeSystemInformation::passing());
        $firstRun = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $firstRun->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]), $firstRun->getDisplay());

        self::assertFileExists($classCacheDir . '/QPoint.json');
        $this->removeDir($metadataDir);
        self::assertDirectoryDoesNotExist($metadataDir);

        $secondRun = new CommandTester(new BuildDiscoverCommand(FakeSystemInformation::passing()));
        $exitCode = $secondRun->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $secondRun->getDisplay());
        self::assertStringContainsString('Class structure cache:', $secondRun->getDisplay());
        self::assertStringContainsString('5 hit(s), 0 miss(es)', $secondRun->getDisplay());
        self::assertStringNotContainsString('Building cached class structures with 2 parallel worker(s)...', $secondRun->getDisplay());
        self::assertFileExists($metadataDir . '/discovery_cache.json');
        self::assertFileExists($metadataDir . '/accepted_candidates.json');
        self::assertFileExists($metadataDir . '/allowed_classes.json');
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

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $path . '/' . $entry;
            if (is_dir($entryPath)) {
                $this->removeDir($entryPath);
                continue;
            }

            @unlink($entryPath);
        }

        @rmdir($path);
    }
}
