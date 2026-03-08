<?php

declare(strict_types=1);

use QtBuilder\Parsing\ClassDefinitionBuilder;
use QtBuilder\Support\CppClassTypeResolver;

it('merges parameters using the minimum required argument count across overloads', function (): void {
    $builder = new ClassDefinitionBuilder();

    $class = $builder->build([
        'name' => 'QLineEditLike',
        'is_abstract' => false,
        'is_struct' => false,
        'bases' => [],
        'properties' => [],
        'methods' => [
            [
                'name' => 'QLineEditLike',
                'return_type' => 'void',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'parent', 'type' => 'QWidget *', 'has_default' => true],
                ],
                'is_static' => false,
                'is_const' => false,
                'is_virtual' => false,
                'is_pure_virtual' => false,
                'is_override' => false,
            ],
            [
                'name' => 'QLineEditLike',
                'return_type' => 'void',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'contents', 'type' => 'QString', 'has_default' => false],
                    ['name' => 'parent', 'type' => 'QWidget *', 'has_default' => true],
                ],
                'is_static' => false,
                'is_const' => false,
                'is_virtual' => false,
                'is_pure_virtual' => false,
                'is_override' => false,
            ],
        ],
        'signals' => [],
    ]);

    expect($class->methods)->toHaveCount(1);

    $constructor = $class->methods[0];
    expect($constructor->name)->toBe('__construct')
        ->and($constructor->parameters)->toHaveCount(2)
        ->and($constructor->parameters[0]->hasDefault)->toBeTrue()
        ->and($constructor->parameters[1]->hasDefault)->toBeTrue()
        ->and($constructor->parameters[0]->phpType)->toBe('QWidget|string')
        ->and($constructor->parameters[1]->phpType)->toBe('QWidget');
});

it('preserves qualified native cpp types for namespaced classes', function (): void {
    $builder = new ClassDefinitionBuilder();

    $class = $builder->build([
        'name' => 'QNode',
        'qualified_name' => 'Qt3DCore::QNode',
        'is_abstract' => false,
        'is_struct' => false,
        'bases' => ['QObject'],
        'properties' => [],
        'methods' => [],
        'signals' => [],
    ]);

    expect($class->nativeCppType)->toBe('Qt3DCore::QNode')
        ->and($class->parent)->toBe('QObject');
});

it('canonicalizes same-namespace class references for namespaced classes', function (): void {
    $builder = new ClassDefinitionBuilder();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QNodeId', 'qualified_name' => 'Qt3DCore::QNodeId', 'module' => 'Qt3DCore'],
    ]);

    $class = $builder->build([
        'name' => 'QNodeId',
        'qualified_name' => 'Qt3DCore::QNodeId',
        'is_abstract' => false,
        'is_struct' => false,
        'bases' => [],
        'properties' => [
            ['name' => 'peer', 'type' => 'const QNodeId *', 'access' => 'public', 'is_static' => false],
        ],
        'methods' => [
            [
                'name' => 'createId',
                'return_type' => 'QNodeId',
                'access' => 'public',
                'parameters' => [],
                'is_static' => true,
                'is_const' => false,
                'is_virtual' => false,
                'is_pure_virtual' => false,
                'is_override' => false,
            ],
            [
                'name' => 'equals',
                'return_type' => 'bool',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'other', 'type' => 'const QNodeId &', 'has_default' => false],
                ],
                'is_static' => false,
                'is_const' => true,
                'is_virtual' => false,
                'is_pure_virtual' => false,
                'is_override' => false,
            ],
        ],
        'signals' => [],
    ], $resolver);

    expect($class->properties[0]->cppType)->toBe('const Qt3DCore::QNodeId *')
        ->and($class->properties[0]->phpType)->toBe('\\Qt\\Qt3DCore\\QNodeId')
        ->and($class->methods[0]->returnType)->toBe('\\Qt\\Qt3DCore\\QNodeId')
        ->and($class->methods[0]->overloads[0]->returnType)->toBe('Qt3DCore::QNodeId')
        ->and($class->methods[1]->parameters[0]->phpType)->toBe('\\Qt\\Qt3DCore\\QNodeId')
        ->and($class->methods[1]->overloads[0]->parameters[0]->cppType)->toBe('const Qt3DCore::QNodeId &');
});

it('canonicalizes foreign nested enum owners for namespaced classes', function (): void {
    $builder = new ClassDefinitionBuilder();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QTextureData', 'qualified_name' => 'Qt3DRender::QTextureData', 'module' => 'Qt3DRender'],
        ['name' => 'QAbstractTexture', 'qualified_name' => 'Qt3DRender::QAbstractTexture', 'module' => 'Qt3DRender'],
        ['name' => 'QTextureWrapMode', 'qualified_name' => 'Qt3DRender::QTextureWrapMode', 'module' => 'Qt3DRender'],
    ]);

    $class = $builder->build([
        'name' => 'QTextureData',
        'qualified_name' => 'Qt3DRender::QTextureData',
        'is_abstract' => false,
        'is_struct' => false,
        'bases' => [],
        'properties' => [
            ['name' => 'target', 'type' => 'QAbstractTexture::Target', 'access' => 'public', 'is_static' => false],
        ],
        'methods' => [
            [
                'name' => 'target',
                'return_type' => 'QAbstractTexture::Target',
                'access' => 'public',
                'parameters' => [],
                'is_static' => false,
                'is_const' => true,
                'is_virtual' => false,
                'is_pure_virtual' => false,
                'is_override' => false,
            ],
            [
                'name' => 'setWrapModeX',
                'return_type' => 'void',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'mode', 'type' => 'const QTextureWrapMode::WrapMode &', 'has_default' => false],
                ],
                'is_static' => false,
                'is_const' => false,
                'is_virtual' => false,
                'is_pure_virtual' => false,
                'is_override' => false,
            ],
        ],
        'signals' => [],
    ], $resolver);

    expect($class->properties[0]->cppType)->toBe('Qt3DRender::QAbstractTexture::Target')
        ->and($class->properties[0]->phpType)->toBe('int')
        ->and($class->methods[0]->returnType)->toBe('int')
        ->and($class->methods[0]->overloads[0]->returnType)->toBe('Qt3DRender::QAbstractTexture::Target')
        ->and($class->methods[1]->parameters[0]->phpType)->toBe('int')
        ->and($class->methods[1]->overloads[0]->parameters[0]->cppType)->toBe('const Qt3DRender::QTextureWrapMode::WrapMode &');
});

it('adds php string unions for qstring-like parameters when class types are available', function (): void {
    $builder = new ClassDefinitionBuilder();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QString', 'qualified_name' => 'QString', 'module' => 'QtCore'],
        ['name' => 'QLabelLike', 'qualified_name' => 'QLabelLike', 'module' => 'QtWidgets'],
    ]);

    $class = $builder->build([
        'name' => 'QLabelLike',
        'is_abstract' => false,
        'is_struct' => false,
        'bases' => [],
        'properties' => [],
        'methods' => [
            [
                'name' => 'setText',
                'return_type' => 'void',
                'access' => 'public',
                'parameters' => [
                    ['name' => 'text', 'type' => 'const QString &', 'has_default' => false],
                ],
                'is_static' => false,
                'is_const' => false,
                'is_virtual' => false,
                'is_pure_virtual' => false,
                'is_override' => false,
            ],
        ],
        'signals' => [],
    ], $resolver);

    expect($class->methods)->toHaveCount(1)
        ->and($class->methods[0]->parameters)->toHaveCount(1)
        ->and($class->methods[0]->parameters[0]->phpType)->toBe('QString|string');
});
