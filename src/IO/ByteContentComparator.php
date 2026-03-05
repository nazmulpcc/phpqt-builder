<?php

declare(strict_types=1);

namespace QtBuilder\IO;

final class ByteContentComparator implements ContentComparator
{
    public function equals(string $existing, string $generated): bool
    {
        return $existing === $generated;
    }

    public function name(): string
    {
        return 'byte';
    }
}

