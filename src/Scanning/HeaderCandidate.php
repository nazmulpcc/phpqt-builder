<?php

declare(strict_types=1);

namespace QtBuilder\Scanning;

readonly class HeaderCandidate
{
    public function __construct(
        public string $module,
        public string $className,
        public string $publicHeader,
        public string $parseHeader,
    ) {}
}
