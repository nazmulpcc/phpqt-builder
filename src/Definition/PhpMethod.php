<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

/**
 * A method in the PHP class definition.
 *
 * Holds the merged PHP signature (after overload resolution) plus
 * all original C++ overload variants for the code generator.
 */
readonly class PhpMethod
{
    /**
     * @param list<PhpParameter> $parameters  Merged PHP parameter list.
     * @param list<MethodOverload> $overloads  Original C++ overload variants.
     */
    public function __construct(
        public string $name,
        public string $access,
        public bool $isStatic,
        public bool $isSignal,
        public bool $isSlot,
        public string $returnType,
        public array $parameters,
        public array $overloads,
    ) {}

    public function isOverloaded(): bool
    {
        return \count($this->overloads) > 1;
    }

    public function overloadCount(): int
    {
        return \count($this->overloads);
    }

    /**
     * @return array{name: string, access: string, is_static: bool, is_signal: bool, is_slot: bool, return_type: string, parameters: list<array<string, mixed>>, overloads: list<array<string, mixed>>, overload_count: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'access' => $this->access,
            'is_static' => $this->isStatic,
            'is_signal' => $this->isSignal,
            'is_slot' => $this->isSlot,
            'return_type' => $this->returnType,
            'parameters' => array_map(
                static fn(PhpParameter $p): array => $p->toArray(),
                $this->parameters,
            ),
            'overloads' => array_map(
                static fn(MethodOverload $o): array => $o->toArray(),
                $this->overloads,
            ),
            'overload_count' => $this->overloadCount(),
        ];
    }
}
