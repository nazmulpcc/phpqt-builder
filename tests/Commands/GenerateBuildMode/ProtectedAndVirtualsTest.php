<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Commands\GenerateCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
it('generates protected methods through access shims', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('tweak', array_column($payload['skipped_methods'], 'name'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotectedthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedthing.cpp');
    
        Assert::assertStringContainsString('protected function tweak(): void {}', $stub);
        Assert::assertStringContainsString('class qt_access_QProtectedThing : public QProtectedThing', $cpp);
        Assert::assertStringContainsString('intern->native_ptr = qt_new_default_native<qt_access_QProtectedThing>()', $cpp);
        Assert::assertStringContainsString('static_cast<qt_access_QProtectedThing *>(intern->native_ptr)->qt_access_tweak_0()', $cpp);
});

it('generates protected virtual methods with native trampolines', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('value', array_column($payload['skipped_methods'], 'name'));
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotectedvirtualthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedvirtualthing.cpp');
    
        Assert::assertStringContainsString('protected function value(): int {}', $stub);
        Assert::assertStringContainsString('class qt_access_QProtectedVirtualThing : public QProtectedVirtualThing', $cpp);
        Assert::assertStringContainsString('class qt_php_QProtectedVirtualThing : public qt_access_QProtectedVirtualThing', $cpp);
        Assert::assertStringContainsString('zend_class_entry *_qt_actual_ce = Z_OBJCE_P(ZEND_THIS);', $cpp);
        Assert::assertStringContainsString('bool _qt_has_virtual_override = false;', $cpp);
        Assert::assertStringContainsString('static const char * const _qt_virtual_methods[] = {', $cpp);
        Assert::assertStringContainsString('"value",', $cpp);
        Assert::assertStringContainsString('_qt_has_virtual_override = qt_any_virtual_method_overridden_in_ce(', $cpp);
        Assert::assertStringContainsString('bool _qt_use_trampoline = (_qt_actual_ce != qt_ce_QProtectedVirtualThing) && _qt_has_virtual_override;', $cpp);
        Assert::assertStringContainsString('intern->native_ptr = qt_new_default_native<qt_access_QProtectedVirtualThing>();', $cpp);
        Assert::assertStringContainsString('intern->native_ptr = qt_new_default_native<qt_php_QProtectedVirtualThing>();', $cpp);
        Assert::assertStringContainsString('intern->native_is_virtual_trampoline = true;', $cpp);
        Assert::assertStringContainsString('intern->native_is_virtual_trampoline = false;', $cpp);
        Assert::assertStringContainsString('int value() const override', $cpp);
        Assert::assertStringContainsString('QProtectedVirtualThing::value()', $cpp);
        Assert::assertStringContainsString('zend_hash_str_find_ptr_lc(&ce->function_table, function_name, strlen(function_name))', $cpp);
        Assert::assertStringContainsString('zend_call_known_function(method, object, object->ce, retval, param_count, params, NULL);', $cpp);
});

it('generates static protected access helpers', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotectedstaticthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedstaticthing.cpp');
    
        Assert::assertStringContainsString('protected static function doThing(int $value): void {}', $stub);
        Assert::assertStringContainsString('static inline void qt_access_doThing_0(int _qt_p0)', $cpp);
        Assert::assertStringContainsString('qt_access_QProtectedStaticThing::qt_access_doThing_0((int)value);', $cpp);
});

it('marshals const char pointer virtual args to php strings', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedstringvirtualthing.cpp');
    
        Assert::assertStringContainsString('if (_qt_p0 != NULL) {', $cpp);
        Assert::assertStringContainsString('ZVAL_STRING(&_qt_params[0], _qt_p0);', $cpp);
        Assert::assertStringContainsString('ZVAL_NULL(&_qt_params[0]);', $cpp);
});

it('erases protected nested enum types at the shim boundary', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotectedenumthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedenumthing.cpp');
    
        Assert::assertStringContainsString('protected function supportsExtension(int $extension): bool {}', $stub);
        Assert::assertStringContainsString('protected function setExtension(int $extension): void {}', $stub);
        Assert::assertStringContainsString('inline bool qt_access_supportsExtension_0(zend_long _qt_p0) const', $cpp);
        Assert::assertStringContainsString('inline void qt_access_setExtension_0(zend_long _qt_p0)', $cpp);
        Assert::assertStringContainsString('QProtectedEnumThing::supportsExtension((QProtectedEnumThing::Extension)((int)(_qt_p0)))', $cpp);
        Assert::assertStringContainsString('QProtectedEnumThing::setExtension((QProtectedEnumThing::Extension)((int)(_qt_p0)))', $cpp);
        Assert::assertStringNotContainsString('qt_access_supportsExtension_0((QProtectedEnumThing::Extension)', $cpp);
        Assert::assertStringNotContainsString('qt_access_setExtension_0((QProtectedEnumThing::Extension)', $cpp);
});

it('does not generate trampolines for final virtual methods', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qfinalvirtualthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qfinalvirtualthing.cpp');
    
        Assert::assertStringContainsString('public function value(): int {}', $stub);
        Assert::assertStringNotContainsString('class qt_php_QFinalVirtualThing', $cpp);
        Assert::assertStringContainsString('auto _result = intern->native_ptr->value();', $cpp);
        Assert::assertStringContainsString('RETURN_LONG((zend_long)(_result));', $cpp);
});

it('pulls inherited QWidget input virtuals into widget subclasses', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtWidgets/qwidgeteventchild.h',
            'class' => 'QWidgetEventChild',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtWidgets',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QWidgetEventChild,QWidget,QEvent,QMouseEvent,QWheelEvent',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qwidgeteventchild.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qwidgeteventchild.cpp');

        Assert::assertStringContainsString('protected function mousePressEvent(', $stub);
        Assert::assertStringContainsString('protected function mouseMoveEvent(', $stub);
        Assert::assertStringContainsString('protected function wheelEvent(', $stub);
        Assert::assertStringContainsString('"mousePressEvent",', $cpp);
        Assert::assertStringContainsString('"mouseMoveEvent",', $cpp);
        Assert::assertStringContainsString('"wheelEvent",', $cpp);
        Assert::assertStringContainsString('void mousePressEvent(', $cpp);
        Assert::assertStringContainsString('void mouseMoveEvent(', $cpp);
        Assert::assertStringContainsString('void wheelEvent(', $cpp);
});

it('does not add widget input virtuals to unrelated non-widget subclasses', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qprotectedvirtualthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qprotectedvirtualthing.cpp');

        Assert::assertStringNotContainsString('mousePressEvent', $stub);
        Assert::assertStringNotContainsString('mouseMoveEvent', $stub);
        Assert::assertStringNotContainsString('wheelEvent', $stub);
        Assert::assertStringNotContainsString('"mousePressEvent",', $cpp);
        Assert::assertStringNotContainsString('"mouseMoveEvent",', $cpp);
        Assert::assertStringNotContainsString('"wheelEvent",', $cpp);
});

it('uses a shim only for the protected overload branch', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qmixedaccessoverloadthing.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qmixedaccessoverloadthing.cpp');
    
        Assert::assertStringContainsString('public function addItem(int $value, int $row = 0): void {}', $stub);
        Assert::assertStringContainsString('class qt_access_QMixedAccessOverloadThing : public QMixedAccessOverloadThing', $cpp);
        Assert::assertStringContainsString('inline void qt_access_addItem_1(int _qt_p0)', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->addItem((int)value, (int)row);', $cpp);
        Assert::assertStringContainsString('static_cast<qt_access_QMixedAccessOverloadThing *>(intern->native_ptr)->qt_access_addItem_1((int)value);', $cpp);
        Assert::assertStringNotContainsString('intern->native_ptr->QMixedAccessOverloadThing::addItem((int)value);', $cpp);
});
