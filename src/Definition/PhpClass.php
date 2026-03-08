<?php

declare(strict_types=1);

namespace QtBuilder\Definition;

use QtBuilder\Support\GeneratedTypeIdentity;

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
     * @param list<PhpMethod> $signals
     * @param list<PhpClassConstant> $classConstants
     * @param list<string> $nativeIncludes
     * @param array<string, string> $smartPointerAliases
     */
    public function __construct(
        public string $name,
        public ?string $parent,
        public bool $isAbstract,
        public bool $isCopyConstructible,
        public bool $hasPublicConstructor,
        public bool $hasPublicDestructor,
        public array $properties,
        public array $methods,
        public array $signals,
        public bool $isQObjectDerived = false,
        public array $classConstants = [],
        public array $nativeIncludes = [],
        public ?string $nativeAliasOf = null,
        public ?string $nativeCppType = null,
        public ?string $generationId = null,
        public array $smartPointerAliases = [],
    ) {}

    public function resolvedGenerationId(): string
    {
        if (is_string($this->generationId) && $this->generationId !== '') {
            return $this->generationId;
        }

        return GeneratedTypeIdentity::fromNames($this->name, $this->nativeCppType)->generationId;
    }

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
     * @return array{name: string, parent: ?string, is_abstract: bool, is_copy_constructible: bool, has_public_constructor: bool, has_public_destructor: bool, is_qobject_derived: bool, native_includes: list<string>, native_alias_of: ?string, native_cpp_type: ?string, generation_id: string, smart_pointer_aliases: array<string, string>, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>, signals: list<array<string, mixed>>, class_constants: list<array<string, mixed>>, summary: array{total_methods: int, public_methods: int, protected_methods: int, overloaded_methods: int, total_signals: int, total_properties: int, total_class_constants: int}}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'parent' => $this->parent,
            'is_abstract' => $this->isAbstract,
            'is_copy_constructible' => $this->isCopyConstructible,
            'has_public_constructor' => $this->hasPublicConstructor,
            'has_public_destructor' => $this->hasPublicDestructor,
            'is_qobject_derived' => $this->isQObjectDerived,
            'native_includes' => $this->nativeIncludes,
            'native_alias_of' => $this->nativeAliasOf,
            'native_cpp_type' => $this->nativeCppType,
            'generation_id' => $this->resolvedGenerationId(),
            'smart_pointer_aliases' => $this->smartPointerAliases,
            'properties' => array_map(
                static fn(PhpProperty $p): array => $p->toArray(),
                $this->properties,
            ),
            'methods' => array_map(
                static fn(PhpMethod $m): array => $m->toArray(),
                $this->methods,
            ),
            'signals' => array_map(
                static fn(PhpMethod $m): array => $m->toArray(),
                $this->signals,
            ),
            'class_constants' => array_map(
                static fn(PhpClassConstant $constant): array => $constant->toArray(),
                $this->classConstants,
            ),
            'summary' => [
                'total_methods' => \count($this->methods),
                'public_methods' => \count($this->publicMethods()),
                'protected_methods' => \count($this->protectedMethods()),
                'overloaded_methods' => \count($this->overloadedMethods()),
                'total_signals' => \count($this->signals),
                'total_properties' => \count($this->properties),
                'total_class_constants' => \count($this->classConstants),
            ],
        ];
    }
}
