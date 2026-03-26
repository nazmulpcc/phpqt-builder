<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Commands\GenerateCommand;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
it('uses nullable unions for optional value object parameters', function (): void {
        $fixtureRoot = qt_fixture_path('qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qabstractitemmodel.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qabstractitemmodel.cpp');
    
        Assert::assertStringContainsString('QModelIndex|null $parent = null', $stub);
        Assert::assertStringContainsString('instanceof_function(Z_OBJCE_P(parent), qt_ce_qmodelindex)', $cpp);
        Assert::assertStringContainsString('*qt_qmodelindex_from_obj(Z_OBJ_P(parent))->native_ptr', $cpp);
});

it('skips methods with value object dependencies outside the allow list', function (): void {
        $fixtureRoot = qt_fixture_path('qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('skipped', $payload['status']);
        Assert::assertSame('no_supported_methods', $payload['reason_code']);
        Assert::assertFileDoesNotExist($outputDir . '/classes/qt_qabstractitemmodel.cpp');
});

it('uses nullable unions for optional qobject parameters', function (): void {
        $fixtureRoot = qt_fixture_path('qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qtree.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qtree.cpp');
    
        Assert::assertStringContainsString('QNode|null $node = null', $stub);
        Assert::assertStringContainsString('Z_PARAM_OBJECT_OF_CLASS_OR_NULL(node, qt_ce_qnode)', $cpp);
        Assert::assertStringContainsString('instanceof_function(Z_OBJCE_P(node), qt_ce_qnode)', $cpp);
        Assert::assertStringContainsString('qt_qnode_from_obj(Z_OBJ_P(node))->native_ptr', $cpp);
});

it('transfers ownership for layout attachment methods', function (): void {
        $fixtureRoot = qt_fixture_path('layout-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $boxLayoutCpp = (string) file_get_contents($outputDir . '/classes/qt_qboxlayout.cpp');
        Assert::assertStringContainsString('intern->native_ptr->addWidget(qt_qwidget_from_obj(Z_OBJ_P(w))->native_ptr, (int)stretch);', $boxLayoutCpp);
        Assert::assertStringContainsString('qt_qwidget_object *_qt_owned_arg_0 = qt_qwidget_from_obj(Z_OBJ_P(w));', $boxLayoutCpp);
        Assert::assertStringContainsString('_qt_owned_arg_0->prevent_destroy = true;', $boxLayoutCpp);
        Assert::assertStringContainsString('intern->native_ptr->addLayout(qt_qlayout_from_obj(Z_OBJ_P(layout))->native_ptr, (int)stretch);', $boxLayoutCpp);
        Assert::assertStringContainsString('qt_qlayout_object *_qt_owned_arg_0 = qt_qlayout_from_obj(Z_OBJ_P(layout));', $boxLayoutCpp);
    
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $widgetCpp = (string) file_get_contents($outputDir . '/classes/qt_qwidget.cpp');
        Assert::assertStringContainsString('intern->native_ptr->setLayout(qt_qlayout_from_obj(Z_OBJ_P(layout))->native_ptr);', $widgetCpp);
        Assert::assertStringContainsString('qt_qlayout_object *_qt_owned_arg_0 = qt_qlayout_from_obj(Z_OBJ_P(layout));', $widgetCpp);
        Assert::assertStringContainsString('_qt_owned_arg_0->prevent_destroy = true;', $widgetCpp);
});

it('uses the automatic qobject ownership probe', function (): void {
        $fixtureRoot = qt_fixture_path('ownership-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-object-ownership-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
    
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qobjectownership.h',
            'class' => 'QObjectOwner',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QObject,QObjectOwner',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qobjectowner.cpp');
        $ownershipHelpers = (string) file_get_contents($outputDir . '/classes/qt_ownership_helpers.h');
        Assert::assertFileExists($outputDir . '/classes/qt_php_compat.h');
        Assert::assertFileExists($outputDir . '/classes/qt_class_helpers.h');
        Assert::assertFileExists($outputDir . '/classes/qt_ownership_helpers.h');
        Assert::assertStringContainsString('#include "qt_class_helpers.h"', $cpp);
        Assert::assertStringContainsString('#include "qt_ownership_helpers.h"', $cpp);
        Assert::assertStringContainsString('if (qt_native_has_qobject_parent(_qt_owned_arg_0->native_ptr)) {', $cpp);
        Assert::assertStringContainsString('_qt_owned_arg_0->prevent_destroy = true;', $cpp);
        Assert::assertStringContainsString('if (QCoreApplication::closingDown()) {', $ownershipHelpers);
        Assert::assertStringContainsString('if ((EG(flags) & EG_FLAGS_IN_SHUTDOWN) != 0) {', $ownershipHelpers);
        Assert::assertStringContainsString('if (qt_runtime_is_shutdown_in_progress()) {', $ownershipHelpers);
        Assert::assertStringContainsString('qt_runtime_try_hook_about_to_quit();', $cpp);
        Assert::assertStringNotContainsString('static zend_always_inline bool qt_native_has_qobject_parent(T *ptr)', $cpp);
});

it('pins qt3d retained objects after setter calls', function (): void {
        $fixtureRoot = qt_fixture_path('ownership-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-qt3d-ownership-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/Qt3DCore/qaspectengine.h',
            'class' => 'QAspectEngine',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
                $fixtureRoot . '/include/Qt3DCore',
            ],
            '--module' => 'Qt3DCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QObject,QEntity,QAspectEngine',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $aspectEngineCpp = (string) file_get_contents($outputDir . '/classes/qt_qaspectengine.cpp');
        Assert::assertStringContainsString('intern->native_ptr->setRootEntity(QtCore::QEntityPtr(qt_qentity_from_obj(Z_OBJ_P(root))->native_ptr, [](QEntity *) {}));', $aspectEngineCpp);
        Assert::assertStringContainsString('qt_qentity_object *_qt_owned_arg_0 = qt_qentity_from_obj(Z_OBJ_P(root));', $aspectEngineCpp);
        Assert::assertStringContainsString('_qt_owned_arg_0->prevent_destroy = true;', $aspectEngineCpp);
        Assert::assertStringNotContainsString('if (qt_native_has_qobject_parent(_qt_owned_arg_0->native_ptr)) {', $aspectEngineCpp);

        $tester = new CommandTester(new GenerateCommand(FakeSystemInformation::passing()));
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/Qt3DRender/qrendersurfaceselector.h',
            'class' => 'QRenderSurfaceSelector',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
                $fixtureRoot . '/include/Qt3DRender',
            ],
            '--module' => 'Qt3DRender',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QObject,QRenderSurfaceSelector',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());

        $surfaceSelectorCpp = (string) file_get_contents($outputDir . '/classes/qt_qrendersurfaceselector.cpp');
        Assert::assertStringContainsString('intern->native_ptr->setSurface(qt_qobject_from_obj(Z_OBJ_P(surfaceObject))->native_ptr);', $surfaceSelectorCpp);
        Assert::assertStringContainsString('qt_qobject_object *_qt_owned_arg_0 = qt_qobject_from_obj(Z_OBJ_P(surfaceObject));', $surfaceSelectorCpp);
        Assert::assertStringContainsString('_qt_owned_arg_0->prevent_destroy = true;', $surfaceSelectorCpp);
        Assert::assertStringNotContainsString('if (qt_native_has_qobject_parent(_qt_owned_arg_0->native_ptr)) {', $surfaceSelectorCpp);
});

it('adds qobject property apis and handlers', function (): void {
        $fixtureRoot = qt_fixture_path('ownership-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-qobject-properties-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
    
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qobject.h',
            'class' => 'QObject',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QObject',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qobject.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qobject.cpp');
        Assert::assertFileExists($outputDir . '/classes/qt_qobject_helpers.h');
    
        Assert::assertStringContainsString('public function property(string $name): mixed {}', $stub);
        Assert::assertStringContainsString('public function setProperty(string $name, mixed $value): bool {}', $stub);
        Assert::assertStringContainsString('public function hasProperty(string $name): bool {}', $stub);
        Assert::assertStringContainsString('public function propertyNames(): array {}', $stub);
        Assert::assertStringContainsString('public function propertyInfo(string $name): array {}', $stub);
        Assert::assertStringContainsString('public function connectPropertyNotify(string $name, callable $callback): \Qt\Core\QMetaObjectConnection {}', $stub);
        Assert::assertStringContainsString('#include "qt_class_helpers.h"', $cpp);
        Assert::assertStringContainsString('#include "qt_qobject_helpers.h"', $cpp);
        Assert::assertStringContainsString('static zval *qt_qobject_read_property(', $cpp);
        Assert::assertStringContainsString('static zval *qt_qobject_write_property(', $cpp);
        Assert::assertStringContainsString('static zend_array *qt_qobject_get_properties_for(', $cpp);
        Assert::assertStringContainsString('if (qt_qobject_should_delegate_to_std_property(object, member)) {', $cpp);
        Assert::assertStringContainsString('return zend_std_write_property(object, member, value, cache_slot);', $cpp);
        Assert::assertStringContainsString('zend_declare_typed_property(', $cpp);
        Assert::assertStringContainsString('ZEND_ACC_PUBLIC | ZEND_ACC_VIRTUAL', $cpp);
        Assert::assertStringContainsString('qt_qobject_handlers.read_property = qt_qobject_read_property;', $cpp);
        Assert::assertStringContainsString('qt_qobject_handlers.get_properties_for = qt_qobject_get_properties_for;', $cpp);
        Assert::assertStringContainsString('#ifdef PHP_WIN32', $cpp);
        Assert::assertStringContainsString('QObject::connectPropertyNotify() is not supported in this build yet', $cpp);
        Assert::assertStringContainsString('auto _qt_notify = _qt_property.notifySignal();', $cpp);
        Assert::assertStringContainsString('QMetaObject::Connection _qt_connection = QMetaObject::connect(', $cpp);
        Assert::assertStringNotContainsString('static bool qt_qobject_should_delegate_to_std_property(', $cpp);
        Assert::assertStringNotContainsString('static inline void qt_qobject_variant_to_property_zval(', $cpp);
        Assert::assertStringNotContainsString('#include "qt_qvariant.h"', $cpp);
        Assert::assertStringNotContainsString('object_init_ex(target, qt_ce_qvariant);', $cpp);
});

it('adds qobject property handlers to derived classes', function (): void {
        $fixtureRoot = qt_fixture_path('ownership-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-derived-qobject-properties-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
    
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qobjectownership.h',
            'class' => 'QObjectOwner',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
            ],
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QObject,QObjectOwner',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qobjectowner.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qobjectowner.cpp');
    
        Assert::assertStringNotContainsString('function property(string $name): mixed {}', $stub);
        Assert::assertStringContainsString('static zval *qt_qobjectowner_read_property(', $cpp);
        Assert::assertStringContainsString('qt_qobjectowner_handlers.read_property = qt_qobjectowner_read_property;', $cpp);
        Assert::assertStringContainsString('zend_declare_typed_property(', $cpp);
        Assert::assertStringContainsString('const QMetaObject &_qt_meta = QObjectOwner::staticMetaObject;', $cpp);
});

it('uses the automatic standard item ownership probe', function (): void {
        $fixtureRoot = qt_fixture_path('ownership-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-standarditem-ownership-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
    
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtGui/qstandarditemownership.h',
            'class' => 'QStandardItemModel',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtCore',
                $fixtureRoot . '/include/QtGui',
            ],
            '--module' => 'QtGui',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QObject,QStandardItem,QStandardItemModel',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qstandarditemmodel.cpp');
        Assert::assertStringContainsString('qt_qstandarditem_object *_qt_owned_arg_2 = qt_qstandarditem_from_obj(Z_OBJ_P(item));', $cpp);
        Assert::assertStringContainsString('(_qt_owned_arg_2->native_ptr != NULL && (_qt_owned_arg_2->native_ptr->model() != NULL || _qt_owned_arg_2->native_ptr->parent() != NULL))', $cpp);
        Assert::assertStringContainsString('qt_qstandarditem_object *_qt_owned_arg_1 = qt_qstandarditem_from_obj(Z_OBJ_P(item));', $cpp);
        Assert::assertStringContainsString('_qt_owned_arg_1->prevent_destroy = true;', $cpp);
});

it('uses the automatic table widget item ownership probe', function (): void {
        $fixtureRoot = qt_fixture_path('ownership-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-tablewidgetitem-ownership-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
    
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtWidgets/qtablewidgetownership.h',
            'class' => 'QTableWidget',
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
            '--allowed-classes' => 'QObject,QTableWidgetItem,QTableWidget',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qtablewidget.cpp');
        Assert::assertStringContainsString('qt_qtablewidgetitem_object *_qt_owned_arg_2 = qt_qtablewidgetitem_from_obj(Z_OBJ_P(item));', $cpp);
        Assert::assertStringContainsString('if ((_qt_owned_arg_2->native_ptr != NULL && _qt_owned_arg_2->native_ptr->tableWidget() != NULL)) {', $cpp);
        Assert::assertStringContainsString('_qt_owned_arg_2->prevent_destroy = true;', $cpp);
});

it('transfers qevent ownership for post event', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qcoreapplication.cpp');
        Assert::assertStringContainsString('QCoreApplication::postEvent(qt_qobject_from_obj(Z_OBJ_P(receiver))->native_ptr, qt_qevent_from_obj(Z_OBJ_P(event))->native_ptr, (int)priority);', $cpp);
        Assert::assertStringContainsString('qt_qevent_object *_qt_posted_event = qt_qevent_from_obj(Z_OBJ_P(event));', $cpp);
        Assert::assertStringContainsString('_qt_posted_event->prevent_destroy = true;', $cpp);
        Assert::assertStringContainsString('_qt_posted_event->native_ptr = NULL;', $cpp);
});

it('guards instance methods when native pointers are missing', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
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
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_quninstantiablething.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_quninstantiablething.cpp');
        Assert::assertStringContainsString('protected function __construct() {}', $stub);
        Assert::assertStringContainsString('zend_throw_error(NULL, "QUninstantiableThing cannot be instantiated directly.");', $cpp);
        Assert::assertStringContainsString('zend_throw_error(NULL, "QUninstantiableThing native instance is not initialized");', $cpp);
        Assert::assertStringContainsString('RETURN_THROWS();', $cpp);
});
