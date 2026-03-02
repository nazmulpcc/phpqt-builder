<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

/**
 * The top-level PHP class definition produced by the ClassDefinitionBuilder.
 *
 * This is the Intermediate Representation (IR) that sits between the raw
 * C++ AST data and the code generator. It describes what the PHP user will
 * see: the class name, parent, properties, and methods with their merged
 * signatures and underlying C++ overloads.
 */
readonly class PhpClass
{
    /**
     * @param list<PhpProperty> $properties
     * @param list<PhpMethod> $methods
     */
    public function __construct(
        public string $name,
        public ?string $parent,
        public bool $isAbstract,
        public bool $isCopyConstructible,
        public array $properties,
        public array $methods,
    ) {}

    /**
     * @return list<PhpMethod>
     */
    public function publicMethods(): array
    {
        return array_values(
            array_filter($this->methods, static fn(PhpMethod $m): bool => $m->access === 'public'),
        );
    }

    /**
     * @return list<PhpMethod>
     */
    public function protectedMethods(): array
    {
        return array_values(
            array_filter($this->methods, static fn(PhpMethod $m): bool => $m->access === 'protected'),
        );
    }

    /**
     * @return list<PhpMethod>
     */
    public function overloadedMethods(): array
    {
        return array_values(
            array_filter($this->methods, static fn(PhpMethod $m): bool => $m->isOverloaded()),
        );
    }

    /**
     * @return array{name: string, parent: ?string, is_abstract: bool, is_copy_constructible: bool, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>, summary: array{total_methods: int, public_methods: int, protected_methods: int, overloaded_methods: int, total_properties: int}}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'parent' => $this->parent,
            'is_abstract' => $this->isAbstract,
            'is_copy_constructible' => $this->isCopyConstructible,
            'properties' => array_map(
                static fn(PhpProperty $p): array => $p->toArray(),
                $this->properties,
            ),
            'methods' => array_map(
                static fn(PhpMethod $m): array => $m->toArray(),
                $this->methods,
            ),
            'summary' => [
                'total_methods' => \count($this->methods),
                'public_methods' => \count($this->publicMethods()),
                'protected_methods' => \count($this->protectedMethods()),
                'overloaded_methods' => \count($this->overloadedMethods()),
                'total_properties' => \count($this->properties),
            ],
        ];
    }
}
