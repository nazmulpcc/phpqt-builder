<?php

declare(strict_types=1);

namespace QtBuilder\Containers;

readonly class QListSpecialization
{
    /**
     * @param list<string> $nativeIncludes
     */
    public function __construct(
        public string $className,
        public string $rawType,
        public string $elementCppType,
        public string $elementPhpType,
        public array $nativeIncludes,
        public ?string $nativeAliasOf = null,
    ) {}
}
