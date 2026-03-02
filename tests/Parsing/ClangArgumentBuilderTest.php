<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Parsing;

use PHPUnit\Framework\TestCase;
use QtBuilder\Parsing\ClangArgumentBuilder;

final class ClangArgumentBuilderTest extends TestCase
{
    public function testBuildIncludesQtFeatureOverrideHeader(): void
    {
        $builder = new ClangArgumentBuilder();
        $args = $builder->build();

        $overrideHeader = realpath(dirname(__DIR__, 2) . '/templates/clang/qt_feature_overrides.h');
        self::assertNotFalse($overrideHeader);
        self::assertContains('-include', $args);
        self::assertContains($overrideHeader, $args);
    }
}
