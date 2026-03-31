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

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $value = $payload['value'] ?? null;
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            $value = '';
        }

        return new self(
            name: is_string($payload['name'] ?? null) ? $payload['name'] : '',
            value: $value,
            enumName: is_string($payload['enum_name'] ?? null) ? $payload['enum_name'] : '',
        );
    }
}
