<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Parsing;

use PHPUnit\Framework\TestCase;
use QtBuilder\Parsing\ClassDefinitionBuilder;

final class ClassDefinitionBuilderTest extends TestCase
{
    public function testMergeParametersUsesMinimumRequiredArgumentCountAcrossOverloads(): void
    {
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

        self::assertCount(1, $class->methods);

        $constructor = $class->methods[0];
        self::assertSame('__construct', $constructor->name);
        self::assertCount(2, $constructor->parameters);
        self::assertTrue($constructor->parameters[0]->hasDefault);
        self::assertTrue($constructor->parameters[1]->hasDefault);
        self::assertSame('QWidget|string', $constructor->parameters[0]->phpType);
        self::assertSame('QWidget', $constructor->parameters[1]->phpType);
    }
}
