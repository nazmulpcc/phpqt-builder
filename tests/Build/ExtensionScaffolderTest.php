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
                    returnType: 'string',
                    access: 'public',
                    isStatic: false,
                    parameters: [],
                    overloads: [
                        new MethodOverload(
                            returnType: 'QString',
                            parameters: [],
                            isConst: true,
                            isStatic: false,
                            isVirtual: false,
                            isPureVirtual: false,
                        ),
                    ],
                ),
            ],
        );

        $generator->generate($phpClass, 'Qt\\Core', $outputDir);

        $source = (string) file_get_contents($outputDir . '/qt_qstringemitter.cpp');

        self::assertStringContainsString('#include <QString>', $source);
        self::assertStringContainsString('#include <QByteArray>', $source);
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
        );

        $generator->generate($phpClass, 'Qt\\Core', $outputDir);

        $source = (string) file_get_contents($outputDir . '/qt_qpoint.cpp');

        self::assertStringNotContainsString('static zend_object *qt_qpoint_clone_object', $source);
        self::assertStringContainsString('qt_qpoint_handlers.clone_obj = NULL;', $source);
    }
}
