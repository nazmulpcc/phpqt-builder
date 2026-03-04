<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Commands\GenerateCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
it('supports common qt container parameters and returns', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qcontainerbridgeholder.h',
            'class' => 'QContainerBridgeHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QContainerBridgeHolder,QModelIndex,QPersistentModelIndex,QAction,QWidget,QVariant',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('unsupported_parameter_type', array_column($payload['skipped_methods'], 'reason_code'));
        Assert::assertNotContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qcontainerbridgeholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qcontainerbridgeholder.cpp');
    
        Assert::assertStringContainsString('function mimeTypes(): array {}', $stub);
        Assert::assertStringContainsString('function setMimeTypes(array $types): void {}', $stub);
        Assert::assertStringContainsString('function roles(): array {}', $stub);
        Assert::assertStringContainsString('function selectedIndexes(): array {}', $stub);
        Assert::assertStringContainsString('function roleNames(): array {}', $stub);
        Assert::assertStringContainsString('function itemData(): array {}', $stub);
        Assert::assertStringContainsString('function setItemData(array $roles): void {}', $stub);
        Assert::assertStringContainsString('function actions(): array {}', $stub);
    
        Assert::assertStringContainsString('HashTable *_qt_arg_0_ht = Z_ARRVAL_P(types);', $cpp);
        Assert::assertStringContainsString('_qt_it.key().toUtf8();', $cpp);
        Assert::assertStringContainsString('qt_variant_to_zval(&_qt_value, _qt_it.value());', $cpp);
        Assert::assertStringContainsString('if (!qt_zval_to_variant(_qt_arg_0_entry, &_qt_arg_0_value)) {', $cpp);
        Assert::assertStringContainsString('add_next_index_zval(return_value, &_qt_value);', $cpp);
        Assert::assertStringContainsString('#include "qt_qaction.h"', $cpp);
        Assert::assertStringContainsString('#include "qt_qmodelindex.h"', $cpp);
});

it('supports common qt container signatures for virtual overrides', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qvirtualcontainerholder.h',
            'class' => 'QVirtualContainerHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QVirtualContainerHolder,QModelIndex,QPersistentModelIndex,QAccessibleInterface,QListWidgetItem,QVariant',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('unsupported_virtual_override_signature', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qvirtualcontainerholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qvirtualcontainerholder.cpp');

        Assert::assertStringContainsString('abstract public function mimeTypes(): array;', $stub);
        Assert::assertStringContainsString('abstract public function selectedIndexes(): array;', $stub);
        Assert::assertStringContainsString('abstract public function roleNames(): array;', $stub);
        Assert::assertStringContainsString('abstract public function itemData(): array;', $stub);
        Assert::assertStringContainsString('abstract public function setItemData(array $roles): void;', $stub);
        Assert::assertStringContainsString('abstract public function dataChanged(QModelIndex $topLeft, QModelIndex $bottomRight, array $roles): void;', $stub);
        Assert::assertStringContainsString('abstract public function selectedItems(): array;', $stub);
        Assert::assertStringContainsString('abstract public function convertFromMime(): array;', $stub);
        Assert::assertStringContainsString('abstract public function convertToMime(array $formats): void;', $stub);
        Assert::assertStringContainsString('abstract public function consumeWidgetItems(array $items): void;', $stub);

        Assert::assertStringContainsString('ZEND_RAW_FENTRY("mimeTypes", NULL, arginfo_class_Qt_Core_QVirtualContainerHolder_mimeTypes, ZEND_ACC_PUBLIC | ZEND_ACC_ABSTRACT, NULL, NULL)', $cpp);
        Assert::assertStringContainsString('HashTable *_qt_native_return_ht = Z_ARRVAL_P(&_qt_retval);', $cpp);
        Assert::assertStringContainsString('qt_variant_to_zval(&_qt_value_0, _qt_it_0.value());', $cpp);
        Assert::assertStringContainsString('Expected PHP array for Qt container conversion.', $cpp);
        Assert::assertStringContainsString('QMap<int, QVariant>()', $cpp);
        Assert::assertStringContainsString('QList<QByteArray>()', $cpp);
});

it('keeps writable container references unsupported', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        mkdir($outputDir, 0777, true);
    
        $header = $outputDir . '/qwritablecontainerholder.h';
        file_put_contents($header, <<<'CPP'
    template <typename T> class QList {};
    class QWritableContainerHolder {
    public:
    void update(QList<int> &roles);
    void setRoles(const QList<int> &roles);
    };
    CPP);
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $header,
            'class' => 'QWritableContainerHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QWritableContainerHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('update', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_output_parameter', array_column($payload['skipped_methods'], 'reason_code'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qwritablecontainerholder.cpp');
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QWritableContainerHolder, setRoles)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QWritableContainerHolder, update)', $cpp);
});

it('skips nested qualified container struct elements', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qunsupportedcontainerelements.h',
            'class' => 'QUnsupportedContainerElements',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QUnsupportedContainerElements',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('skipped', $payload['status']);
        Assert::assertSame('no_supported_methods', $payload['reason_code']);
});

it('does not mistake self pointer constructors for copy constructors', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qselfparentthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qselfparentthing.cpp');
    
        Assert::assertStringContainsString('public function __construct(QSelfParentThing|null $parent = null)', $stub);
        Assert::assertStringContainsString('intern->native_ptr = new QSelfParentThing((parent != NULL && Z_TYPE_P(parent) == IS_OBJECT ? qt_qselfparentthing_from_obj(Z_OBJ_P(parent))->native_ptr : NULL));', $cpp);
});

it('keeps supported constructor overloads when one sibling is unsupported', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('unsupported_parameter_type', array_column($payload['skipped_methods'], 'reason_code'));
        Assert::assertContains('QSizeLike', array_column($payload['skipped_methods'], 'name'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qsizelike.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qsizelike.cpp');
    
        Assert::assertStringContainsString('public function __construct(int $width = 0, int $height = 0) {}', $stub);
        Assert::assertStringContainsString('int _qt_overload_index = -1;', $cpp);
        Assert::assertStringContainsString('intern->native_ptr = new QSizeLike();', $cpp);
        Assert::assertStringContainsString('intern->native_ptr = new QSizeLike((int)width, (int)height);', $cpp);
        Assert::assertStringNotContainsString('QComplexHost::Iterator', $stub);
});

it('retains method overloads and matches across all parameters', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qoverloadhost.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qoverloadhost.cpp');
    
        Assert::assertStringContainsString('public function resize(QSizeLike|int $size, int $height = 0): void {}', $stub);
        Assert::assertStringContainsString('public function setSlot(int $slot, QSizeLike|int $size): void {}', $stub);
        Assert::assertStringContainsString('int _qt_overload_index = -1;', $cpp);
        Assert::assertStringContainsString('int _qt_overload_best_score = -1;', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->resize(*qt_qsizelike_from_obj(Z_OBJ_P(size))->native_ptr);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->resize((int)Z_LVAL_P(size), (int)height);', $cpp);
        Assert::assertStringContainsString('qt_zval_object_match_score(size, qt_ce_QSizeLike)', $cpp);
        Assert::assertStringContainsString('Z_TYPE_P(size) == IS_LONG', $cpp);
        Assert::assertStringContainsString('zend_throw_error(NULL, "No matching overload for QOverloadHost::setSlot().");', $cpp);
});

it('prefers more specific object overloads', function (): void {
        $fixtureRoot = qt_fixture_path('qml-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-overload-specificity-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtQml/qcomponentoverload.h',
            'class' => 'QComponentLike',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtQml',
            ],
            '--module' => 'QtQml',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QObject,QEngineLike,QComponentLike',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qcomponentlike.cpp');
    
        Assert::assertStringContainsString('int _qt_overload_best_score = -1;', $cpp);
        Assert::assertStringContainsString('qt_zval_object_match_score(parent, qt_ce_QEngineLike)', $cpp);
        Assert::assertStringContainsString('qt_zval_object_match_score(parent, qt_ce_QObject)', $cpp);
        Assert::assertStringContainsString('_qt_score_1 > _qt_overload_best_score', $cpp);
});

it('skips private reference constructor variants', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('non_public_constructor', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprivaterefconstructorthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprivaterefconstructorthing.cpp');
    
        Assert::assertStringContainsString('public function __construct() {}', $stub);
        Assert::assertStringNotContainsString('QSizeLike $size', $stub);
        Assert::assertStringContainsString('intern->native_ptr = new QPrivateRefConstructorThing();', $cpp);
        Assert::assertStringNotContainsString('new QPrivateRefConstructorThing(*qt_qsizelike_from_obj', $cpp);
});

it('does not emit fallback objects for required reference overload parameters', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qrefconstructorthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qrefconstructorthing.cpp');
    
        Assert::assertStringContainsString('public function __construct(QSizeLike|null $size = null) {}', $stub);
        Assert::assertStringContainsString('intern->native_ptr = new QRefConstructorThing();', $cpp);
        Assert::assertStringContainsString('intern->native_ptr = new QRefConstructorThing(*qt_qsizelike_from_obj(Z_OBJ_P(size))->native_ptr);', $cpp);
        Assert::assertStringNotContainsString('? *qt_qsizelike_from_obj(Z_OBJ_P(size))->native_ptr : QSizeLike()', $cpp);
});
