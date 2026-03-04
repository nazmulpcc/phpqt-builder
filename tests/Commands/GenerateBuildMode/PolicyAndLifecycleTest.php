<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Commands\GenerateCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
it('skips template classes', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qtemplatething.h',
            'class' => 'QTemplateThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QTemplateThing',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('skipped', $payload['status']);
        Assert::assertSame('template_class', $payload['reason_code']);
        Assert::assertFileDoesNotExist($outputDir . '/classes/qt_qtemplatething.cpp');
});

it('skips classes with unsupported parents', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qchildthing.h',
            'class' => 'QChildThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QChildThing',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('skipped', $payload['status']);
        Assert::assertSame('unsupported_parent_class', $payload['reason_code']);
        Assert::assertFileDoesNotExist($outputDir . '/classes/qt_qchildthing.cpp');
});

it('detects qdisablecopy and skips copy constructor exposure', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $service = new ClassGenerationService();
        $result = $service->generate(
            $fixtureRoot . '/include/QtCore/qnocopything.h',
            'QNoCopyThing',
            [$fixtureRoot . '/include', $fixtureRoot . '/include/QtCore'],
            ['QNoCopyThing'],
        );
    
        Assert::assertSame('ok', $result->status);
        Assert::assertNotNull($result->phpClass);
        Assert::assertFalse($result->phpClass->isCopyConstructible);
        Assert::assertCount(1, array_values(array_filter(
            $result->phpClass->methods,
            static fn(\QtBuilder\Definition\PhpMethod $method): bool => $method->name === 'value',
        )));
});

it('skips protected default constructors and keeps public constructors', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qprotecteddefaultthing.h',
            'class' => 'QProtectedDefaultThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QProtectedDefaultThing',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('QProtectedDefaultThing', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('non_public_constructor', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotecteddefaultthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotecteddefaultthing.cpp');
    
        Assert::assertStringContainsString('public function __construct(int $value)', $stub);
        Assert::assertStringContainsString('new QProtectedDefaultThing((int)value)', $cpp);
        Assert::assertStringNotContainsString('new QProtectedDefaultThing()', $cpp);
});

it('skips direct construction and delete for private lifecycle classes', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qprivatelifecyclething.h',
            'class' => 'QPrivateLifecycleThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QPrivateLifecycleThing',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('QPrivateLifecycleThing', array_column($payload['skipped_methods'], 'name'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprivatelifecyclething.cpp');
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QPrivateLifecycleThing, __construct)', $cpp);
        Assert::assertStringNotContainsString('delete intern->native_ptr;', $cpp);
        Assert::assertStringContainsString('RETURN_LONG((zend_long)(QPrivateLifecycleThing::version()));', $cpp);
});

it('skips delete when the destructor is in an implicit private section', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qdefaultprivatelifecyclething.h',
            'class' => 'QDefaultPrivateLifecycleThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QDefaultPrivateLifecycleThing',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('QDefaultPrivateLifecycleThing', array_column($payload['skipped_methods'], 'name'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qdefaultprivatelifecyclething.cpp');
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QDefaultPrivateLifecycleThing, __construct)', $cpp);
        Assert::assertStringNotContainsString('delete intern->native_ptr;', $cpp);
        Assert::assertStringContainsString('RETURN_LONG((zend_long)(QDefaultPrivateLifecycleThing::version()));', $cpp);
});

it('skips qt disambiguation tag parameters', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qdisambiguationholder.h',
            'class' => 'QDisambiguationHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QDisambiguationHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('count', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_parameter_type', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qdisambiguationholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qdisambiguationholder.cpp');
    
        Assert::assertStringContainsString('public function value(): int {}', $stub);
        Assert::assertStringNotContainsString('public function count', $stub);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QDisambiguationHolder, count)', $cpp);
});
