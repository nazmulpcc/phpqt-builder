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
        self::assertStringContainsString('QFlags<QFlagHolder::Mode>::fromInt((QFlags<QFlagHolder::Mode>::Int)(modes))', $cpp);
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
        self::assertContains('swap', array_column($refPayload['skipped_methods'], 'name'));
        self::assertContains('unsupported_reference_parameter', array_column($refPayload['skipped_methods'], 'reason_code'));

        $refCpp = (string) file_get_contents($refOutputDir . '/classes/qt_qrefholder.cpp');
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QRefHolder, swap)', $refCpp);
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
        self::assertStringContainsString('intern->native_ptr->setMode((QResultHolder::Mode)mode);', $cpp);
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
        self::assertStringContainsString('intern->native_ptr->setFormat((QPartsHolder::NameFormat)format);', $cpp);
        self::assertStringNotContainsString('public function partsFromDate', $stub);
        self::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QPartsHolder, partsFromDate)', $cpp);
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

        $skippedMethodNames = array_column($result->skippedMethods, 'name');
        $skippedReasonCodes = array_column($result->skippedMethods, 'reason_code');

        self::assertContains('QNoCopyThing', $skippedMethodNames);
        self::assertContains('noncopyable_copy_constructor', $skippedReasonCodes);
        self::assertCount(1, array_values(array_filter(
            $result->phpClass->methods,
            static fn(\QtBuilder\Definition\PhpMethod $method): bool => $method->name === 'value',
        )));
    }
}
