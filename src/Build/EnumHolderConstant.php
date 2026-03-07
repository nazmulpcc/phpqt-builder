<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class EnumHolderConstant
{
    public function __construct(
        public string $name,
        public int|float|string $value,
    ) {}

    /**
     * @return array{name: string, value: int|float|string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
        ];
    }
}
