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
        public bool $isAbstractMethod,
        public string $returnType,
        public array $parameters,
        public array $overloads,
        public ?string $cppName = null,
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
     * @return array{name: string, cpp_name: string, access: string, is_static: bool, is_signal: bool, is_slot: bool, is_abstract_method: bool, return_type: string, parameters: list<array<string, mixed>>, overloads: list<array<string, mixed>>, overload_count: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'cpp_name' => $this->cppName ?? $this->name,
            'access' => $this->access,
            'is_static' => $this->isStatic,
            'is_signal' => $this->isSignal,
            'is_slot' => $this->isSlot,
            'is_abstract_method' => $this->isAbstractMethod,
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

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $parameters = [];
        foreach (($payload['parameters'] ?? []) as $parameterPayload) {
            if (!is_array($parameterPayload)) {
                continue;
            }

            $parameters[] = PhpParameter::fromArray($parameterPayload);
        }

        $overloads = [];
        foreach (($payload['overloads'] ?? []) as $overloadPayload) {
            if (!is_array($overloadPayload)) {
                continue;
            }

            $overloads[] = MethodOverload::fromArray($overloadPayload);
        }

        return new self(
            name: is_string($payload['name'] ?? null) ? $payload['name'] : '',
            access: is_string($payload['access'] ?? null) ? $payload['access'] : 'public',
            isStatic: (bool) ($payload['is_static'] ?? false),
            isSignal: (bool) ($payload['is_signal'] ?? false),
            isSlot: (bool) ($payload['is_slot'] ?? false),
            isAbstractMethod: (bool) ($payload['is_abstract_method'] ?? false),
            returnType: is_string($payload['return_type'] ?? null) ? $payload['return_type'] : 'void',
            parameters: $parameters,
            overloads: $overloads,
            cppName: is_string($payload['cpp_name'] ?? null) ? $payload['cpp_name'] : null,
        );
    }
}
