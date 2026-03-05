<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Commands\GenerateCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
it('generates signal apis and retains protected slots', function (): void {
        $fixtureRoot = qt_fixture_path('signals-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('resetValue', array_column($payload['skipped_methods'], 'name'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qsignalfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qsignalfixture.cpp');
        Assert::assertFileExists($outputDir . '/classes/qt_qmetaobjectconnection.h');
        Assert::assertFileExists($outputDir . '/classes/qt_qmetaobjectconnection.cpp');
        Assert::assertFileExists($outputDir . '/classes/qt_qmetaobjectconnection.stub.php');
    
        Assert::assertStringContainsString('protected function resetValue(): void {}', $stub);
        Assert::assertStringContainsString('public function connect(string $signalSignature, callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('public function disconnect(\Qt\Core\QMetaObjectConnection $connection): bool {}', $stub);
        Assert::assertStringContainsString('public function onTriggered(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('public function onValueChanged(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringNotContainsString('function triggered(): void {}', $stub);
        Assert::assertStringNotContainsString('function valueChanged(int $value): void {}', $stub);
        Assert::assertStringContainsString('class qt_access_QSignalFixture : public QSignalFixture', $cpp);
        Assert::assertStringContainsString('qt_access_resetValue_0', $cpp);
    
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QSignalFixture, connect)', $cpp);
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QSignalFixture, disconnect)', $cpp);
        Assert::assertStringContainsString('#include "qt_qmetaobjectconnection.h"', $cpp);
        Assert::assertStringContainsString('qt_track_native_instance(intern->native_ptr);', $cpp);
        Assert::assertStringContainsString('qt_should_delete_native', $cpp);
        Assert::assertStringContainsString('if (qt_should_delete_native(intern->native_ptr, intern->prevent_destroy)) {', $cpp);
        Assert::assertStringContainsString('zend_string_equals_literal(signalSignature, "triggered()")', $cpp);
        Assert::assertStringContainsString('static_cast<void (QSignalFixture::*)(int)>(&QSignalFixture::valueChanged)', $cpp);
        Assert::assertStringContainsString('ZEND_ME(Qt_Core_QSignalFixture, onTriggered,', $cpp);
        Assert::assertStringContainsString('qt_qmetaobjectconnection_wrap(return_value, _qt_connection);', $cpp);
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QSignalFixture, resetValue)', $cpp);
        Assert::assertStringNotContainsString('zend_fcall_info_args_clear(&callback->fci, true);', $cpp);
        Assert::assertStringContainsString('callback->fci.params = previousParams;', $cpp);
        Assert::assertStringContainsString('callback->fci.param_count = previousParamCount;', $cpp);
});

it('disambiguates overloaded signal sugar methods', function (): void {
        $fixtureRoot = qt_fixture_path('signals-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qoverloadedsignalfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qoverloadedsignalfixture.cpp');
    
        Assert::assertStringContainsString('public function onValueChangedInt(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('public function onValueChangedBool(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('zend_string_equals_literal(signalSignature, "valueChanged(int)")', $cpp);
        Assert::assertStringContainsString('zend_string_equals_literal(signalSignature, "valueChanged(bool)")', $cpp);
});

it('uses unique utf8 temp names for multi qstring signal callbacks', function (): void {
        $fixtureRoot = qt_fixture_path('signals-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qstringsignalfixture.cpp');
        Assert::assertStringContainsString('QByteArray _qt_utf8_0 = _qt_arg_0.toUtf8();', $cpp);
        Assert::assertStringContainsString('QByteArray _qt_utf8_1 = _qt_arg_1.toUtf8();', $cpp);
        Assert::assertStringContainsString('QByteArray _qt_utf8_2 = _qt_arg_2.toUtf8();', $cpp);
});

it('skips signals with non copyable callback parameters', function (): void {
        $fixtureRoot = qt_fixture_path('signals-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('blocked', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_signal_callback_parameter', array_column($payload['skipped_methods'], 'reason_code'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qsignalnocopyfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qsignalnocopyfixture.cpp');
    
        Assert::assertStringNotContainsString('public function connect(', $stub);
        Assert::assertStringNotContainsString('public function onBlocked(', $stub);
        Assert::assertStringNotContainsString('zend_string_equals_literal(signalSignature, "blocked(QSignalNoCopyValue)")', $cpp);
        Assert::assertStringNotContainsString('new QSignalNoCopyValue(_qt_arg_0)', $cpp);
});

it('includes inherited signals in the connect api', function (): void {
        $fixtureRoot = qt_fixture_path('signals-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qsignalchildfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qsignalchildfixture.cpp');
    
        Assert::assertStringContainsString('public function onTriggered(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('public function onChanged(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('zend_string_equals_literal(signalSignature, "triggered()")', $cpp);
        Assert::assertStringContainsString('zend_string_equals_literal(signalSignature, "changed(int)")', $cpp);
        Assert::assertStringContainsString('static_cast<void (QSignalBaseFixture::*)()>(&QSignalBaseFixture::triggered)', $cpp);
});

it('preserves const signal member pointers', function (): void {
        $fixtureRoot = qt_fixture_path('signals-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qsignalconstfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qsignalconstfixture.cpp');
    
        Assert::assertStringContainsString('public function connect(string $signalSignature, callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('public function disconnect(\Qt\Core\QMetaObjectConnection $connection): bool {}', $stub);
        Assert::assertStringContainsString('public function onChanged(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('static_cast<void (QSignalConstFixture::*)(int) const>(&QSignalConstFixture::changed)', $cpp);
});

it('includes signals with trailing qprivatesignal callback args stripped', function (): void {
        $fixtureRoot = qt_fixture_path('signals-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-private-signals-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qprivatesignalfixture.h',
            'class' => 'QPrivateSignalFixture',
            '--qt-path' => $fixtureRoot,
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QPrivateSignalFixture',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('fileChanged', array_column($payload['skipped_methods'], 'name'));
        Assert::assertNotContains('changed', array_column($payload['skipped_methods'], 'name'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprivatesignalfixture.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprivatesignalfixture.cpp');
    
        Assert::assertStringContainsString('public function onFileChanged(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('public function onChanged(callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('zend_string_equals_literal(signalSignature, "fileChanged(QString)")', $cpp);
        Assert::assertStringContainsString('zend_string_equals_literal(signalSignature, "changed()")', $cpp);
        Assert::assertStringContainsString('&QPrivateSignalFixture::fileChanged', $cpp);
        Assert::assertStringContainsString('&QPrivateSignalFixture::changed', $cpp);
        Assert::assertStringContainsString('_qt_arg_0', $cpp);
        Assert::assertStringContainsString('qt_signal_callback_invoke(_qt_callback, 1, _qt_params);', $cpp);
        Assert::assertStringContainsString('qt_signal_callback_invoke(_qt_callback, 0, NULL);', $cpp);
});

it('filters connect methods by name', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('connect', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('method_name_filtered', array_column($payload['skipped_methods'], 'reason_code'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qconnectionholder.cpp');
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QConnectionHolder, version)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QConnectionHolder, connect)', $cpp);
});
