<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Filtering\MethodExposurePolicy;

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
