<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class IosToolchain
{
    /**
     * @param array<string, IosSdkToolchain> $sdks
     */
    public function __construct(
        public string $developerDir,
        public array $sdks,
    ) {}
}
