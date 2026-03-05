<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

readonly class PhpClassConstant
{
    public function __construct(
        public string $name,
        public int|float|string $value,
        public string $enumName = '',
    ) {}

    /**
     * @return array{name: string, value: int|float|string, enum_name: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'enum_name' => $this->enumName,
        ];
    }
}
