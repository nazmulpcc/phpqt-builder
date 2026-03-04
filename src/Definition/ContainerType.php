<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

readonly class ContainerType
{
    public function __construct(
        public string $rawType,
        public string $kind,
        public string $containerName,
        public ?string $elementType = null,
        public ?string $keyType = null,
        public ?string $valueType = null,
    ) {}

    public function isSequence(): bool
    {
        return $this->kind === 'sequence';
    }

    public function isMapLike(): bool
    {
        return $this->kind === 'map' || $this->kind === 'hash';
    }
}
