<?php

declare(strict_types=1);

use QtBuilder\Parsing\ClassDefinitionBuilder;

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
