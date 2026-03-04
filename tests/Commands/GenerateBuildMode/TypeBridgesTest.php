<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Commands\GenerateCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
it('casts const object pointer returns for wrapping', function (): void {
        $fixtureRoot = qt_fixture_path('const-pointer');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qnodeconstholder.cpp');
        Assert::assertStringContainsString('const QNode * _result = intern->native_ptr->node();', $cpp);
        Assert::assertStringContainsString('qt_qnode_wrap_native(return_value, const_cast<QNode *>(_result), qt_ce_QNode, true);', $cpp);
});

it('casts enum parameters back to native types', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qenumholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qenumholder.cpp');
    
        Assert::assertStringContainsString('public function setMode(int $mode): void {}', $stub);
        Assert::assertStringContainsString('intern->native_ptr->setMode((QEnumHolder::Mode)((int)(mode)));', $cpp);
        Assert::assertStringContainsString('RETURN_LONG((zend_long)(intern->native_ptr->mode()));', $cpp);
});

it('handles const char pointer string returns', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qcstringholder.cpp');
        Assert::assertStringContainsString('auto _result = intern->native_ptr->bits();', $cpp);
        Assert::assertStringContainsString('RETURN_STRING(_result);', $cpp);
        Assert::assertStringNotContainsString('QByteArray _utf8 = _result.toUtf8();', $cpp);
});

it('treats qbitarray factories as value returns and skips bool out parameters', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('toUInt32', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_output_parameter', array_column($payload['skipped_methods'], 'reason_code'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qbitarray.cpp');
        Assert::assertStringContainsString('QBitArray _result = QBitArray::fromBits(ZSTR_VAL(data), (int)len);', $cpp);
        Assert::assertStringContainsString('_ret_intern->native_ptr = new QBitArray(std::move(_result));', $cpp);
        Assert::assertStringNotContainsString('QBitArray *_result = QBitArray::fromBits', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QBitArray, toUInt32)', $cpp);
});

it('skips object double pointer out parameters', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('locate', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_output_parameter', array_column($payload['skipped_methods'], 'reason_code'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qdoublepointerholder.cpp');
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QDoublePointerHolder, value)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QDoublePointerHolder, locate)', $cpp);
});

it('uses fromInt for flag aliases', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qflagholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qflagholder.cpp');
    
        Assert::assertStringContainsString('public function setModes(int $modes): void {}', $stub);
        Assert::assertStringContainsString('QFlags<QFlagHolder::Mode>::fromInt((QFlags<QFlagHolder::Mode>::Int)((int)(modes)))', $cpp);
        Assert::assertStringContainsString('RETURN_LONG((zend_long)(intern->native_ptr->modes()));', $cpp);
});

it('handles char strings and skips non const reference parameters', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
    
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $charPayload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $charPayload['status']);
    
        $charCpp = (string) file_get_contents($charOutputDir . '/classes/qt_qcharholder.cpp');
        Assert::assertStringContainsString('RETURN_STRINGL(&_result, 1);', $charCpp);
        Assert::assertStringContainsString('RETURN_STRING(_result);', $charCpp);
        Assert::assertStringContainsString("(ZSTR_LEN(ch) > 0 ? ZSTR_VAL(ch)[0] : '\\0')", $charCpp);
    
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $refPayload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $refPayload['status']);
    
        $refStub = (string) file_get_contents($refOutputDir . '/classes/qt_qrefholder.stub.php');
        $refCpp = (string) file_get_contents($refOutputDir . '/classes/qt_qrefholder.cpp');
    
        Assert::assertStringContainsString('public function swap(string $other): void {}', $refStub);
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QRefHolder, swap)', $refCpp);
        Assert::assertStringContainsString('QByteArray _qt_arg_0 = QByteArray(ZSTR_VAL(other), ZSTR_LEN(other));', $refCpp);
        Assert::assertStringNotContainsString('&$other', $refStub);
});

it('builds an input only argv constructor bridge', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $header = (string) file_get_contents($outputDir . '/classes/qt_qargvholder.h');
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qargvholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qargvholder.cpp');
    
        Assert::assertStringContainsString('typedef struct _qt_argv_storage {', $header);
        Assert::assertStringContainsString('std::vector<QByteArray> argv_storage;', $header);
        Assert::assertStringContainsString('std::vector<char *> argv_pointers;', $header);
        Assert::assertStringContainsString('void *extra_storage;', $header);
        Assert::assertStringContainsString('public function __construct(int $argc = 0, array $argv = [], int $flags = 0) {}', $stub);
        Assert::assertStringContainsString('intern->extra_storage = new qt_argv_storage();', $cpp);
        Assert::assertStringContainsString('if (intern->extra_storage == NULL) {', $cpp);
        Assert::assertStringContainsString('intern->extra_storage = new qt_argv_storage();', $cpp);
        Assert::assertStringContainsString('auto *_qt_argv_storage = static_cast<qt_argv_storage *>(intern->extra_storage);', $cpp);
        Assert::assertStringContainsString('char ** _qt_arg_1 = NULL;', $cpp);
        Assert::assertStringContainsString('_qt_argv_storage->argv_storage.emplace_back("php", 3);', $cpp);
        Assert::assertStringContainsString('_qt_arg_0 = (int)_qt_argv_storage->argv_storage.size();', $cpp);
});

it('skips nested result types but keeps nested enums', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('decode', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qresultholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qresultholder.cpp');
    
        Assert::assertStringContainsString('public function setMode(int $mode): void {}', $stub);
        Assert::assertStringContainsString('intern->native_ptr->setMode((QResultHolder::Mode)((int)(mode)));', $cpp);
        Assert::assertStringNotContainsString('fromBase64Encoding', $cpp);
        Assert::assertStringNotContainsString('RETURN_LONG((zend_long)(QResultHolder::decode()', $cpp);
});

it('handles std string conversions', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qstdstringholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qstdstringholder.cpp');
    
        Assert::assertStringContainsString('public static function fromStdString(string $s): QStdStringHolder {}', $stub);
        Assert::assertStringContainsString('public function toStdString(): string {}', $stub);
        Assert::assertStringContainsString('QStdStringHolder::fromStdString(std::string(ZSTR_VAL(s), ZSTR_LEN(s)))', $cpp);
        Assert::assertStringContainsString('RETURN_STRINGL(_result.data(), _result.size())', $cpp);
});

it('treats object returns without pointers as value objects', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qvaluereturnholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qvaluereturnholder.cpp');
    
        Assert::assertStringContainsString('public static function create(): QValueReturnHolder {}', $stub);
        Assert::assertStringContainsString('public function normalized(): QValueReturnHolder {}', $stub);
        Assert::assertStringContainsString('QValueReturnHolder _result = QValueReturnHolder::create();', $cpp);
        Assert::assertStringContainsString('QValueReturnHolder _result = intern->native_ptr->normalized();', $cpp);
        Assert::assertStringContainsString('_ret_intern->native_ptr = new QValueReturnHolder(std::move(_result));', $cpp);
        Assert::assertStringNotContainsString('QValueReturnHolder *_result = QValueReturnHolder::create();', $cpp);
        Assert::assertStringNotContainsString('QValueReturnHolder *_result = intern->native_ptr->normalized();', $cpp);
});

it('skips nested struct returns but keeps bare enums', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('partsFromDate', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qpartsholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qpartsholder.cpp');
    
        Assert::assertStringContainsString('public function setFormat(int $format): void {}', $stub);
        Assert::assertStringContainsString('intern->native_ptr->setFormat((QPartsHolder::NameFormat)((int)(format)));', $cpp);
        Assert::assertStringNotContainsString('public function partsFromDate', $stub);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QPartsHolder, partsFromDate)', $cpp);
});

it('skips qualified nested struct returns but keeps qualified enums', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('elementAt', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qqualifiedtypeholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qqualifiedtypeholder.cpp');
    
        Assert::assertStringContainsString('public function kind(): int {}', $stub);
        Assert::assertStringContainsString('public function setKind(int $kind): void {}', $stub);
        Assert::assertStringContainsString('RETURN_LONG((zend_long)(intern->native_ptr->kind()));', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setKind((QQualifiedTypeHolder::Kind)((int)(kind)));', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QQualifiedTypeHolder, elementAt)', $cpp);
});

it('converts chrono durations to and from integers', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qchronoholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qchronoholder.cpp');
    
        Assert::assertStringContainsString('public function setInterval(int $value): void {}', $stub);
        Assert::assertStringContainsString('intern->native_ptr->setInterval(std::chrono::milliseconds((std::chrono::milliseconds::rep)((int)(value))));', $cpp);
        Assert::assertStringContainsString('RETURN_LONG((zend_long)(intern->native_ptr->interval().count()));', $cpp);
});

it('bridges wide strings through qstring', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qwidestringholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qwidestringholder.cpp');
    
        Assert::assertStringContainsString('public static function fromStdWString(string $s): QWideStringHolder {}', $stub);
        Assert::assertStringContainsString('QString::fromUtf8(ZSTR_VAL(s), (int)ZSTR_LEN(s)).toStdWString()', $cpp);
        Assert::assertStringContainsString('QString::fromStdWString(_result).toUtf8()', $cpp);
        Assert::assertStringContainsString('QString::fromUtf8(ZSTR_VAL(s), (int)ZSTR_LEN(s)).toStdU16String()', $cpp);
        Assert::assertStringContainsString('QString::fromStdU16String(_result).toUtf8()', $cpp);
        Assert::assertStringContainsString('QString::fromUtf8(ZSTR_VAL(s), (int)ZSTR_LEN(s)).toStdU32String()', $cpp);
        Assert::assertStringContainsString('QString::fromStdU32String(_result).toUtf8()', $cpp);
});

it('returns qanystringview values via toString', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qanystringviewholder.cpp');
        Assert::assertStringContainsString('QByteArray _utf8 = _result.toString().toUtf8();', $cpp);
        Assert::assertStringNotContainsString('_result.toUtf8()', $cpp);
});

it('skips qchar buffer returns', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('unicode', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('constData', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_buffer_return', array_column($payload['skipped_methods'], 'reason_code'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qstringbufferholder.cpp');
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QStringBufferHolder, length)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QStringBufferHolder, unicode)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QStringBufferHolder, constData)', $cpp);
});

it('copies pointer returns for value types', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qvariantpointerholder.cpp');
        Assert::assertStringContainsString('QVariant * _result = intern->native_ptr->current();', $cpp);
        Assert::assertStringContainsString('object_init_ex(return_value, qt_ce_QVariant);', $cpp);
        Assert::assertStringContainsString('_ret_intern->native_ptr = new QVariant(*_result);', $cpp);
        Assert::assertStringNotContainsString('qt_qvariant_wrap_native', $cpp);
});

it('skips complex returns instead of casting to scalars', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));
        Assert::assertContains('begin', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('provider', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('values', array_column($payload['skipped_methods'], 'name'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qunsupportedtypes.cpp');
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QUnsupportedTypes, begin)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QUnsupportedTypes, provider)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QUnsupportedTypes, values)', $cpp);
});

it('uses move construction for move only value object returns', function (): void {
        $fixtureRoot = qt_fixture_path('qml-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtQml/qjsmanagedvalue.h',
            'class' => 'QJSManagedValue',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtQml',
            ],
            '--module' => 'QtQml',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QJSManagedValue,QJSEngine',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qjsmanagedvalue.cpp');
        Assert::assertStringContainsString('_ret_intern->native_ptr = new QJSManagedValue(std::move(_result));', $cpp);
});

it('uses a move aware bridge for rvalue reference object parameters', function (): void {
        $fixtureRoot = qt_fixture_path('qml-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtQml/qjsvalue.h',
            'class' => 'QJSValue',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtQml',
            ],
            '--module' => 'QtQml',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QJSValue,QJSManagedValue,QJSPrimitiveValue,QJSEngine',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qjsvalue.cpp');
        Assert::assertStringContainsString('QJSPrimitiveValue _qt_arg_0 = QJSPrimitiveValue(*qt_qjsprimitivevalue_from_obj(Z_OBJ_P(value))->native_ptr);', $cpp);
        Assert::assertStringContainsString('new QJSValue(std::move(_qt_arg_0));', $cpp);
        Assert::assertStringContainsString('QJSManagedValue _qt_arg_0 = QJSManagedValue(qt_qjsmanagedvalue_from_obj(Z_OBJ_P(value))->native_ptr->toJSValue(), qt_qjsmanagedvalue_from_obj(Z_OBJ_P(value))->native_ptr->engine());', $cpp);
        Assert::assertStringContainsString('new QJSValue(std::move(_qt_arg_0));', $cpp);
});
