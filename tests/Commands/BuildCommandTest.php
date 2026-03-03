<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Commands;

use PHPUnit\Framework\TestCase;
use QtBuilder\Commands\BuildCommand;
use QtBuilder\Tests\Support\FakeExtensionBootstrapper;
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
        $classCacheDir = $buildRoot . '/classes';
        $bootstrapper = new FakeExtensionBootstrapper();

        $command = new BuildCommand(FakeSystemInformation::passing(), $bootstrapper);
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
        self::assertFileExists($outputDir . '/build/gen_stub.php');
        self::assertFileExists($outputDir . '/configure');
        self::assertFileExists($outputDir . '/Makefile');
        self::assertFileExists($outputDir . '/classes/qt_qpoint_arginfo.h');
        self::assertFileExists($metadataDir . '/build_summary.json');
        self::assertFileExists($metadataDir . '/allowed_classes.json');
        self::assertFileExists($metadataDir . '/discovery_cache.json');
        self::assertFileExists($metadataDir . '/accepted_candidates.json');
        self::assertFileExists($classCacheDir . '/QPoint.json');
        self::assertFileExists($metadataDir . '/phpize.stdout.log');
        self::assertFileExists($metadataDir . '/gen_stub.stdout.log');
        self::assertFileExists($metadataDir . '/configure.stdout.log');
        self::assertFileExists($metadataDir . '/make.stdout.log');
        self::assertStringContainsString('Running 2 parallel discovery worker(s)...', $tester->getDisplay());
        self::assertStringContainsString('Class structure cache:', $tester->getDisplay());
        self::assertStringContainsString('Discovery pass 1', $tester->getDisplay());
        self::assertCount(1, $bootstrapper->contexts);

        $summary = json_decode((string) file_get_contents($metadataDir . '/build_summary.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(5, $summary['generated_classes']);
        self::assertSame(1, $summary['skipped_classes']);
        self::assertSame(['phpize', 'gen_stub', 'configure', 'make'], array_column($summary['bootstrap'], 'name'));

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
        $bootstrapper = new FakeExtensionBootstrapper();

        $command = new BuildCommand(FakeSystemInformation::passing(), $bootstrapper);
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

        $tester = new CommandTester(new BuildCommand(FakeSystemInformation::passing(), $bootstrapper));
        $exitCode = $tester->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('Using cached build metadata:', $tester->getDisplay());
        self::assertStringContainsString('Bootstrapping extension build tree...', $tester->getDisplay());
        self::assertStringContainsString('discovery_cache.json', $tester->getDisplay());
        self::assertStringContainsString('accepted_candidates.json', $tester->getDisplay());
        self::assertStringContainsString('allowed_classes.json', $tester->getDisplay());
        self::assertStringNotContainsString('Running 2 parallel discovery worker(s)...', $tester->getDisplay());

        $summary = json_decode((string) file_get_contents($metadataDir . '/build_summary.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $summary['generated_classes']);
        self::assertSame(1, $summary['candidate_classes']);

        $classmap = json_decode((string) file_get_contents($metadataDir . '/classmap.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QPoint'], array_column($classmap, 'class'));
    }

    public function testBuildGeneratesAbstractShellsAndConcreteChildren(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/abstract-qt';
        $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-abstract-' . bin2hex(random_bytes(4));
        $outputDir = $buildRoot . '/ext';
        $metadataDir = $buildRoot . '/generated';
        $bootstrapper = new FakeExtensionBootstrapper();

        $command = new BuildCommand(FakeSystemInformation::passing(), $bootstrapper);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertFileExists($outputDir . '/classes/qt_qabstractshell.cpp');
        self::assertFileExists($outputDir . '/classes/qt_qabstractparentthing.cpp');
        self::assertFileExists($outputDir . '/classes/qt_qconcretechildthing.cpp');

        $classmap = json_decode((string) file_get_contents($metadataDir . '/classmap.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['QAbstractParentThing', 'QAbstractShell', 'QConcreteChildThing'],
            array_column($classmap, 'class'),
        );

        $allowedClasses = json_decode((string) file_get_contents($metadataDir . '/allowed_classes.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QAbstractParentThing', 'QAbstractShell', 'QConcreteChildThing'], $allowedClasses);

        $skippedClasses = json_decode((string) file_get_contents($metadataDir . '/skipped_classes.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $skippedClasses);

        $abstractStub = (string) file_get_contents($outputDir . '/classes/qt_qabstractparentthing.stub.php');
        self::assertStringContainsString('abstract class QAbstractParentThing', $abstractStub);
    }

    public function testBuildFailsWhenBootstrapStepFails(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
        $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-fail-' . bin2hex(random_bytes(4));
        $outputDir = $buildRoot . '/ext';
        $metadataDir = $buildRoot . '/generated';

        $bootstrapper = new FakeExtensionBootstrapper();
        $bootstrapper->failureMessage = 'configure failed';

        $command = new BuildCommand(FakeSystemInformation::passing(), $bootstrapper);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]);

        self::assertSame(Command::FAILURE, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('configure failed', $tester->getDisplay());

        $summary = json_decode((string) file_get_contents($metadataDir . '/build_summary.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('configure failed', $summary['bootstrap_error']);
    }

    public function testBuildRewritesCachedAllowListToActualGeneratedClasses(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-stable-' . bin2hex(random_bytes(4));
        $outputDir = $buildRoot . '/ext';
        $metadataDir = $buildRoot . '/generated';
        mkdir($metadataDir, 0755, true);

        $cache = [
            'modules' => ['QtCore'],
            'qt_path' => $fixtureRoot,
            'candidate_count' => 2,
            'accepted_candidates' => [
                [
                    'module' => 'QtCore',
                    'class' => 'QCStringHolder',
                    'public_header' => $fixtureRoot . '/include/QtCore/QCStringHolder',
                    'parse_header' => $fixtureRoot . '/include/QtCore/qcstringholder.h',
                ],
                [
                    'module' => 'QtCore',
                    'class' => 'QChildThing',
                    'public_header' => $fixtureRoot . '/include/QtCore/QChildThing',
                    'parse_header' => $fixtureRoot . '/include/QtCore/qchildthing.h',
                ],
            ],
            'skipped_classes' => [],
            'allowed_classes' => ['QCStringHolder', 'QChildThing'],
        ];

        file_put_contents($metadataDir . '/discovery_cache.json', json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($metadataDir . '/accepted_candidates.json', json_encode($cache['accepted_candidates'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($metadataDir . '/allowed_classes.json', json_encode($cache['allowed_classes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $bootstrapper = new FakeExtensionBootstrapper();
        $command = new BuildCommand(FakeSystemInformation::passing(), $bootstrapper);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('Using cached build metadata:', $tester->getDisplay());
        self::assertStringContainsString('Regenerating against actual generated dependency set', $tester->getDisplay());

        $allowedClasses = json_decode((string) file_get_contents($metadataDir . '/allowed_classes.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QCStringHolder'], $allowedClasses);

        $acceptedCandidates = json_decode((string) file_get_contents($metadataDir . '/accepted_candidates.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QCStringHolder'], array_column($acceptedCandidates, 'class'));

        $classmap = json_decode((string) file_get_contents($metadataDir . '/classmap.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['QCStringHolder'], array_column($classmap, 'class'));

        $skippedClasses = json_decode((string) file_get_contents($metadataDir . '/skipped_classes.json'), true, 512, JSON_THROW_ON_ERROR);
        $skippedByClass = [];
        foreach ($skippedClasses as $skippedClass) {
            $skippedByClass[$skippedClass['class']] = $skippedClass['reason_code'];
        }
        self::assertSame('unsupported_parent_class', $skippedByClass['QChildThing'] ?? null);

        $summary = json_decode((string) file_get_contents($metadataDir . '/build_summary.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $summary['generation_passes']);
        self::assertSame(1, $summary['generated_classes']);
        self::assertSame(1, $summary['skipped_classes']);
    }
}
