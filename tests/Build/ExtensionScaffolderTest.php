<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Build;

use PHPUnit\Framework\TestCase;
use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Build\ExtensionScaffolder;
use QtBuilder\CodeGen\ExtensionGenerator;
use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Qt\QtInstallation;

final class ExtensionScaffolderTest extends TestCase
{
    public function testConfigM4InjectsDarwinFrameworkFlagsIntoCompilerAndLinkerVariables(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-scaffolder-' . bin2hex(random_bytes(4)) . '/ext';
        $installation = new QtInstallation(
            rootPath: '/opt/qt',
            osFamily: 'Darwin',
            includeRoots: ['/opt/qt/include', '-F/opt/qt/lib', '/opt/qt/lib/QtCore.framework/Headers'],
            libraryRoots: ['/opt/qt/lib'],
            moduleHeaderRoots: ['QtCore' => '/opt/qt/lib/QtCore.framework/Headers'],
            tools: [],
        );
        $context = new ExtensionBuildContext('qt', '0.1.0', $outputDir, $installation, ['QtCore'], ['QPoint']);

        $scaffolder = new ExtensionScaffolder();
        $scaffolder->prepare($context);
        $scaffolder->finalize($context);

        $config = (string) file_get_contents($outputDir . '/config.m4');

        self::assertStringContainsString('PHP_ADD_INCLUDE([/opt/qt/include])', $config);
        self::assertStringContainsString('CPPFLAGS="$CPPFLAGS -F/opt/qt/lib"', $config);
        self::assertStringContainsString('QT_SHARED_LIBADD="$QT_SHARED_LIBADD -F/opt/qt/lib -framework QtCore"', $config);
        self::assertStringContainsString('classes/qt_qpoint.cpp', $config);
    }

    public function testPrepareDoesNotWriteCoreFilesBeforeFinalize(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-scaffolder-' . bin2hex(random_bytes(4)) . '/ext';
        $installation = new QtInstallation(
            rootPath: '/opt/qt',
            osFamily: 'Darwin',
            includeRoots: ['/opt/qt/include'],
            libraryRoots: ['/opt/qt/lib'],
            moduleHeaderRoots: ['QtCore' => '/opt/qt/include/QtCore'],
            tools: [],
        );
        $context = new ExtensionBuildContext('qt', '0.1.0', $outputDir, $installation, ['QtCore'], ['QPoint']);

        $scaffolder = new ExtensionScaffolder();
        $scaffolder->prepare($context);

        self::assertFileDoesNotExist($outputDir . '/config.m4');
        self::assertFileDoesNotExist($outputDir . '/php_qt.h');
        self::assertFileDoesNotExist($outputDir . '/qt.cpp');

        $scaffolder->finalize($context);

        self::assertFileExists($outputDir . '/config.m4');
        self::assertFileExists($outputDir . '/php_qt.h');
        self::assertFileExists($outputDir . '/qt.cpp');
    }

    public function testConfigM4LinksAllRequestedDarwinFrameworkModules(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-scaffolder-' . bin2hex(random_bytes(4)) . '/ext';
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
        $context = new ExtensionBuildContext('qt', '0.1.0', $outputDir, $installation, ['QtCore', 'QtGui'], ['QPoint']);

        $scaffolder = new ExtensionScaffolder();
        $scaffolder->prepare($context);
        $scaffolder->finalize($context);

        $config = (string) file_get_contents($outputDir . '/config.m4');

        self::assertStringContainsString('-framework QtCore', $config);
        self::assertStringContainsString('-framework QtGui', $config);
    }

    public function testConfigM4PrefersResolvedModuleLinkFlags(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-scaffolder-' . bin2hex(random_bytes(4)) . '/ext';
        $installation = new QtInstallation(
            rootPath: '/opt/qt',
            osFamily: 'Darwin',
            includeRoots: ['/opt/qt/include', '-F/opt/qt/lib'],
            libraryRoots: ['/opt/qt/lib'],
            moduleHeaderRoots: ['QtCore' => '/opt/qt/lib/QtCore.framework/Headers'],
            moduleLinkFlags: '-F/custom/qt/lib -framework QtGui -framework QtCore',
            tools: [],
        );
        $context = new ExtensionBuildContext('qt', '0.1.0', $outputDir, $installation, ['QtCore', 'QtGui'], ['QPoint']);

        $scaffolder = new ExtensionScaffolder();
        $scaffolder->prepare($context);
        $scaffolder->finalize($context);

        $config = (string) file_get_contents($outputDir . '/config.m4');

        self::assertStringContainsString('QT_SHARED_LIBADD="$QT_SHARED_LIBADD -F/custom/qt/lib -framework QtGui -framework QtCore"', $config);
        self::assertStringNotContainsString('QT_SHARED_LIBADD="$QT_SHARED_LIBADD -F/opt/qt/lib -framework QtCore -framework QtGui"', $config);
    }

    public function testExtensionSourceRegistersParentsBeforeChildren(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-scaffolder-' . bin2hex(random_bytes(4)) . '/ext';
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

        self::assertLessThan(
            strpos($source, 'PHP_MINIT(qt_qcoreapplication)'),
            strpos($source, 'PHP_MINIT(qt_qobject)'),
        );
    }

    public function testExtensionSourceRegistersTypedDependenciesBeforeConsumers(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-scaffolder-' . bin2hex(random_bytes(4)) . '/ext';
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

        self::assertLessThan(
            strpos($source, 'PHP_MINIT(qt_qtexttable)'),
            strpos($source, 'PHP_MINIT(qt_qtexttableformat)'),
        );
    }

    public function testGeneratedHeaderGuardDoesNotCollideWithQtHeaderGuard(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generator-' . bin2hex(random_bytes(4));
        $generator = new ExtensionGenerator();
        $phpClass = new PhpClass(
            name: 'QAbstractAnimation',
            parent: 'QObject',
            isAbstract: true,
            isCopyConstructible: true,
            hasPublicDestructor: true,
            properties: [],
            methods: [],
            signals: [],
        );

        $generator->generate($phpClass, 'Qt\\Core', $outputDir);

        $header = (string) file_get_contents($outputDir . '/qt_qabstractanimation.h');

        self::assertStringContainsString('#ifndef QT_QABSTRACTANIMATION_H', $header);
        self::assertStringNotContainsString('#ifndef QABSTRACTANIMATION_H', $header);
    }

    public function testGeneratedSourceIncludesQStringAndQByteArrayHeaders(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generator-' . bin2hex(random_bytes(4));
        $generator = new ExtensionGenerator();
        $phpClass = new PhpClass(
            name: 'QStringEmitter',
            parent: null,
            isAbstract: false,
            isCopyConstructible: true,
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

        self::assertStringContainsString('#include <QString>', $source);
        self::assertStringContainsString('#include <QByteArray>', $source);
    }

    public function testGeneratedStubQualifiesCrossNamespaceQtTypes(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generator-' . bin2hex(random_bytes(4));
        $generator = new ExtensionGenerator();
        $phpClass = new PhpClass(
            name: 'QGuiApplication',
            parent: 'QCoreApplication',
            isAbstract: false,
            isCopyConstructible: false,
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

        self::assertStringContainsString('class QGuiApplication extends \\Qt\\Core\\QCoreApplication', $stub);
        self::assertStringContainsString('function focusObject(): \\Qt\\Core\\QObject {}', $stub);
    }

    public function testGeneratedValueTypeDisablesCloneWhenCopyConstructorIsUnavailable(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generator-' . bin2hex(random_bytes(4));
        $generator = new ExtensionGenerator();
        $phpClass = new PhpClass(
            name: 'QPoint',
            parent: null,
            isAbstract: false,
            isCopyConstructible: false,
            hasPublicDestructor: true,
            properties: [],
            methods: [],
            signals: [],
        );

        $generator->generate($phpClass, 'Qt\\Core', $outputDir);

        $source = (string) file_get_contents($outputDir . '/qt_qpoint.cpp');

        self::assertStringNotContainsString('static zend_object *qt_qpoint_clone_object', $source);
        self::assertStringContainsString('qt_qpoint_handlers.clone_obj = NULL;', $source);
    }

    public function testGeneratedValueTypeStubIsNotMarkedFinal(): void
    {
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generator-' . bin2hex(random_bytes(4));
        $generator = new ExtensionGenerator();
        $phpClass = new PhpClass(
            name: 'QPixmap',
            parent: 'QPaintDevice',
            isAbstract: false,
            isCopyConstructible: true,
            hasPublicDestructor: true,
            properties: [],
            methods: [],
            signals: [],
        );

        $generator->generate($phpClass, 'Qt\\Gui', $outputDir);

        $stub = (string) file_get_contents($outputDir . '/qt_qpixmap.stub.php');
        $source = (string) file_get_contents($outputDir . '/qt_qpixmap.cpp');

        self::assertStringContainsString('class QPixmap extends QPaintDevice', $stub);
        self::assertStringNotContainsString('final class QPixmap', $stub);
        self::assertStringNotContainsString('ce_flags |= ZEND_ACC_FINAL;', $source);
    }
}
