<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Commands\GenerateCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
it('returns json and writes files in build mode', function (): void {
        $fixtureRoot = qt_fixture_path('qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qpoint.h',
            'class' => 'QPoint',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QPoint',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertSame('QPoint', $payload['class']);
        Assert::assertFileExists($outputDir . '/classes/qt_qpoint.cpp');
        Assert::assertFileExists($outputDir . '/classes/qt_qpoint.h');
        Assert::assertFileExists($outputDir . '/classes/qt_qpoint.stub.php');
    
        $skipNames = array_column($payload['skipped_methods'], 'name');
        Assert::assertContains('rx', $skipNames);
});

it('returns json without writing files in probe mode', function (): void {
        $fixtureRoot = qt_fixture_path('qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-probe-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qpoint.h',
            'class' => 'QPoint',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--worker-mode' => 'probe',
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QPoint',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertSame('QPoint', $payload['class']);
        Assert::assertArrayNotHasKey('generated_files', $payload);
        Assert::assertFileDoesNotExist($outputDir . '/classes/qt_qpoint.cpp');
        Assert::assertFileDoesNotExist($outputDir . '/classes/qt_qpoint.h');
        Assert::assertFileDoesNotExist($outputDir . '/classes/qt_qpoint.stub.php');
});

it('uses explicit includes even when qt path is invalid', function (): void {
        $fixtureRoot = qt_fixture_path('qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-includes-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qpoint.h',
            'class' => 'QPoint',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QPoint',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertFileExists($outputDir . '/classes/qt_qpoint.cpp');
});

it('can load allowed classes from a json file', function (): void {
        $fixtureRoot = qt_fixture_path('qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        $allowedClassesFile = $outputDir . '/allowed_classes.json';
        mkdir($outputDir, 0755, true);
        file_put_contents($allowedClassesFile, json_encode(['QTree', 'QNode'], JSON_THROW_ON_ERROR));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qtree.h',
            'class' => 'QTree',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes-file' => $allowedClassesFile,
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertFileExists($outputDir . '/classes/qt_qtree.cpp');
});
