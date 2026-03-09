<?php

declare(strict_types=1);

use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Build\ExtensionScaffolder;
use QtBuilder\CodeGen\ExtensionGenerator;
use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Qt\QtInstallation;

it('injects darwin framework flags into compiler and linker variables', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-scaffolder-') . '/ext';
    $installation = new QtInstallation(
        rootPath: '/opt/qt',
        osFamily: 'Darwin',
        includeRoots: ['/opt/qt/include', '-F/opt/qt/lib', '/opt/qt/lib/QtCore.framework/Headers'],
        libraryRoots: ['/opt/qt/lib'],
        moduleHeaderRoots: ['QtCore' => '/opt/qt/lib/QtCore.framework/Headers'],
        tools: [],
    );
    $context = new ExtensionBuildContext('qt', '0.1.0', dirname($outputDir), $outputDir, $installation, ['QtCore'], ['QPoint']);

    $scaffolder = new ExtensionScaffolder();
    $scaffolder->prepare($context);
    $scaffolder->finalize($context);

    $config = (string) file_get_contents($outputDir . '/config.m4');

    expect($config)->toContain(
        'PHP_ADD_INCLUDE([/opt/qt/include])',
        'CPPFLAGS="$CPPFLAGS -F/opt/qt/lib"',
        'QT_SHARED_LIBADD="$QT_SHARED_LIBADD -F/opt/qt/lib -framework QtCore"',
        'classes/qt_qpoint.cpp',
    );
});

it('does not write core files before finalize', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-scaffolder-') . '/ext';
    $installation = new QtInstallation(
        rootPath: '/opt/qt',
        osFamily: 'Darwin',
        includeRoots: ['/opt/qt/include'],
        libraryRoots: ['/opt/qt/lib'],
        moduleHeaderRoots: ['QtCore' => '/opt/qt/include/QtCore'],
        tools: [],
    );
    $context = new ExtensionBuildContext('qt', '0.1.0', dirname($outputDir), $outputDir, $installation, ['QtCore'], ['QPoint']);

    $scaffolder = new ExtensionScaffolder();
    $scaffolder->prepare($context);

    expect(is_file($outputDir . '/config.m4'))->toBeFalse()
        ->and(is_file($outputDir . '/php_qt.h'))->toBeFalse()
        ->and(is_file($outputDir . '/qt.cpp'))->toBeFalse();

    $scaffolder->finalize($context);

    expect(is_file($outputDir . '/config.m4'))->toBeTrue()
        ->and(is_file($outputDir . '/php_qt.h'))->toBeTrue()
        ->and(is_file($outputDir . '/qt.cpp'))->toBeTrue();
});

it('links all requested darwin framework modules', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-scaffolder-') . '/ext';
    $installation = new QtInstallation(
        rootPath: '/opt/qt',
        osFamily: 'Darwin',
        includeRoots: [
            '/opt/qt/include',
            '-F/opt/qt/lib',
            '/opt/qt/lib/QtCore.framework/Headers',
            '/opt/qt/lib/QtGui.framework/Headers',
        ],
        libraryRoots: ['/opt/qt/lib'],
        moduleHeaderRoots: [
            'QtCore' => '/opt/qt/lib/QtCore.framework/Headers',
            'QtGui' => '/opt/qt/lib/QtGui.framework/Headers',
        ],
        tools: [],
    );
    $context = new ExtensionBuildContext('qt', '0.1.0', dirname($outputDir), $outputDir, $installation, ['QtCore', 'QtGui'], ['QPoint']);

    $scaffolder = new ExtensionScaffolder();
    $scaffolder->prepare($context);
    $scaffolder->finalize($context);

    $config = (string) file_get_contents($outputDir . '/config.m4');

    expect($config)->toContain('-framework QtCore', '-framework QtGui');
});

it('prefers resolved module link flags', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-scaffolder-') . '/ext';
    $installation = new QtInstallation(
        rootPath: '/opt/qt',
        osFamily: 'Darwin',
        includeRoots: ['/opt/qt/include', '-F/opt/qt/lib'],
        libraryRoots: ['/opt/qt/lib'],
        moduleHeaderRoots: ['QtCore' => '/opt/qt/lib/QtCore.framework/Headers'],
        moduleLinkFlags: '-F/custom/qt/lib -framework QtGui -framework QtCore',
        tools: [],
    );
    $context = new ExtensionBuildContext('qt', '0.1.0', dirname($outputDir), $outputDir, $installation, ['QtCore', 'QtGui'], ['QPoint']);

    $scaffolder = new ExtensionScaffolder();
    $scaffolder->prepare($context);
    $scaffolder->finalize($context);

    $config = (string) file_get_contents($outputDir . '/config.m4');

    expect($config)->toContain('QT_SHARED_LIBADD="$QT_SHARED_LIBADD -F/custom/qt/lib -framework QtGui -framework QtCore"')
        ->not->toContain('QT_SHARED_LIBADD="$QT_SHARED_LIBADD -F/opt/qt/lib -framework QtCore -framework QtGui"');
});

it('registers parents before children in extension source', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-scaffolder-') . '/ext';
    $installation = new QtInstallation(
        rootPath: '/opt/qt',
        osFamily: 'Darwin',
        includeRoots: ['/opt/qt/include'],
        libraryRoots: ['/opt/qt/lib'],
        moduleHeaderRoots: ['QtCore' => '/opt/qt/include/QtCore'],
        tools: [],
    );
    $context = new ExtensionBuildContext(
        'qt',
        '0.1.0',
        dirname($outputDir),
        $outputDir,
        $installation,
        ['QtCore'],
        ['QCoreApplication', 'QObject'],
        ['QCoreApplication' => 'QObject'],
    );

    $scaffolder = new ExtensionScaffolder();
    $scaffolder->prepare($context);
    $scaffolder->finalize($context);

    $source = (string) file_get_contents($outputDir . '/qt.cpp');

    expect(strpos($source, 'PHP_MINIT(qt_qobject)'))
        ->toBeLessThan(strpos($source, 'PHP_MINIT(qt_qcoreapplication)'))
        ->and($source)->toContain(
            'static std::atomic_bool qt_shutdown_in_progress{false};',
            'static std::atomic_bool qt_about_to_quit_hooked{false};',
            'bool qt_runtime_is_shutdown_in_progress(void)',
            'void qt_runtime_mark_shutdown_in_progress(void)',
            'void qt_runtime_try_hook_about_to_quit(void)',
            'static inline void qt_runtime_shutdown_qcoreapplication(void)',
            'PHP_RINIT_FUNCTION(qt)',
            'PHP_RSHUTDOWN_FUNCTION(qt)',
            'PHP_RINIT(qt)',
            'PHP_RSHUTDOWN(qt)',
            '&QCoreApplication::aboutToQuit',
            'qt_runtime_shutdown_qcoreapplication();',
        );

    $header = (string) file_get_contents($outputDir . '/php_qt.h');
    expect($header)->toContain(
        'bool qt_runtime_is_shutdown_in_progress(void);',
        'void qt_runtime_mark_shutdown_in_progress(void);',
        'void qt_runtime_try_hook_about_to_quit(void);',
    );
});

it('registers typed dependencies before consumers', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-scaffolder-') . '/ext';
    $installation = new QtInstallation(
        rootPath: '/opt/qt',
        osFamily: 'Darwin',
        includeRoots: ['/opt/qt/include'],
        libraryRoots: ['/opt/qt/lib'],
        moduleHeaderRoots: ['QtGui' => '/opt/qt/include/QtGui'],
        tools: [],
    );
    $context = new ExtensionBuildContext(
        'qt',
        '0.1.0',
        dirname($outputDir),
        $outputDir,
        $installation,
        ['QtGui'],
        ['QTextTable', 'QTextTableFormat', 'QTextFrame', 'QTextFrameFormat'],
        [
            'QTextTable' => 'QTextFrame',
            'QTextTableFormat' => 'QTextFrameFormat',
        ],
        [
            'QTextTable' => ['QTextCursor', 'QTextDocument', 'QTextTableCell', 'QTextTableFormat'],
            'QTextTableFormat' => [],
            'QTextFrame' => [],
            'QTextFrameFormat' => [],
        ],
    );

    $scaffolder = new ExtensionScaffolder();
    $scaffolder->prepare($context);
    $scaffolder->finalize($context);

    $source = (string) file_get_contents($outputDir . '/qt.cpp');

    expect(strpos($source, 'PHP_MINIT(qt_qtexttableformat)'))
        ->toBeLessThan(strpos($source, 'PHP_MINIT(qt_qtexttable)'));
});

it('uses a generated header guard that does not collide with qt', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generator-');
    $generator = new ExtensionGenerator();
    $phpClass = new PhpClass(
        name: 'QAbstractAnimation',
        parent: 'QObject',
        isAbstract: true,
        isCopyConstructible: true,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [],
        signals: [],
    );

    $generator->generate($phpClass, 'Qt\\Core', $outputDir);

    $header = (string) file_get_contents($outputDir . '/qt_qabstractanimation.h');

    expect($header)->toContain('#ifndef QT_QABSTRACTANIMATION_H')
        ->not->toContain('#ifndef QABSTRACTANIMATION_H');
});

it('includes qstring and qbytearray headers in generated source', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generator-');
    $generator = new ExtensionGenerator();
    $phpClass = new PhpClass(
        name: 'QStringEmitter',
        parent: null,
        isAbstract: false,
        isCopyConstructible: true,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [
            new PhpMethod(
                name: 'label',
                access: 'public',
                isStatic: false,
                isSignal: false,
                isSlot: false,
                isAbstractMethod: false,
                returnType: 'string',
                parameters: [],
                overloads: [
                    new MethodOverload(
                        declaringClass: 'QStringEmitter',
                        returnType: 'QString',
                        smartPointerReturnTargetCppType: null,
                        parameters: [],
                        access: 'public',
                        isConst: true,
                        isStatic: false,
                        isVirtual: false,
                        isPureVirtual: false,
                    ),
                ],
            ),
        ],
        signals: [],
    );

    $generator->generate($phpClass, 'Qt\\Core', $outputDir);

    $source = (string) file_get_contents($outputDir . '/qt_qstringemitter.cpp');

    expect($source)->toContain('#include <QString>', '#include <QByteArray>');
});

it('makes generated qstring wrappers stringable', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generator-');
    $generator = new ExtensionGenerator();
    $phpClass = new PhpClass(
        name: 'QString',
        parent: null,
        isAbstract: false,
        isCopyConstructible: true,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [],
        signals: [],
        isQObjectDerived: false,
        nativeIncludes: ['<QString>'],
        nativeCppType: 'QString',
    );

    $generator->generate($phpClass, 'Qt\\Core', $outputDir);

    $stub = (string) file_get_contents($outputDir . '/qt_qstring.stub.php');
    $source = (string) file_get_contents($outputDir . '/qt_qstring.cpp');

    expect($stub)->toContain(
        'class QString implements \\Stringable',
        'public function __toString(): string {}',
    );

    expect($source)->toContain(
        'ZEND_METHOD(Qt_Core_QString, __toString)',
        'QByteArray _qt_utf8 = intern->native_ptr->toUtf8();',
        'zend_class_implements(qt_ce_qstring, 1, zend_ce_stringable);',
    );
});

it('qualifies cross namespace qt types in generated stubs', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generator-');
    $generator = new ExtensionGenerator();
    $phpClass = new PhpClass(
        name: 'QGuiApplication',
        parent: 'QCoreApplication',
        isAbstract: false,
        isCopyConstructible: false,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [
            new PhpMethod(
                name: 'focusObject',
                access: 'public',
                isStatic: true,
                isSignal: false,
                isSlot: false,
                isAbstractMethod: false,
                returnType: 'QObject',
                parameters: [],
                overloads: [
                    new MethodOverload(
                        declaringClass: 'QGuiApplication',
                        returnType: 'QObject *',
                        smartPointerReturnTargetCppType: null,
                        parameters: [],
                        access: 'public',
                        isConst: false,
                        isStatic: true,
                        isVirtual: false,
                        isPureVirtual: false,
                    ),
                ],
            ),
        ],
        signals: [],
    );

    $generator->generate($phpClass, 'Qt\\Gui', $outputDir, [
        'QGuiApplication' => 'Qt\\Gui',
        'QCoreApplication' => 'Qt\\Core',
        'QObject' => 'Qt\\Core',
    ]);

    $stub = (string) file_get_contents($outputDir . '/qt_qguiapplication.stub.php');

    expect($stub)->toContain(
        'class QGuiApplication extends \\Qt\\Core\\QCoreApplication',
        'function focusObject(): \\Qt\\Core\\QObject {}',
    );
});

it('emits qualified native cpp types for namespaced classes', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generator-');
    $generator = new ExtensionGenerator();
    $phpClass = new PhpClass(
        name: 'QNode',
        parent: 'QObject',
        isAbstract: false,
        isCopyConstructible: false,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [],
        signals: [],
        isQObjectDerived: true,
        nativeIncludes: ['<Qt3DCore/QNode>'],
        nativeCppType: 'Qt3DCore::QNode',
    );

    $generator->generate($phpClass, 'Qt\\Qt3DCore', $outputDir, ['QObject' => 'Qt\\Core']);

    $header = (string) file_get_contents($outputDir . '/qt_qnode__qt3dcore.h');

    expect($header)->toContain(
        '#include <Qt3DCore/QNode>',
        'Qt3DCore::QNode *native_ptr;',
        'qt_qnode__qt3dcore_wrap_native(zval *return_value, Qt3DCore::QNode *native,',
    );
});

it('emits distinct wrapper artifacts for colliding short names across modules', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generator-');
    $generator = new ExtensionGenerator();

    $guiTransform = new PhpClass(
        name: 'QTransform',
        parent: null,
        isAbstract: false,
        isCopyConstructible: true,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [],
        signals: [],
        isQObjectDerived: false,
        nativeIncludes: ['<QtGui/QTransform>'],
        nativeCppType: 'QTransform',
    );

    $qt3dTransform = new PhpClass(
        name: 'QTransform',
        parent: 'QComponent',
        isAbstract: false,
        isCopyConstructible: false,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [],
        signals: [],
        isQObjectDerived: true,
        nativeIncludes: ['<Qt3DCore/QTransform>'],
        nativeCppType: 'Qt3DCore::QTransform',
    );

    $generator->generate($guiTransform, 'Qt\\Gui', $outputDir, []);
    $generator->generate($qt3dTransform, 'Qt\\Qt3DCore', $outputDir, ['QComponent' => 'Qt\\Qt3DCore']);

    $guiHeader = (string) file_get_contents($outputDir . '/qt_qtransform.h');
    $guiSource = (string) file_get_contents($outputDir . '/qt_qtransform.cpp');
    $qt3dHeader = (string) file_get_contents($outputDir . '/qt_qtransform__qt3dcore.h');
    $qt3dSource = (string) file_get_contents($outputDir . '/qt_qtransform__qt3dcore.cpp');

    expect($guiHeader)->toContain(
        '#include <QtGui/QTransform>',
        'QTransform *native_ptr;',
        'qt_ce_qtransform',
    );

    expect($qt3dHeader)->toContain(
        '#include <Qt3DCore/QTransform>',
        'Qt3DCore::QTransform *native_ptr;',
        'qt_ce_qtransform__qt3dcore',
        'qt_qtransform__qt3dcore_wrap_native',
    );

    expect($guiSource)->not->toContain('qt_runtime_try_hook_about_to_quit();');
    expect($qt3dSource)->toContain('qt_runtime_try_hook_about_to_quit();');
});

it('disables cloning for value types without copy constructors', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generator-');
    $generator = new ExtensionGenerator();
    $phpClass = new PhpClass(
        name: 'QPoint',
        parent: null,
        isAbstract: false,
        isCopyConstructible: false,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [],
        signals: [],
    );

    $generator->generate($phpClass, 'Qt\\Core', $outputDir);

    $source = (string) file_get_contents($outputDir . '/qt_qpoint.cpp');

    expect($source)->not->toContain('static zend_object *qt_qpoint_clone_object')
        ->and($source)->toContain('qt_qpoint_handlers.clone_obj = NULL;');
});

it('does not mark generated value type stubs as final', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generator-');
    $generator = new ExtensionGenerator();
    $phpClass = new PhpClass(
        name: 'QPixmap',
        parent: 'QPaintDevice',
        isAbstract: false,
        isCopyConstructible: true,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [],
        signals: [],
    );

    $generator->generate($phpClass, 'Qt\\Gui', $outputDir);

    $stub = (string) file_get_contents($outputDir . '/qt_qpixmap.stub.php');
    $source = (string) file_get_contents($outputDir . '/qt_qpixmap.cpp');

    expect($stub)->toContain('class QPixmap extends QPaintDevice')
        ->not->toContain('final class QPixmap');
    expect($source)->not->toContain('ce_flags |= ZEND_ACC_FINAL;');
});

it('skips unchanged class outputs on repeated generation', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generator-');
    $generator = new ExtensionGenerator();
    $phpClass = new PhpClass(
        name: 'QPoint',
        parent: null,
        isAbstract: false,
        isCopyConstructible: true,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [],
        signals: [],
    );

    $generator->generate($phpClass, 'Qt\\Core', $outputDir);
    $firstStats = $generator->lastWriteStats()->toArray();
    expect($firstStats['written'])->toBe(3);

    $headerFile = $outputDir . '/qt_qpoint.h';
    touch($headerFile, 1_000_000_000);
    clearstatcache(true, $headerFile);
    $before = filemtime($headerFile);

    $generator->generate($phpClass, 'Qt\\Core', $outputDir);
    $secondStats = $generator->lastWriteStats()->toArray();
    clearstatcache(true, $headerFile);
    $after = filemtime($headerFile);

    expect($secondStats['unchanged'])->toBe(3)
        ->and($secondStats['written'])->toBe(0)
        ->and($after)->toBe($before);
});

it('skips unchanged core scaffold files on repeated finalize', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-scaffolder-') . '/ext';
    $installation = new QtInstallation(
        rootPath: '/opt/qt',
        osFamily: 'Darwin',
        includeRoots: ['/opt/qt/include'],
        libraryRoots: ['/opt/qt/lib'],
        moduleHeaderRoots: ['QtCore' => '/opt/qt/include/QtCore'],
        tools: [],
    );
    $context = new ExtensionBuildContext('qt', '0.1.0', dirname($outputDir), $outputDir, $installation, ['QtCore'], ['QPoint']);

    $scaffolder = new ExtensionScaffolder();
    $scaffolder->prepare($context);
    $scaffolder->finalize($context);
    $firstStats = $scaffolder->lastWriteStats()->toArray();
    expect($firstStats['written'])->toBe(3);

    $moduleSource = $outputDir . '/qt.cpp';
    touch($moduleSource, 1_000_000_000);
    clearstatcache(true, $moduleSource);
    $before = filemtime($moduleSource);

    $scaffolder->finalize($context);
    $secondStats = $scaffolder->lastWriteStats()->toArray();
    clearstatcache(true, $moduleSource);
    $after = filemtime($moduleSource);

    expect($secondStats['unchanged'])->toBe(3)
        ->and($secondStats['written'])->toBe(0)
        ->and($after)->toBe($before);
});
