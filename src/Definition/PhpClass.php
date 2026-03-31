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

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $properties = [];
        foreach (($payload['properties'] ?? []) as $propertyPayload) {
            if (!is_array($propertyPayload)) {
                continue;
            }

            $properties[] = PhpProperty::fromArray($propertyPayload);
        }

        $methods = [];
        foreach (($payload['methods'] ?? []) as $methodPayload) {
            if (!is_array($methodPayload)) {
                continue;
            }

            $methods[] = PhpMethod::fromArray($methodPayload);
        }

        $signals = [];
        foreach (($payload['signals'] ?? []) as $signalPayload) {
            if (!is_array($signalPayload)) {
                continue;
            }

            $signals[] = PhpMethod::fromArray($signalPayload);
        }

        $classConstants = [];
        foreach (($payload['class_constants'] ?? []) as $constantPayload) {
            if (!is_array($constantPayload)) {
                continue;
            }

            $classConstants[] = PhpClassConstant::fromArray($constantPayload);
        }

        $nativeIncludes = array_values(array_filter(
            array_map(static fn(mixed $value): string => is_string($value) ? $value : '', $payload['native_includes'] ?? []),
            static fn(string $value): bool => $value !== '',
        ));

        $smartPointerAliases = [];
        foreach (($payload['smart_pointer_aliases'] ?? []) as $alias => $target) {
            if (!is_string($alias) || !is_string($target) || $alias === '' || $target === '') {
                continue;
            }

            $smartPointerAliases[$alias] = $target;
        }

        return new self(
            name: is_string($payload['name'] ?? null) ? $payload['name'] : '',
            parent: is_string($payload['parent'] ?? null) ? $payload['parent'] : null,
            isAbstract: (bool) ($payload['is_abstract'] ?? false),
            isCopyConstructible: (bool) ($payload['is_copy_constructible'] ?? true),
            hasPublicConstructor: (bool) ($payload['has_public_constructor'] ?? true),
            hasPublicDestructor: (bool) ($payload['has_public_destructor'] ?? true),
            properties: $properties,
            methods: $methods,
            signals: $signals,
            isQObjectDerived: (bool) ($payload['is_qobject_derived'] ?? false),
            classConstants: $classConstants,
            nativeIncludes: $nativeIncludes,
            nativeAliasOf: is_string($payload['native_alias_of'] ?? null) ? $payload['native_alias_of'] : null,
            nativeCppType: is_string($payload['native_cpp_type'] ?? null) ? $payload['native_cpp_type'] : null,
            generationId: is_string($payload['generation_id'] ?? null) ? $payload['generation_id'] : null,
            smartPointerAliases: $smartPointerAliases,
        );
    }
}
