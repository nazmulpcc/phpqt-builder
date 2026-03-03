<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Commands;

use PHPUnit\Framework\TestCase;
use QtBuilder\Build\ClassGenerationService;
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

    public function testGenerateProbeModeReturnsJsonWithoutWritingFiles(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
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

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertSame('QPoint', $payload['class']);
        self::assertArrayNotHasKey('generated_files', $payload);
        self::assertFileDoesNotExist($outputDir . '/classes/qt_qpoint.cpp');
        self::assertFileDoesNotExist($outputDir . '/classes/qt_qpoint.h');
        self::assertFileDoesNotExist($outputDir . '/classes/qt_qpoint.stub.php');
    }

    public function testGenerateBuildModeUsesExplicitIncludesEvenWhenQtPathIsInvalid(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/qt';
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

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertFileExists($outputDir . '/classes/qt_qpoint.cpp');
    }

    public function testGenerateBuildModeSkipsChildMethodsWithIncompatibleInheritedSignatures(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-inheritance-' . bin2hex(random_bytes(4));
        $classHeadersFile = $outputDir . '/class_headers.json';
        mkdir($outputDir, 0755, true);
        file_put_contents($classHeadersFile, json_encode([
            'QBaseSetter' => $fixtureRoot . '/include/QtCore/qbasesetter.h',
            'QParentSetter' => $fixtureRoot . '/include/QtCore/qparentsetter.h',
            'QChildSetter' => $fixtureRoot . '/include/QtCore/qchildsetter.h',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qchildsetter.h',
            'class' => 'QChildSetter',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QBaseSetter,QParentSetter,QChildSetter',
            '--class-headers-file' => $classHeadersFile,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('setPeer', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('peer', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('incompatible_inherited_method', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qchildsetter.stub.php');
        self::assertStringContainsString('class QChildSetter extends QParentSetter', $stub);
        self::assertStringContainsString('public function childId(): int {}', $stub);
        self::assertStringNotContainsString('public function setPeer', $stub);
        self::assertStringNotContainsString('public function peer', $stub);
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

    public function testGenerateBuildModeSkipsMethodsWithValueObjectDependenciesOutsideAllowList(): void
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
            '--allowed-classes' => 'QAbstractItemModel',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('skipped', $payload['status']);
        self::assertSame('no_supported_methods', $payload['reason_code']);
        self::assertFileDoesNotExist($outputDir . '/classes/qt_qabstractitemmodel.cpp');
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

    public function testGenerateBuildModeTransfersOwnershipForLayoutAttachmentMethods(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/layout-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-layout-ownership-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtWidgets/qlayoutownership.h',
            'class' => 'QBoxLayout',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
                $fixtureRoot . '/include/QtWidgets',
            ],
            '--module' => 'QtWidgets',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QObject,QWidget,QLayout,QBoxLayout,QGridLayout',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $boxLayoutCpp = (string) file_get_contents($outputDir . '/classes/qt_qboxlayout.cpp');
        self::assertStringContainsString('intern->native_ptr->addWidget(qt_qwidget_from_obj(Z_OBJ_P(w))->native_ptr, (int)stretch);', $boxLayoutCpp);
        self::assertStringContainsString('qt_qwidget_object *_qt_owned_arg_0 = qt_qwidget_from_obj(Z_OBJ_P(w));', $boxLayoutCpp);
        self::assertStringContainsString('_qt_owned_arg_0->prevent_destroy = true;', $boxLayoutCpp);
        self::assertStringContainsString('intern->native_ptr->addLayout(qt_qlayout_from_obj(Z_OBJ_P(layout))->native_ptr, (int)stretch);', $boxLayoutCpp);
        self::assertStringContainsString('qt_qlayout_object *_qt_owned_arg_0 = qt_qlayout_from_obj(Z_OBJ_P(layout));', $boxLayoutCpp);

        $tester = new CommandTester(new GenerateCommand(FakeSystemInformation::passing()));
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtWidgets/qlayoutownership.h',
            'class' => 'QWidget',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
                $fixtureRoot . '/include/QtWidgets',
            ],
            '--module' => 'QtWidgets',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QObject,QWidget,QLayout,QBoxLayout,QGridLayout',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $widgetCpp = (string) file_get_contents($outputDir . '/classes/qt_qwidget.cpp');
        self::assertStringContainsString('intern->native_ptr->setLayout(qt_qlayout_from_obj(Z_OBJ_P(layout))->native_ptr);', $widgetCpp);
        self::assertStringContainsString('qt_qlayout_object *_qt_owned_arg_0 = qt_qlayout_from_obj(Z_OBJ_P(layout));', $widgetCpp);
        self::assertStringContainsString('_qt_owned_arg_0->prevent_destroy = true;', $widgetCpp);
    }

    public function testGenerateBuildModeCastsConstObjectPointerReturnsForWrapping(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/const-pointer';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qconstnodeholder.h',
            'class' => 'QNodeConstHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QNodeConstHolder,QNode',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qnodeconstholder.cpp');
        self::assertStringContainsString('const QNode * _result = intern->native_ptr->node();', $cpp);
        self::assertStringContainsString('qt_qnode_wrap_native(return_value, const_cast<QNode *>(_result), qt_ce_QNode, true);', $cpp);
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

    public function testGenerateBuildModeGeneratesAbstractClassesAndRetainsPureVirtualMethods(): void
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
        self::assertSame('ok', $payload['status']);
        self::assertContains('QAbstractThing', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('abstract_constructor', array_column($payload['skipped_methods'], 'reason_code'));
        self::assertFileExists($outputDir . '/classes/qt_qabstractthing.cpp');

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qabstractthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qabstractthing.cpp');

        self::assertStringContainsString('abstract class QAbstractThing', $stub);
        self::assertStringNotContainsString('public function __construct()', $stub);
        self::assertStringContainsString('public function size(): int {}', $stub);
        self::assertStringContainsString('class qt_php_QAbstractThing : public QAbstractThing', $cpp);
        self::assertStringContainsString('if (!qt_method_is_overridden(this->php_object, qt_ce_QAbstractThing, "size"))', $cpp);
        self::assertStringContainsString('ce_flags |= ZEND_ACC_ABSTRACT;', $cpp);
    }

    public function testGenerateBuildModeGeneratesAbstractShellClassesWithSupportedPureVirtuals(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/abstract-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qabstractshell.h',
            'class' => 'QAbstractShell',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QAbstractShell',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertFileExists($outputDir . '/classes/qt_qabstractshell.cpp');

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qabstractshell.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qabstractshell.cpp');

        self::assertStringContainsString('abstract class QAbstractShell', $stub);
        self::assertStringNotContainsString('function __construct', $stub);
        self::assertStringContainsString('public function size(): int {}', $stub);
        self::assertStringContainsString('class qt_php_QAbstractShell : public QAbstractShell', $cpp);
    }

    public function testGenerateBuildModeAllowsConcreteChildrenOfAbstractParents(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/abstract-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qabstractparentthing.h',
            'class' => 'QConcreteChildThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QAbstractParentThing,QConcreteChildThing',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertFileExists($outputDir . '/classes/qt_qconcretechildthing.cpp');

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qconcretechildthing.stub.php');
        self::assertStringContainsString('class QConcreteChildThing extends QAbstractParentThing', $stub);
    }

    public function testGenerateBuildModeGeneratesProtectedMethodsThroughAccessShims(): void
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
        self::assertNotContains('tweak', array_column($payload['skipped_methods'], 'name'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotectedthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedthing.cpp');

        self::assertStringContainsString('protected function tweak(): void {}', $stub);
        self::assertStringContainsString('class qt_access_QProtectedThing : public QProtectedThing', $cpp);
        self::assertStringContainsString('intern->native_ptr = new qt_access_QProtectedThing()', $cpp);
        self::assertStringContainsString('static_cast<qt_access_QProtectedThing *>(intern->native_ptr)->qt_access_tweak_0()', $cpp);
    }

    public function testGenerateBuildModeGeneratesProtectedVirtualMethodsWithNativeTrampolines(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qprotectedvirtualthing.h',
            'class' => 'QProtectedVirtualThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QProtectedVirtualThing',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertNotContains('value', array_column($payload['skipped_methods'], 'name'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotectedvirtualthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedvirtualthing.cpp');

        self::assertStringContainsString('protected function value(): int {}', $stub);
        self::assertStringContainsString('class qt_access_QProtectedVirtualThing : public QProtectedVirtualThing', $cpp);
        self::assertStringContainsString('class qt_php_QProtectedVirtualThing : public qt_access_QProtectedVirtualThing', $cpp);
        self::assertStringContainsString('int value() const override', $cpp);
        self::assertStringContainsString('QProtectedVirtualThing::value()', $cpp);
        self::assertStringContainsString('zend_hash_str_find_ptr_lc(&ce->function_table, function_name, strlen(function_name))', $cpp);
        self::assertStringContainsString('zend_call_known_function(method, object, object->ce, retval, param_count, params, NULL);', $cpp);
    }

    public function testGenerateBuildModeGeneratesStaticProtectedAccessHelpers(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qprotectedstaticthing.h',
            'class' => 'QProtectedStaticThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QProtectedStaticThing',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotectedstaticthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedstaticthing.cpp');

        self::assertStringContainsString('protected static function doThing(int $value): void {}', $stub);
        self::assertStringContainsString('static inline void qt_access_doThing_0(int _qt_p0)', $cpp);
        self::assertStringContainsString('qt_access_QProtectedStaticThing::qt_access_doThing_0((int)value);', $cpp);
    }

    public function testGenerateBuildModeMarshalsConstCharPointerVirtualArgsToPhpStrings(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qprotectedstringvirtualthing.h',
            'class' => 'QProtectedStringVirtualThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QProtectedStringVirtualThing',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedstringvirtualthing.cpp');

        self::assertStringContainsString('if (_qt_p0 != NULL) {', $cpp);
        self::assertStringContainsString('ZVAL_STRING(&_qt_params[0], _qt_p0);', $cpp);
        self::assertStringContainsString('ZVAL_NULL(&_qt_params[0]);', $cpp);
    }

    public function testGenerateBuildModeErasesProtectedNestedEnumTypesAtShimBoundary(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qprotectedenumthing.h',
            'class' => 'QProtectedEnumThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QProtectedEnumThing',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotectedenumthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedenumthing.cpp');

        self::assertStringContainsString('protected function supportsExtension(int $extension): bool {}', $stub);
        self::assertStringContainsString('protected function setExtension(int $extension): void {}', $stub);
        self::assertStringContainsString('inline bool qt_access_supportsExtension_0(zend_long _qt_p0) const', $cpp);
        self::assertStringContainsString('inline void qt_access_setExtension_0(zend_long _qt_p0)', $cpp);
        self::assertStringContainsString('QProtectedEnumThing::supportsExtension((QProtectedEnumThing::Extension)((int)(_qt_p0)))', $cpp);
        self::assertStringContainsString('QProtectedEnumThing::setExtension((QProtectedEnumThing::Extension)((int)(_qt_p0)))', $cpp);
        self::assertStringNotContainsString('qt_access_supportsExtension_0((QProtectedEnumThing::Extension)', $cpp);
        self::assertStringNotContainsString('qt_access_setExtension_0((QProtectedEnumThing::Extension)', $cpp);
    }

    public function testGenerateBuildModeDoesNotGenerateTrampolinesForFinalVirtualMethods(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qfinalvirtualthing.h',
            'class' => 'QFinalVirtualThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QFinalVirtualThing,QFinalVirtualBase',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qfinalvirtualthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qfinalvirtualthing.cpp');

        self::assertStringContainsString('public function value(): int {}', $stub);
        self::assertStringNotContainsString('class qt_php_QFinalVirtualThing', $cpp);
        self::assertStringContainsString('RETURN_LONG((zend_long)(intern->native_ptr->value()));', $cpp);
    }

    public function testGenerateBuildModeUsesShimOnlyForProtectedOverloadBranch(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qmixedaccessoverloadthing.h',
            'class' => 'QMixedAccessOverloadThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QMixedAccessOverloadThing',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qmixedaccessoverloadthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qmixedaccessoverloadthing.cpp');

        self::assertStringContainsString('public function addItem(int $value, int $row = 0): void {}', $stub);
        self::assertStringContainsString('class qt_access_QMixedAccessOverloadThing : public QMixedAccessOverloadThing', $cpp);
        self::assertStringContainsString('inline void qt_access_addItem_1(int _qt_p0)', $cpp);
        self::assertStringContainsString('intern->native_ptr->addItem((int)value, (int)row);', $cpp);
        self::assertStringContainsString('static_cast<qt_access_QMixedAccessOverloadThing *>(intern->native_ptr)->qt_access_addItem_1((int)value);', $cpp);
        self::assertStringNotContainsString('intern->native_ptr->QMixedAccessOverloadThing::addItem((int)value);', $cpp);
    }

    public function testGenerateBuildModeTransfersQEventOwnershipForPostEvent(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qcoreapplicationmini.h',
            'class' => 'QCoreApplication',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QCoreApplication,QObject,QEvent',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qcoreapplication.cpp');

        self::assertStringContainsString('QCoreApplication::postEvent(qt_qobject_from_obj(Z_OBJ_P(receiver))->native_ptr, qt_qevent_from_obj(Z_OBJ_P(event))->native_ptr, (int)priority);', $cpp);
        self::assertStringContainsString('qt_qevent_object *_qt_posted_event = qt_qevent_from_obj(Z_OBJ_P(event));', $cpp);
        self::assertStringContainsString('_qt_posted_event->prevent_destroy = true;', $cpp);
        self::assertStringContainsString('_qt_posted_event->native_ptr = NULL;', $cpp);
    }

    public function testGenerateBuildModeGeneratesSignalApisAndRetainsProtectedSlots(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/signals-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-signals-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qsignalfixture.h',
            'class' => 'QSignalFixture',
            '--qt-path' => $fixtureRoot,
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QSignalFixture',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertNotContains('resetValue', array_column($payload['skipped_methods'], 'name'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qsignalfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qsignalfixture.cpp');
        self::assertFileExists($outputDir . '/classes/qt_qmetaobjectconnection.h');
        self::assertFileExists($outputDir . '/classes/qt_qmetaobjectconnection.cpp');
        self::assertFileExists($outputDir . '/classes/qt_qmetaobjectconnection.stub.php');

        self::assertStringContainsString('protected function resetValue(): void {}', $stub);
        self::assertStringContainsString('public function connect(string $signalSignature, callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        self::assertStringContainsString('public function disconnect(\Qt\Core\QMetaObjectConnection $connection): bool {}', $stub);
        self::assertStringContainsString('public function onTriggered(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        self::assertStringContainsString('public function onValueChanged(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        self::assertStringNotContainsString('function triggered(): void {}', $stub);
        self::assertStringNotContainsString('function valueChanged(int $value): void {}', $stub);
        self::assertStringContainsString('class qt_access_QSignalFixture : public QSignalFixture', $cpp);
        self::assertStringContainsString('qt_access_resetValue_0', $cpp);

        self::assertStringContainsString('ZEND_METHOD(Qt_Core_QSignalFixture, connect)', $cpp);
        self::assertStringContainsString('ZEND_METHOD(Qt_Core_QSignalFixture, disconnect)', $cpp);
        self::assertStringContainsString('#include "qt_qmetaobjectconnection.h"', $cpp);
        self::assertStringContainsString('qt_track_native_instance(intern->native_ptr);', $cpp);
        self::assertStringContainsString('qt_should_delete_native', $cpp);
        self::assertStringContainsString('if (qt_should_delete_native(intern->native_ptr, intern->prevent_destroy)) {', $cpp);
        self::assertStringContainsString('zend_string_equals_literal(signalSignature, "triggered()")', $cpp);
        self::assertStringContainsString('static_cast<void (QSignalFixture::*)(int)>(&QSignalFixture::valueChanged)', $cpp);
        self::assertStringContainsString('ZEND_ME(Qt_Core_QSignalFixture, onTriggered,', $cpp);
        self::assertStringContainsString('qt_qmetaobjectconnection_wrap(return_value, _qt_connection);', $cpp);
        self::assertStringContainsString('ZEND_METHOD(Qt_Core_QSignalFixture, resetValue)', $cpp);
        self::assertStringNotContainsString('zend_fcall_info_args_clear(&callback->fci, true);', $cpp);
        self::assertStringContainsString('callback->fci.params = previousParams;', $cpp);
        self::assertStringContainsString('callback->fci.param_count = previousParamCount;', $cpp);
    }

    public function testGenerateBuildModeDisambiguatesOverloadedSignalSugarMethods(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/signals-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-overloaded-signals-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qoverloadedsignalfixture.h',
            'class' => 'QOverloadedSignalFixture',
            '--qt-path' => $fixtureRoot,
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QOverloadedSignalFixture',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qoverloadedsignalfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qoverloadedsignalfixture.cpp');

        self::assertStringContainsString('public function onValueChangedInt(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        self::assertStringContainsString('public function onValueChangedBool(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        self::assertStringContainsString('zend_string_equals_literal(signalSignature, "valueChanged(int)")', $cpp);
        self::assertStringContainsString('zend_string_equals_literal(signalSignature, "valueChanged(bool)")', $cpp);
    }

    public function testGenerateBuildModeUsesUniqueUtf8TempNamesForMultiQStringSignalCallbacks(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/signals-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-qstring-signals-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qstringsignalfixture.h',
            'class' => 'QStringSignalFixture',
            '--qt-path' => $fixtureRoot,
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QStringSignalFixture',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qstringsignalfixture.cpp');
        self::assertStringContainsString('QByteArray _qt_utf8_0 = _qt_arg_0.toUtf8();', $cpp);
        self::assertStringContainsString('QByteArray _qt_utf8_1 = _qt_arg_1.toUtf8();', $cpp);
        self::assertStringContainsString('QByteArray _qt_utf8_2 = _qt_arg_2.toUtf8();', $cpp);
    }

    public function testGenerateBuildModeSkipsSignalsWithNonCopyableCallbackParameters(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/signals-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-nocopy-signals-' . bin2hex(random_bytes(4));
        $classHeadersFile = $outputDir . '/class_headers.json';

        mkdir($outputDir, 0755, true);
        file_put_contents($classHeadersFile, json_encode([
            'QSignalNoCopyFixture' => $fixtureRoot . '/include/QtCore/qsignalnocopyfixture.h',
            'QSignalNoCopyValue' => $fixtureRoot . '/include/QtCore/qsignalnocopyfixture.h',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qsignalnocopyfixture.h',
            'class' => 'QSignalNoCopyFixture',
            '--qt-path' => $fixtureRoot,
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QSignalNoCopyFixture,QSignalNoCopyValue',
            '--class-headers-file' => $classHeadersFile,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('blocked', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('unsupported_signal_callback_parameter', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qsignalnocopyfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qsignalnocopyfixture.cpp');

        self::assertStringNotContainsString('public function connect(', $stub);
        self::assertStringNotContainsString('public function onBlocked(', $stub);
        self::assertStringNotContainsString('zend_string_equals_literal(signalSignature, "blocked(QSignalNoCopyValue)")', $cpp);
        self::assertStringNotContainsString('new QSignalNoCopyValue(_qt_arg_0)', $cpp);
    }

    public function testGenerateBuildModeIncludesInheritedSignalsInConnectApi(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/signals-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-inherited-signals-' . bin2hex(random_bytes(4));
        $classHeadersFile = $outputDir . '/class_headers.json';

        mkdir($outputDir, 0755, true);
        file_put_contents($classHeadersFile, json_encode([
            'QSignalBaseFixture' => $fixtureRoot . '/include/QtCore/qsignalbasefixture.h',
            'QSignalChildFixture' => $fixtureRoot . '/include/QtCore/qsignalchildfixture.h',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qsignalchildfixture.h',
            'class' => 'QSignalChildFixture',
            '--qt-path' => $fixtureRoot,
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--class-headers-file' => $classHeadersFile,
            '--allowed-classes' => 'QSignalBaseFixture,QSignalChildFixture',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qsignalchildfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qsignalchildfixture.cpp');

        self::assertStringContainsString('public function onTriggered(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        self::assertStringContainsString('public function onChanged(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        self::assertStringContainsString('zend_string_equals_literal(signalSignature, "triggered()")', $cpp);
        self::assertStringContainsString('zend_string_equals_literal(signalSignature, "changed(int)")', $cpp);
        self::assertStringContainsString('static_cast<void (QSignalBaseFixture::*)()>(&QSignalBaseFixture::triggered)', $cpp);
    }

    public function testGenerateBuildModePreservesConstSignalMemberPointers(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/signals-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-const-signals-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qconstsignalfixture.h',
            'class' => 'QSignalConstFixture',
            '--qt-path' => $fixtureRoot,
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QSignalConstFixture',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qsignalconstfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qsignalconstfixture.cpp');

        self::assertStringContainsString('public function connect(string $signalSignature, callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        self::assertStringContainsString('public function disconnect(\Qt\Core\QMetaObjectConnection $connection): bool {}', $stub);
        self::assertStringContainsString('public function onChanged(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        self::assertStringContainsString('static_cast<void (QSignalConstFixture::*)(int) const>(&QSignalConstFixture::changed)', $cpp);
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
        self::assertStringContainsString('intern->native_ptr->setMode((QEnumHolder::Mode)((int)(mode)));', $cpp);
        self::assertStringContainsString('RETURN_LONG((zend_long)(intern->native_ptr->mode()));', $cpp);
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

    public function testGenerateBuildModeSkipsClassesWithUnsupportedParent(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
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

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('skipped', $payload['status']);
        self::assertSame('unsupported_parent_class', $payload['reason_code']);
        self::assertFileDoesNotExist($outputDir . '/classes/qt_qchildthing.cpp');
    }

    public function testGenerateBuildModeHandlesConstCharPointerStringReturns(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qcstringholder.h',
            'class' => 'QCStringHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QCStringHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qcstringholder.cpp');
        self::assertStringContainsString('auto _result = intern->native_ptr->bits();', $cpp);
        self::assertStringContainsString('RETURN_STRING(_result);', $cpp);
        self::assertStringNotContainsString('toUtf8()', $cpp);
    }

    public function testGenerateBuildModeTreatsQBitArrayFactoryAsValueReturnAndSkipsBoolOutParameter(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qbitarray.h',
            'class' => 'QBitArray',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QBitArray',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('toUInt32', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('unsupported_output_parameter', array_column($payload['skipped_methods'], 'reason_code'));

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qbitarray.cpp');
        self::assertStringContainsString('QBitArray _result = QBitArray::fromBits(ZSTR_VAL(data), (int)len);', $cpp);
        self::assertStringContainsString('_ret_intern->native_ptr = new QBitArray(_result);', $cpp);
        self::assertStringNotContainsString('QBitArray *_result = QBitArray::fromBits', $cpp);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QBitArray, toUInt32)', $cpp);
    }

    public function testGenerateBuildModeSkipsObjectDoublePointerOutParameters(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qdoublepointerholder.h',
            'class' => 'QDoublePointerHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QDoublePointerHolder,QDoublePointerPeer',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('locate', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('unsupported_output_parameter', array_column($payload['skipped_methods'], 'reason_code'));

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qdoublepointerholder.cpp');
        self::assertStringContainsString('ZEND_METHOD(Qt_Core_QDoublePointerHolder, value)', $cpp);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QDoublePointerHolder, locate)', $cpp);
    }

    public function testGenerateBuildModeUsesFromIntForFlagAliases(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qflagholder.h',
            'class' => 'QFlagHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QFlagHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qflagholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qflagholder.cpp');

        self::assertStringContainsString('public function setModes(int $modes): void {}', $stub);
        self::assertStringContainsString('QFlags<QFlagHolder::Mode>::fromInt((QFlags<QFlagHolder::Mode>::Int)((int)(modes)))', $cpp);
        self::assertStringContainsString('RETURN_LONG((zend_long)(intern->native_ptr->modes()));', $cpp);
    }

    public function testGenerateBuildModeHandlesCharStringsAndSkipsNonConstReferenceParameters(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';

        $charOutputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qcharholder.h',
            'class' => 'QCharHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $charOutputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QCharHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $charPayload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $charPayload['status']);

        $charCpp = (string) file_get_contents($charOutputDir . '/classes/qt_qcharholder.cpp');
        self::assertStringContainsString('RETURN_STRINGL(&_result, 1);', $charCpp);
        self::assertStringContainsString('RETURN_STRING(_result);', $charCpp);
        self::assertStringContainsString("(ZSTR_LEN(ch) > 0 ? ZSTR_VAL(ch)[0] : '\\0')", $charCpp);

        $refOutputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qrefholder.h',
            'class' => 'QRefHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $refOutputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QRefHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $refPayload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $refPayload['status']);

        $refStub = (string) file_get_contents($refOutputDir . '/classes/qt_qrefholder.stub.php');
        $refCpp = (string) file_get_contents($refOutputDir . '/classes/qt_qrefholder.cpp');

        self::assertStringContainsString('public function swap(string $other): void {}', $refStub);
        self::assertStringContainsString('ZEND_METHOD(Qt_Core_QRefHolder, swap)', $refCpp);
        self::assertStringContainsString('QByteArray _qt_arg_0 = QByteArray(ZSTR_VAL(other), ZSTR_LEN(other));', $refCpp);
        self::assertStringNotContainsString('&$other', $refStub);
    }

    public function testGenerateBuildModeBuildsInputOnlyArgvConstructorBridge(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qargvholder.h',
            'class' => 'QArgvHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QArgvHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $header = (string) file_get_contents($outputDir . '/classes/qt_qargvholder.h');
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qargvholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qargvholder.cpp');

        self::assertStringContainsString('typedef struct _qt_argv_storage {', $header);
        self::assertStringContainsString('std::vector<QByteArray> argv_storage;', $header);
        self::assertStringContainsString('std::vector<char *> argv_pointers;', $header);
        self::assertStringContainsString('void *extra_storage;', $header);
        self::assertStringContainsString('public function __construct(int $argc = 0, array $argv = [], int $flags = 0) {}', $stub);
        self::assertStringContainsString('intern->extra_storage = new qt_argv_storage();', $cpp);
        self::assertStringContainsString('if (intern->extra_storage == NULL) {', $cpp);
        self::assertStringContainsString('intern->extra_storage = new qt_argv_storage();', $cpp);
        self::assertStringContainsString('auto *_qt_argv_storage = static_cast<qt_argv_storage *>(intern->extra_storage);', $cpp);
        self::assertStringContainsString('char ** _qt_arg_1 = NULL;', $cpp);
        self::assertStringContainsString('_qt_argv_storage->argv_storage.emplace_back("php", 3);', $cpp);
        self::assertStringContainsString('_qt_arg_0 = (int)_qt_argv_storage->argv_storage.size();', $cpp);
    }

    public function testGenerateBuildModeSkipsNestedResultTypesButKeepsNestedEnums(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qresultholder.h',
            'class' => 'QResultHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QResultHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('decode', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qresultholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qresultholder.cpp');

        self::assertStringContainsString('public function setMode(int $mode): void {}', $stub);
        self::assertStringContainsString('intern->native_ptr->setMode((QResultHolder::Mode)((int)(mode)));', $cpp);
        self::assertStringNotContainsString('fromBase64Encoding', $cpp);
        self::assertStringNotContainsString('RETURN_LONG((zend_long)(QResultHolder::decode()', $cpp);
    }

    public function testGenerateBuildModeHandlesStdStringConversions(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qstdstringholder.h',
            'class' => 'QStdStringHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QStdStringHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qstdstringholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qstdstringholder.cpp');

        self::assertStringContainsString('public static function fromStdString(string $s): QStdStringHolder {}', $stub);
        self::assertStringContainsString('public function toStdString(): string {}', $stub);
        self::assertStringContainsString('QStdStringHolder::fromStdString(std::string(ZSTR_VAL(s), ZSTR_LEN(s)))', $cpp);
        self::assertStringContainsString('RETURN_STRINGL(_result.data(), _result.size())', $cpp);
    }

    public function testGenerateBuildModeTreatsObjectReturnsWithoutPointersAsValueObjects(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qvaluereturnholder.h',
            'class' => 'QValueReturnHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QValueReturnHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qvaluereturnholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qvaluereturnholder.cpp');

        self::assertStringContainsString('public static function create(): QValueReturnHolder {}', $stub);
        self::assertStringContainsString('public function normalized(): QValueReturnHolder {}', $stub);
        self::assertStringContainsString('QValueReturnHolder _result = QValueReturnHolder::create();', $cpp);
        self::assertStringContainsString('QValueReturnHolder _result = intern->native_ptr->normalized();', $cpp);
        self::assertStringContainsString('_ret_intern->native_ptr = new QValueReturnHolder(_result);', $cpp);
        self::assertStringNotContainsString('QValueReturnHolder *_result = QValueReturnHolder::create();', $cpp);
        self::assertStringNotContainsString('QValueReturnHolder *_result = intern->native_ptr->normalized();', $cpp);
    }

    public function testGenerateBuildModeSkipsNestedStructReturnsButKeepsBareEnums(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qpartsholder.h',
            'class' => 'QPartsHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QPartsHolder,QDate',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('partsFromDate', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qpartsholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qpartsholder.cpp');

        self::assertStringContainsString('public function setFormat(int $format): void {}', $stub);
        self::assertStringContainsString('intern->native_ptr->setFormat((QPartsHolder::NameFormat)((int)(format)));', $cpp);
        self::assertStringNotContainsString('public function partsFromDate', $stub);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QPartsHolder, partsFromDate)', $cpp);
    }

    public function testGenerateBuildModeSkipsQualifiedNestedStructReturnsButKeepsQualifiedEnums(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qqualifiedtypeholder.h',
            'class' => 'QQualifiedTypeHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QQualifiedTypeHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('elementAt', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qqualifiedtypeholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qqualifiedtypeholder.cpp');

        self::assertStringContainsString('public function kind(): int {}', $stub);
        self::assertStringContainsString('public function setKind(int $kind): void {}', $stub);
        self::assertStringContainsString('RETURN_LONG((zend_long)(intern->native_ptr->kind()));', $cpp);
        self::assertStringContainsString('intern->native_ptr->setKind((QQualifiedTypeHolder::Kind)((int)(kind)));', $cpp);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QQualifiedTypeHolder, elementAt)', $cpp);
    }

    public function testClassGenerationDetectsQDisableCopyAndSkipsCopyConstructorExposure(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $service = new ClassGenerationService();
        $result = $service->generate(
            $fixtureRoot . '/include/QtCore/qnocopything.h',
            'QNoCopyThing',
            [$fixtureRoot . '/include', $fixtureRoot . '/include/QtCore'],
            ['QNoCopyThing'],
        );

        self::assertSame('ok', $result->status);
        self::assertNotNull($result->phpClass);
        self::assertFalse($result->phpClass->isCopyConstructible);
        self::assertCount(1, array_values(array_filter(
            $result->phpClass->methods,
            static fn(\QtBuilder\Definition\PhpMethod $method): bool => $method->name === 'value',
        )));
    }

    public function testGenerateBuildModeSkipsProtectedDefaultConstructorAndKeepsPublicConstructor(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
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

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('QProtectedDefaultThing', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('non_public_constructor', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotecteddefaultthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotecteddefaultthing.cpp');

        self::assertStringContainsString('public function __construct(int $value)', $stub);
        self::assertStringContainsString('new QProtectedDefaultThing((int)value)', $cpp);
        self::assertStringNotContainsString('new QProtectedDefaultThing()', $cpp);
    }

    public function testGenerateBuildModeSkipsDirectConstructionAndDeleteForPrivateLifecycleClasses(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
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

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('QPrivateLifecycleThing', array_column($payload['skipped_methods'], 'name'));

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprivatelifecyclething.cpp');
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QPrivateLifecycleThing, __construct)', $cpp);
        self::assertStringNotContainsString('delete intern->native_ptr;', $cpp);
        self::assertStringContainsString('RETURN_LONG((zend_long)(QPrivateLifecycleThing::version()));', $cpp);
    }

    public function testGenerateBuildModeSkipsDeleteWhenDestructorIsInImplicitPrivateSection(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
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

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('QDefaultPrivateLifecycleThing', array_column($payload['skipped_methods'], 'name'));

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qdefaultprivatelifecyclething.cpp');
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QDefaultPrivateLifecycleThing, __construct)', $cpp);
        self::assertStringNotContainsString('delete intern->native_ptr;', $cpp);
        self::assertStringContainsString('RETURN_LONG((zend_long)(QDefaultPrivateLifecycleThing::version()));', $cpp);
    }

    public function testGenerateBuildModeConvertsChronoDurationsToAndFromIntegers(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qchronoholder.h',
            'class' => 'QChronoHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QChronoHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qchronoholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qchronoholder.cpp');

        self::assertStringContainsString('public function setInterval(int $value): void {}', $stub);
        self::assertStringContainsString('intern->native_ptr->setInterval(std::chrono::milliseconds((std::chrono::milliseconds::rep)((int)(value))));', $cpp);
        self::assertStringContainsString('RETURN_LONG((zend_long)(intern->native_ptr->interval().count()));', $cpp);
    }

    public function testGenerateBuildModeBridgesWideStringsThroughQString(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qwidestringholder.h',
            'class' => 'QWideStringHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QWideStringHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qwidestringholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qwidestringholder.cpp');

        self::assertStringContainsString('public static function fromStdWString(string $s): QWideStringHolder {}', $stub);
        self::assertStringContainsString('QString::fromUtf8(ZSTR_VAL(s), (int)ZSTR_LEN(s)).toStdWString()', $cpp);
        self::assertStringContainsString('QString::fromStdWString(_result).toUtf8()', $cpp);
        self::assertStringContainsString('QString::fromUtf8(ZSTR_VAL(s), (int)ZSTR_LEN(s)).toStdU16String()', $cpp);
        self::assertStringContainsString('QString::fromStdU16String(_result).toUtf8()', $cpp);
        self::assertStringContainsString('QString::fromUtf8(ZSTR_VAL(s), (int)ZSTR_LEN(s)).toStdU32String()', $cpp);
        self::assertStringContainsString('QString::fromStdU32String(_result).toUtf8()', $cpp);
    }

    public function testGenerateBuildModeSkipsQtDisambiguationTagParameters(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
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

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('count', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('unsupported_parameter_type', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qdisambiguationholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qdisambiguationholder.cpp');

        self::assertStringContainsString('public function value(): int {}', $stub);
        self::assertStringNotContainsString('public function count', $stub);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QDisambiguationHolder, count)', $cpp);
    }

    public function testGenerateBuildModeReturnsQAnyStringViewViaToString(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qanystringviewholder.h',
            'class' => 'QAnyStringViewHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QAnyStringViewHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qanystringviewholder.cpp');
        self::assertStringContainsString('QByteArray _utf8 = _result.toString().toUtf8();', $cpp);
        self::assertStringNotContainsString('_result.toUtf8()', $cpp);
    }

    public function testGenerateBuildModeFiltersConnectMethodsByName(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qconnectionholder.h',
            'class' => 'QConnectionHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QConnectionHolder',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('connect', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('method_name_filtered', array_column($payload['skipped_methods'], 'reason_code'));

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qconnectionholder.cpp');
        self::assertStringContainsString('ZEND_METHOD(Qt_Core_QConnectionHolder, version)', $cpp);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QConnectionHolder, connect)', $cpp);
    }

    public function testGenerateBuildModeSkipsQCharBufferReturns(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qstringbufferholder.h',
            'class' => 'QStringBufferHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QStringBufferHolder,QChar',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('unicode', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('constData', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('unsupported_buffer_return', array_column($payload['skipped_methods'], 'reason_code'));

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qstringbufferholder.cpp');
        self::assertStringContainsString('ZEND_METHOD(Qt_Core_QStringBufferHolder, length)', $cpp);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QStringBufferHolder, unicode)', $cpp);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QStringBufferHolder, constData)', $cpp);
    }

    public function testGenerateBuildModeCopiesPointerReturnsForValueTypes(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qvariantpointerholder.h',
            'class' => 'QVariantPointerHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QVariantPointerHolder,QVariant',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qvariantpointerholder.cpp');
        self::assertStringContainsString('QVariant * _result = intern->native_ptr->current();', $cpp);
        self::assertStringContainsString('object_init_ex(return_value, qt_ce_QVariant);', $cpp);
        self::assertStringContainsString('_ret_intern->native_ptr = new QVariant(*_result);', $cpp);
        self::assertStringNotContainsString('qt_qvariant_wrap_native', $cpp);
    }

    public function testGenerateBuildModeSkipsComplexReturnsInsteadOfCastingToScalars(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qunsupportedtypes.h',
            'class' => 'QUnsupportedTypes',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QUnsupportedTypes',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));
        self::assertContains('begin', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('provider', array_column($payload['skipped_methods'], 'name'));
        self::assertContains('values', array_column($payload['skipped_methods'], 'name'));

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qunsupportedtypes.cpp');
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QUnsupportedTypes, begin)', $cpp);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QUnsupportedTypes, provider)', $cpp);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QUnsupportedTypes, values)', $cpp);
    }

    public function testGenerateBuildModeDoesNotMistakeSelfPointerConstructorForCopyConstructor(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qselfparentthing.h',
            'class' => 'QSelfParentThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QSelfParentThing',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qselfparentthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qselfparentthing.cpp');

        self::assertStringContainsString('public function __construct(QSelfParentThing|null $parent = null)', $stub);
        self::assertStringContainsString('intern->native_ptr = new QSelfParentThing((parent != NULL ? qt_qselfparentthing_from_obj(Z_OBJ_P(parent))->native_ptr : NULL));', $cpp);
    }

    public function testGenerateBuildModeGuardsInstanceMethodsWhenNativePtrIsMissing(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/quninstantiablething.h',
            'class' => 'QUninstantiableThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QUninstantiableThing',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_quninstantiablething.cpp');
        self::assertStringContainsString('zend_throw_error(NULL, "QUninstantiableThing native instance is not initialized");', $cpp);
        self::assertStringContainsString('RETURN_THROWS();', $cpp);
    }

    public function testGenerateBuildModeKeepsSupportedConstructorOverloadsWhenOneSiblingIsUnsupported(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qsizelike.h',
            'class' => 'QSizeLike',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QSizeLike',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('unsupported_parameter_type', array_column($payload['skipped_methods'], 'reason_code'));
        self::assertContains('QSizeLike', array_column($payload['skipped_methods'], 'name'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qsizelike.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qsizelike.cpp');

        self::assertStringContainsString('public function __construct(int $width = 0, int $height = 0) {}', $stub);
        self::assertStringContainsString('int _qt_overload_index = -1;', $cpp);
        self::assertStringContainsString('intern->native_ptr = new QSizeLike();', $cpp);
        self::assertStringContainsString('intern->native_ptr = new QSizeLike((int)width, (int)height);', $cpp);
        self::assertStringNotContainsString('QComplexHost::Iterator', $stub);
    }

    public function testGenerateBuildModeRetainsMethodOverloadsAndMatchesAcrossAllParameters(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qoverloadhost.h',
            'class' => 'QOverloadHost',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QOverloadHost,QSizeLike',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qoverloadhost.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qoverloadhost.cpp');

        self::assertStringContainsString('public function resize(QSizeLike|int $size, int $height = 0): void {}', $stub);
        self::assertStringContainsString('public function setSlot(int $slot, QSizeLike|int $size): void {}', $stub);
        self::assertStringContainsString('int _qt_overload_index = -1;', $cpp);
        self::assertStringContainsString('intern->native_ptr->resize(*qt_qsizelike_from_obj(Z_OBJ_P(size))->native_ptr);', $cpp);
        self::assertStringContainsString('intern->native_ptr->resize((int)Z_LVAL_P(size), (int)height);', $cpp);
        self::assertStringContainsString('instanceof_function(Z_OBJCE_P(size), qt_ce_QSizeLike)', $cpp);
        self::assertStringContainsString('Z_TYPE_P(size) == IS_LONG', $cpp);
        self::assertStringContainsString('zend_throw_error(NULL, "No matching overload for QOverloadHost::setSlot().");', $cpp);
    }

    public function testGenerateBuildModeSkipsPrivateReferenceConstructorVariants(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qprivaterefconstructorthing.h',
            'class' => 'QPrivateRefConstructorThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QPrivateRefConstructorThing,QSizeLike',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertContains('non_public_constructor', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprivaterefconstructorthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprivaterefconstructorthing.cpp');

        self::assertStringContainsString('public function __construct() {}', $stub);
        self::assertStringNotContainsString('QSizeLike $size', $stub);
        self::assertStringContainsString('intern->native_ptr = new QPrivateRefConstructorThing();', $cpp);
        self::assertStringNotContainsString('new QPrivateRefConstructorThing(*qt_qsizelike_from_obj', $cpp);
    }

    public function testGenerateBuildModeDoesNotEmitFallbackObjectForRequiredReferenceOverloadParameters(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qrefconstructorthing.h',
            'class' => 'QRefConstructorThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QRefConstructorThing,QSizeLike',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qrefconstructorthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qrefconstructorthing.cpp');

        self::assertStringContainsString('public function __construct(QSizeLike|null $size = null) {}', $stub);
        self::assertStringContainsString('intern->native_ptr = new QRefConstructorThing();', $cpp);
        self::assertStringContainsString('intern->native_ptr = new QRefConstructorThing(*qt_qsizelike_from_obj(Z_OBJ_P(size))->native_ptr);', $cpp);
        self::assertStringNotContainsString('? *qt_qsizelike_from_obj(Z_OBJ_P(size))->native_ptr : QSizeLike()', $cpp);
    }
}
