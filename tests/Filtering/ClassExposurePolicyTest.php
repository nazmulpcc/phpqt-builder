<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Filtering;

use PHPUnit\Framework\TestCase;
use QtBuilder\Filtering\ClassExposurePolicy;

final class ClassExposurePolicyTest extends TestCase
{
    public function testViewClassesAreNoLongerFilteredBySuffix(): void
    {
        $policy = new ClassExposurePolicy();

        $decision = $policy->decideClassName('QAbstractItemView');

        self::assertTrue($decision->accepted);
        self::assertNull($decision->reasonCode);
    }

    public function testIteratorClassesRemainFilteredBySuffix(): void
    {
        $policy = new ClassExposurePolicy();

        $decision = $policy->decideClassName('QJSValueIterator');

        self::assertFalse($decision->accepted);
        self::assertSame('class_filtered', $decision->reasonCode);
    }
}
