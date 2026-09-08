<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Filtering\MethodExposurePolicy;
use QtBuilder\Support\CppClassTypeResolver;

it('filters macro-like and helper methods by name', function (): void {
    $policy = new MethodExposurePolicy();

    $classData = [
        'name' => 'QMethodPolicyFixture',
        'methods' => [
            [
                'name' => 'QT_CORE_CONSTEXPR_INLINE_SINCE',
                'return_type' => 'int',
                'access' => 'public',
                'parameters' => [],
                'is_static' => false,
            ],
            [
                'name' => 'Q_DECLARE_STRONGLY_ORDERED',
                'return_type' => 'int',
                'access' => 'public',
                'parameters' => [],
                'is_static' => false,
            ],
            [
                'name' => 'compare_helper',
                'return_type' => 'int',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'lhs', 'type' => 'int', 'has_default' => false],
                ],
                'is_static' => true,
            ],
            [
                'name' => 'size',
                'return_type' => 'int',
                'access' => 'public',
                'parameters' => [],
                'is_static' => false,
            ],
        ],
    ];

    $result = $policy->filter($classData, ['QMethodPolicyFixture']);

    Assert::assertSame(['size'], array_column($result['selected_methods'], 'name'));

    $skippedNames = array_column($result['skipped_methods'], 'name');
    $skippedReasons = array_column($result['skipped_methods'], 'reason_code');

    Assert::assertContains('QT_CORE_CONSTEXPR_INLINE_SINCE', $skippedNames);
    Assert::assertContains('Q_DECLARE_STRONGLY_ORDERED', $skippedNames);
    Assert::assertContains('compare_helper', $skippedNames);
    Assert::assertContains('method_name_filtered', $skippedReasons);
});

it('accepts inherited and global enum-like parameter types', function (): void {
    $policy = new MethodExposurePolicy();

    $classData = [
        'name' => 'QBuffer',
        'methods' => [
            [
                'name' => 'open',
                'return_type' => 'bool',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'mode', 'type' => 'OpenMode', 'has_default' => false],
                ],
                'is_static' => false,
            ],
            [
                'name' => 'isEnabled',
                'return_type' => 'bool',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'type', 'type' => 'QtMsgType', 'has_default' => false],
                ],
                'is_static' => false,
            ],
        ],
    ];

    $result = $policy->filter($classData, ['QBuffer']);
    $selectedByName = [];
    foreach ($result['selected_methods'] as $method) {
        $selectedByName[$method['name']] = $method;
    }

    Assert::assertArrayHasKey('open', $selectedByName);
    Assert::assertArrayHasKey('isEnabled', $selectedByName);
    Assert::assertSame('QBuffer::OpenMode', $selectedByName['open']['parameters'][0]['type']);
    Assert::assertSame('QtMsgType', $selectedByName['isEnabled']['parameters'][0]['type']);
});

it('does not treat qt function typedef names as enums', function (): void {
    $policy = new MethodExposurePolicy();

    $classData = [
        'name' => 'QStaticPlugin',
        'methods' => [[
            'name' => 'QStaticPlugin',
            'return_type' => 'void',
            'access' => 'public',
            'parameters' => [
                ['name' => 'i', 'type' => 'QtPluginInstanceFunction', 'has_default' => false],
                ['name' => 'm', 'type' => 'QtPluginMetaDataFunction', 'has_default' => false],
            ],
            'is_static' => false,
        ]],
    ];

    $result = $policy->filter($classData, ['QStaticPlugin']);
    Assert::assertSame([], $result['selected_methods']);
    Assert::assertContains('unsupported_parameter_type', array_column($result['skipped_methods'], 'reason_code'));
});

it('strips trailing qt disambiguation tag parameters from exposed methods', function (): void {
    $policy = new MethodExposurePolicy();

    $classData = [
        'name' => 'QObject',
        'methods' => [[
            'name' => 'moveToThread',
            'return_type' => 'bool',
            'access' => 'public',
            'parameters' => [
                ['name' => 'thread', 'type' => 'QThread *', 'has_default' => false],
                ['name' => '', 'type' => 'Qt::Disambiguated_t', 'has_default' => true],
            ],
            'is_static' => false,
        ]],
    ];

    $result = $policy->filter($classData, ['QObject', 'QThread']);

    Assert::assertCount(1, $result['selected_methods']);
    Assert::assertSame('moveToThread', $result['selected_methods'][0]['name']);
    Assert::assertSame([['name' => 'thread', 'type' => 'QThread *', 'has_default' => false]], $result['selected_methods'][0]['parameters']);
});

it('supports opengl const void input buffers only for opengl classes', function (): void {
    $policy = new MethodExposurePolicy();

    $openGlClassData = [
        'name' => 'QOpenGLBuffer',
        'methods' => [[
            'name' => 'allocate',
            'return_type' => 'void',
            'access' => 'public',
            'parameters' => [
                ['name' => 'data', 'type' => 'const void *', 'has_default' => false],
                ['name' => 'count', 'type' => 'int', 'has_default' => false],
            ],
            'is_static' => false,
        ]],
    ];

    $genericClassData = [
        'name' => 'QByteArray',
        'methods' => [[
            'name' => 'fromBlob',
            'return_type' => 'void',
            'access' => 'public',
            'parameters' => [
                ['name' => 'data', 'type' => 'const void *', 'has_default' => false],
            ],
            'is_static' => false,
        ]],
    ];

    $openGlResult = $policy->filter($openGlClassData, ['QOpenGLBuffer']);
    $genericResult = $policy->filter($genericClassData, ['QByteArray']);

    Assert::assertSame(['allocate'], array_column($openGlResult['selected_methods'], 'name'));
    Assert::assertSame([], $genericResult['selected_methods']);
    Assert::assertContains('unsupported_parameter_type', array_column($genericResult['skipped_methods'], 'reason_code'));
});

it('accepts qualified namespaced object parameter and return types when resolver data is available', function (): void {
    $policy = new MethodExposurePolicy();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QNodeId', 'qualified_name' => 'Qt3DCore::QNodeId', 'module' => 'Qt3DCore'],
        ['name' => 'QRayCasterHit', 'qualified_name' => 'Qt3DRender::QRayCasterHit', 'module' => 'Qt3DRender'],
    ]);

    $classData = [
        'name' => 'QRayCasterHit',
        'qualified_name' => 'Qt3DRender::QRayCasterHit',
        'methods' => [
            [
                'name' => 'entityId',
                'return_type' => 'Qt3DCore::QNodeId',
                'access' => 'public',
                'parameters' => [],
                'is_static' => false,
            ],
            [
                'name' => 'setEntityId',
                'return_type' => 'void',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'id', 'type' => 'Qt3DCore::QNodeId', 'has_default' => false],
                ],
                'is_static' => false,
            ],
        ],
    ];

    $result = $policy->filter($classData, ['QRayCasterHit', 'QNodeId'], false, null, $resolver);

    Assert::assertSame(['entityId', 'setEntityId'], array_column($result['selected_methods'], 'name'));
    Assert::assertSame([], $result['skipped_methods']);
});

it('accepts foreign nested enum types when the owner class resolves cleanly', function (): void {
    $policy = new MethodExposurePolicy();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QTextureData', 'qualified_name' => 'Qt3DRender::QTextureData', 'module' => 'Qt3DRender'],
        ['name' => 'QAbstractTexture', 'qualified_name' => 'Qt3DRender::QAbstractTexture', 'module' => 'Qt3DRender'],
        ['name' => 'QTextureWrapMode', 'qualified_name' => 'Qt3DRender::QTextureWrapMode', 'module' => 'Qt3DRender'],
    ]);

    $classData = [
        'name' => 'QTextureData',
        'qualified_name' => 'Qt3DRender::QTextureData',
        'methods' => [
            [
                'name' => 'target',
                'return_type' => 'QAbstractTexture::Target',
                'access' => 'public',
                'parameters' => [],
                'is_static' => false,
            ],
            [
                'name' => 'setWrapModeX',
                'return_type' => 'void',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'mode', 'type' => 'QTextureWrapMode::WrapMode', 'has_default' => false],
                ],
                'is_static' => false,
            ],
        ],
    ];

    $result = $policy->filter($classData, ['QTextureData', 'QAbstractTexture', 'QTextureWrapMode'], false, null, $resolver);

    Assert::assertSame(['target', 'setWrapModeX'], array_column($result['selected_methods'], 'name'));
    Assert::assertSame([], $result['skipped_methods']);
});

it('accepts qsharedpointer alias parameter and return types through their pointee class', function (): void {
    $policy = new MethodExposurePolicy();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QAspectEngine', 'qualified_name' => 'Qt3DCore::QAspectEngine', 'module' => 'Qt3DCore'],
        ['name' => 'QEntity', 'qualified_name' => 'Qt3DCore::QEntity', 'module' => 'Qt3DCore'],
    ]);

    $classData = [
        'name' => 'QAspectEngine',
        'qualified_name' => 'Qt3DCore::QAspectEngine',
        'smart_pointer_aliases' => ['QEntityPtr' => 'Qt3DCore::QEntity'],
        'methods' => [
            [
                'name' => 'setRootEntity',
                'return_type' => 'void',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'root', 'type' => 'QEntityPtr', 'has_default' => false],
                ],
                'is_static' => false,
            ],
            [
                'name' => 'rootEntity',
                'return_type' => 'QEntityPtr',
                'access' => 'public',
                'parameters' => [],
                'is_static' => false,
            ],
        ],
    ];

    $result = $policy->filter($classData, ['QAspectEngine', 'QEntity'], false, null, $resolver);

    Assert::assertSame(['setRootEntity', 'rootEntity'], array_column($result['selected_methods'], 'name'));
    Assert::assertSame([], $result['skipped_methods']);
});

it('filters canonicalized namespaced copy constructors for noncopyable classes', function (): void {
    $policy = new MethodExposurePolicy();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QBackendNode', 'qualified_name' => 'Qt3DCore::QBackendNode', 'module' => 'Qt3DCore'],
    ]);

    $classData = [
        'name' => 'QBackendNode',
        'qualified_name' => 'Qt3DCore::QBackendNode',
        'is_copy_constructible' => false,
        'methods' => [[
            'name' => 'QBackendNode',
            'return_type' => 'void',
            'access' => 'public',
            'parameters' => [
                ['name' => 'other', 'type' => 'const Qt3DCore::QBackendNode &', 'has_default' => false],
            ],
            'is_static' => false,
        ]],
    ];

    $result = $policy->filter($classData, ['QBackendNode'], false, null, $resolver);

    Assert::assertSame([], $result['selected_methods']);
    Assert::assertContains('copy_constructor_filtered', array_column($result['skipped_methods'], 'reason_code'));
});

it('accepts pair-sequence containers with object keys when key classes are allowed', function (): void {
    $policy = new MethodExposurePolicy();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QBluetoothDeviceInfo', 'qualified_name' => 'QBluetoothDeviceInfo', 'module' => 'QtBluetooth'],
        ['name' => 'QBluetoothUuid', 'qualified_name' => 'QBluetoothUuid', 'module' => 'QtBluetooth'],
    ]);

    $classData = [
        'name' => 'QBluetoothDeviceInfo',
        'qualified_name' => 'QBluetoothDeviceInfo',
        'methods' => [[
            'name' => 'serviceDataMap',
            'return_type' => 'QMultiHash<QBluetoothUuid, QByteArray>',
            'access' => 'public',
            'parameters' => [],
            'is_static' => false,
        ]],
    ];

    $result = $policy->filter($classData, ['QBluetoothDeviceInfo', 'QBluetoothUuid'], false, null, $resolver);

    Assert::assertSame(['serviceDataMap'], array_column($result['selected_methods'], 'name'));
    Assert::assertSame([], $result['skipped_methods']);
});

it('accepts same-class reference returns on instance methods for fluent chaining', function (): void {
    $policy = new MethodExposurePolicy();

    $classData = [
        'name' => 'QString',
        'methods' => [
            [
                'name' => 'append',
                'return_type' => 'QString &',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'str', 'type' => 'const QString &', 'has_default' => false],
                ],
                'is_static' => false,
            ],
            [
                'name' => 'front',
                'return_type' => 'QChar &',
                'access' => 'public',
                'parameters' => [],
                'is_static' => false,
            ],
            [
                'name' => 'globalDefault',
                'return_type' => 'QString &',
                'access' => 'public',
                'parameters' => [],
                'is_static' => true,
            ],
        ],
    ];

    $result = $policy->filter($classData, ['QString']);

    Assert::assertSame(['append'], array_column($result['selected_methods'], 'name'));

    $skipped = [];
    foreach ($result['skipped_methods'] as $item) {
        $skipped[$item['name']] = $item['reason_code'];
    }

    Assert::assertArrayHasKey('front', $skipped);
    Assert::assertSame('unsupported_reference_return', $skipped['front']);
    Assert::assertArrayHasKey('globalDefault', $skipped);
    Assert::assertSame('unsupported_reference_return', $skipped['globalDefault']);
});
