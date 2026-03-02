<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Commands;

use PHPUnit\Framework\TestCase;
use QtBuilder\Commands\GenerateCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateCommandBuildModeTest extends TestCase
{
    public function testGenerateBuildModeReturnsJsonAndWritesFiles(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
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

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertSame('QPoint', $payload['class']);
        self::assertFileExists($outputDir . '/classes/qt_qpoint.cpp');
        self::assertFileExists($outputDir . '/classes/qt_qpoint.h');
        self::assertFileExists($outputDir . '/classes/qt_qpoint.stub.php');

        $skipNames = array_column($payload['skipped_methods'], 'name');
        self::assertContains('rx', $skipNames);
    }

    public function testGenerateBuildModeUsesNullableUnionForOptionalValueObjectParameters(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qabstractitemmodel.h',
            'class' => 'QAbstractItemModel',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QAbstractItemModel,QModelIndex',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qabstractitemmodel.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qabstractitemmodel.cpp');

        self::assertStringContainsString('QModelIndex|null $parent = null', $stub);
        self::assertStringContainsString('(parent != NULL ? *qt_qmodelindex_from_obj(Z_OBJ_P(parent))->native_ptr : QModelIndex())', $cpp);
    }

    public function testGenerateBuildModeUsesNullableUnionForOptionalQObjectParameters(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

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
            '--allowed-classes' => 'QTree,QNode',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qtree.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qtree.cpp');

        self::assertStringContainsString('QNode|null $node = null', $stub);
        self::assertStringContainsString('Z_PARAM_OBJECT_OF_CLASS_OR_NULL(node, qt_ce_QNode)', $cpp);
        self::assertStringContainsString('(node != NULL ? qt_qnode_from_obj(Z_OBJ_P(node))->native_ptr : NULL)', $cpp);
    }

    public function testGenerateBuildModeCanLoadAllowedClassesFromJsonFile(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
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

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertFileExists($outputDir . '/classes/qt_qtree.cpp');
    }

    public function testGenerateBuildModeSkipsAbstractClasses(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qabstractthing.h',
            'class' => 'QAbstractThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QAbstractThing',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('skipped', $payload['status']);
        self::assertSame('abstract_class', $payload['reason_code']);
        self::assertFileDoesNotExist($outputDir . '/classes/qt_qabstractthing.cpp');
    }

    public function testGenerateBuildModeSkipsProtectedMethods(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qprotectedthing.h',
            'class' => 'QProtectedThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QProtectedThing',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('tweak', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('non_public_method', array_column($payload['skipped_methods'], 'reason_code'));

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedthing.cpp');
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QProtectedThing, tweak)', $cpp);
    }

    public function testGenerateBuildModeCastsEnumParametersBackToNativeTypes(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qenumholder.h',
            'class' => 'QEnumHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QEnumHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qenumholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qenumholder.cpp');

        self::assertStringContainsString('public function setMode(int $mode): void {}', $stub);
        self::assertStringContainsString('intern->native_ptr->setMode((QEnumHolder::Mode)mode);', $cpp);
    }

    public function testGenerateBuildModeSkipsTemplateClasses(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
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

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('skipped', $payload['status']);
        self::assertSame('template_class', $payload['reason_code']);
        self::assertFileDoesNotExist($outputDir . '/classes/qt_qtemplatething.cpp');
    }
}
