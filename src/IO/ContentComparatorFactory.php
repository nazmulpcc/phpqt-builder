<?php

declare(strict_types=1);

namespace QtBuilder\IO;

final class ContentComparatorFactory
{
    public static function createDefault(): ContentComparator
    {
        return new ByteContentComparator();
    }
}

