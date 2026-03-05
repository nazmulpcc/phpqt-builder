<?php

declare(strict_types=1);

namespace QtBuilder\IO;

interface ContentComparator
{
    public function equals(string $existing, string $generated): bool;

    public function name(): string;
}

