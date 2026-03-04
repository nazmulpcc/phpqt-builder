<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Commands\GenerateCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
it('skips child methods with incompatible inherited signatures', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('setPeer', array_column($payload['skipped_methods'], 'name'));
        Assert::assertNotContains('peer', array_column($payload['skipped_methods'], 'name'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qchildsetter.stub.php');
        Assert::assertStringContainsString('class QChildSetter extends QParentSetter', $stub);
        Assert::assertStringContainsString('public function childId(): int {}', $stub);
        Assert::assertStringContainsString('public function setPeerChildSetter(QChildSetter|null $peer = null): void {}', $stub);
        Assert::assertStringContainsString('public function peerAsChildSetter(): QChildSetter {}', $stub);
        Assert::assertStringNotContainsString('public function setPeer(QChildSetter', $stub);
        Assert::assertStringNotContainsString('public function peer(): QChildSetter', $stub);
});

it('generates abstract classes and retains pure virtual methods', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('abstract_constructor', array_column($payload['skipped_methods'], 'reason_code'));
        Assert::assertFileExists($outputDir . '/classes/qt_qabstractthing.cpp');
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qabstractthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qabstractthing.cpp');
    
        Assert::assertStringContainsString('abstract class QAbstractThing', $stub);
        Assert::assertStringContainsString('protected function __construct() {}', $stub);
        Assert::assertStringContainsString('abstract public function size(): int;', $stub);
        Assert::assertStringContainsString('class qt_php_QAbstractThing : public QAbstractThing', $cpp);
        Assert::assertStringContainsString('ZEND_ME(Qt_Core_QAbstractThing, __construct,', $cpp);
        Assert::assertStringContainsString('ZEND_ACC_PROTECTED', $cpp);
        Assert::assertStringContainsString('ZEND_RAW_FENTRY("size", NULL, arginfo_class_Qt_Core_QAbstractThing_size, ZEND_ACC_PUBLIC | ZEND_ACC_ABSTRACT, NULL, NULL)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QAbstractThing, size)', $cpp);
        Assert::assertStringContainsString('bool _qt_use_trampoline = (Z_OBJCE_P(ZEND_THIS) != qt_ce_QAbstractThing);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr = new qt_php_QAbstractThing();', $cpp);
        Assert::assertStringContainsString('zend_throw_error(NULL, "Abstract class QAbstractThing cannot be instantiated directly.");', $cpp);
        Assert::assertStringContainsString('if (!qt_method_is_overridden(this->php_object, qt_ce_QAbstractThing, "size"))', $cpp);
        Assert::assertStringContainsString('ce_flags |= ZEND_ACC_ABSTRACT;', $cpp);
});

it('generates abstract shell classes with supported pure virtuals', function (): void {
        $fixtureRoot = qt_fixture_path('abstract-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertFileExists($outputDir . '/classes/qt_qabstractshell.cpp');
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qabstractshell.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qabstractshell.cpp');
    
        Assert::assertStringContainsString('abstract class QAbstractShell', $stub);
        Assert::assertStringNotContainsString('function __construct', $stub);
        Assert::assertStringContainsString('abstract public function size(): int;', $stub);
        Assert::assertStringContainsString('class qt_php_QAbstractShell : public QAbstractShell', $cpp);
        Assert::assertStringContainsString('ZEND_RAW_FENTRY("size", NULL, arginfo_class_Qt_Core_QAbstractShell_size, ZEND_ACC_PUBLIC | ZEND_ACC_ABSTRACT, NULL, NULL)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QAbstractShell, size)', $cpp);
});

it('omits abstract constructors when pure virtuals cannot be satisfied', function (): void {
        $fixtureRoot = qt_fixture_path('abstract-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qabstractconstructorgaps.h',
            'class' => 'QAbstractUnsupportedCtorThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QAbstractUnsupportedCtorThing',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('unsupported_abstract_subclass_constructor', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qabstractunsupportedctorthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qabstractunsupportedctorthing.cpp');
    
        Assert::assertStringContainsString('abstract class QAbstractUnsupportedCtorThing', $stub);
        Assert::assertStringNotContainsString('function __construct', $stub);
        Assert::assertStringNotContainsString('ZEND_ME(Qt_Core_QAbstractUnsupportedCtorThing, __construct,', $cpp);
});

it('omits abstract constructors when pure virtuals are only inherited', function (): void {
        $fixtureRoot = qt_fixture_path('abstract-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qabstractconstructorgaps.h',
            'class' => 'QAbstractCtorChildThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QAbstractCtorParentThing,QAbstractCtorChildThing',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('unsupported_abstract_subclass_constructor', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qabstractctorchildthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qabstractctorchildthing.cpp');
    
        Assert::assertStringContainsString('abstract class QAbstractCtorChildThing extends QAbstractCtorParentThing', $stub);
        Assert::assertStringNotContainsString('function __construct', $stub);
        Assert::assertStringNotContainsString('ZEND_ME(Qt_Core_QAbstractCtorChildThing, __construct,', $cpp);
});

it('keeps abstract constructors when value object pure virtuals are supported', function (): void {
        $fixtureRoot = qt_fixture_path('abstract-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qabstractmodelthing.h',
            'class' => 'QAbstractModelThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QAbstractModelThing,QModelIndex,QVariant',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('unsupported_abstract_subclass_constructor', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qabstractmodelthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qabstractmodelthing.cpp');
    
        Assert::assertStringContainsString('abstract class QAbstractModelThing', $stub);
        Assert::assertStringContainsString('protected function __construct() {}', $stub);
        Assert::assertStringContainsString('abstract public function index(', $stub);
        Assert::assertStringContainsString('): QModelIndex;', $stub);
        Assert::assertStringContainsString('abstract public function parent(', $stub);
        Assert::assertStringContainsString('abstract public function rowCount(', $stub);
        Assert::assertStringContainsString('abstract public function columnCount(', $stub);
        Assert::assertStringContainsString('abstract public function data(', $stub);
        Assert::assertStringContainsString('): QVariant;', $stub);
        Assert::assertStringContainsString('class qt_php_QAbstractModelThing : public QAbstractModelThing', $cpp);
        Assert::assertStringContainsString('QModelIndex index(', $cpp);
        Assert::assertStringContainsString('QModelIndex parent(', $cpp);
        Assert::assertStringContainsString('QVariant data(', $cpp);
        Assert::assertStringContainsString('ZEND_ME(Qt_Core_QAbstractModelThing, __construct,', $cpp);
        Assert::assertStringContainsString('ZEND_RAW_FENTRY("index", NULL,', $cpp);
        Assert::assertStringContainsString('ZEND_RAW_FENTRY("data", NULL,', $cpp);
});

it('does not make inherited concrete methods abstract in php', function (): void {
        $fixtureRoot = qt_fixture_path('abstract-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qabstractoverridechildthing.h',
            'class' => 'QAbstractOverrideThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QBaseConcreteThing,QAbstractOverrideThing',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qabstractoverridething.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qabstractoverridething.cpp');
    
        Assert::assertStringContainsString('abstract class QAbstractOverrideThing extends QBaseConcreteThing', $stub);
        Assert::assertStringContainsString('protected function act(): void {}', $stub);
        Assert::assertStringNotContainsString('abstract protected function act(): void;', $stub);
        Assert::assertStringContainsString('ZEND_ME(Qt_Core_QAbstractOverrideThing, act,', $cpp);
        Assert::assertStringNotContainsString('ZEND_RAW_FENTRY("act", NULL,', $cpp);
});

it('renames inherited conflicting methods deterministically', function (): void {
        $fixtureRoot = qt_fixture_path('abstract-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qconflictingparentthing.h',
            'class' => 'QConflictingParentThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QObject,QModelIndex,QConflictingParentThing',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('unsupported_abstract_subclass_constructor', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qconflictingparentthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qconflictingparentthing.cpp');
    
        Assert::assertStringContainsString('protected function __construct() {}', $stub);
        Assert::assertStringContainsString('abstract public function parentModelIndex(', $stub);
        Assert::assertStringNotContainsString('abstract public function parent(', $stub);
        Assert::assertStringContainsString('ZEND_RAW_FENTRY("parentModelIndex", NULL,', $cpp);
        Assert::assertStringContainsString('QModelIndex parent(const QModelIndex & _qt_p0) const override', $cpp);
        Assert::assertStringContainsString('qt_method_is_overridden(this->php_object, qt_ce_QConflictingParentThing, "parentModelIndex")', $cpp);
        Assert::assertStringContainsString('qt_call_php_method(this->php_object, "parentModelIndex"', $cpp);
});

it('supports inherited enum types in overrides', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qinheritedenumthing.h',
            'class' => 'QInheritedEnumChildThing',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QInheritedEnumBaseThing,QInheritedEnumChildThing',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('unsupported_parameter_type', array_column($payload['skipped_methods'], 'reason_code'));
        Assert::assertNotContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qinheritedenumchildthing.stub.php');
        Assert::assertStringContainsString('function currentMode(): int {}', $stub);
        Assert::assertStringContainsString('function setMode(int $mode): void {}', $stub);
});

it('allows concrete children of abstract parents', function (): void {
        $fixtureRoot = qt_fixture_path('abstract-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertFileExists($outputDir . '/classes/qt_qconcretechildthing.cpp');
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qconcretechildthing.stub.php');
        Assert::assertStringContainsString('class QConcreteChildThing extends QAbstractParentThing', $stub);
});
