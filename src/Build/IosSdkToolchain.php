<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class IosSdkToolchain
{
    /**
     * @param list<string> $architectures
     */
    public function __construct(
        public string $sdk,
        public string $sdkPath,
        public string $clangxxPath,
        public string $libtoolPath,
        public array $architectures,
        public string $minimumVersion,
    ) {}
}
