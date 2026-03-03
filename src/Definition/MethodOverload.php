<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

/**
 * Represents a single C++ method overload variant.
 *
 * A PHP method with overloaded C++ counterparts will hold one of these
 * for each original C++ signature. The code generator uses these to
 * emit dispatch logic (arg-count / type checks).
 */
readonly class MethodOverload
{
    /**
     * @param list<OverloadParameter> $parameters
     */
    public function __construct(
        public string $declaringClass,
        public string $returnType,
        public array $parameters,
        public bool $isConst,
        public bool $isStatic,
        public bool $isVirtual,
        public bool $isPureVirtual,
    ) {}

    public function parameterCount(): int
    {
        return \count($this->parameters);
    }

    public function requiredParameterCount(): int
    {
        $count = 0;

        foreach ($this->parameters as $param) {
            if ($param->hasDefault) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @return array{declaring_class: string, return_type: string, parameters: list<array<string, mixed>>, is_const: bool, is_static: bool, is_virtual: bool, is_pure_virtual: bool}
     */
    public function toArray(): array
    {
        return [
            'declaring_class' => $this->declaringClass,
            'return_type' => $this->returnType,
            'parameters' => array_map(
                static fn(OverloadParameter $p): array => $p->toArray(),
                $this->parameters,
            ),
            'is_const' => $this->isConst,
            'is_static' => $this->isStatic,
            'is_virtual' => $this->isVirtual,
            'is_pure_virtual' => $this->isPureVirtual,
        ];
    }
}
