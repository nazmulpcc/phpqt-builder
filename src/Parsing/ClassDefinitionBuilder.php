<?php

declare(strict_types=1);

namespace QtBuilder\Parsing;

use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Definition\PhpProperty;

/**
 * Transforms the raw inspector data (arrays from QtClassInspector) into
 * a {@see PhpClass} definition.
 *
 * Handles:
 * - Filtering out private members
 * - Mapping C++ types to PHP types via {@see CppToPhpTypeMapper}
 * - Merging C++ method overloads into a single PHP method signature
 */
class ClassDefinitionBuilder
{
    public function __construct(
        private readonly CppToPhpTypeMapper $typeMapper = new CppToPhpTypeMapper(),
    ) {}

    /**
     * Build a PhpClass from the array produced by QtClassInspector::inspect().
     *
     * @param array{name: string, is_abstract: bool, is_struct: bool, bases: list<string>, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>} $classData
     */
    public function build(array $classData): PhpClass
    {
        $properties = $this->buildProperties($classData['properties']);
        $methods = $this->buildMethods($classData['methods']);

        // Use the first base class as the PHP parent (single inheritance).
        $parent = $classData['bases'][0] ?? null;

        return new PhpClass(
            name: $classData['name'],
            parent: $parent,
            isAbstract: $classData['is_abstract'],
            properties: $properties,
            methods: $methods,
        );
    }

    // ------------------------------------------------------------------
    // Properties
    // ------------------------------------------------------------------

    /**
     * @param list<array{name: string, type: string, access: string, is_static: bool}> $fields
     * @return list<PhpProperty>
     */
    private function buildProperties(array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            if ($field['access'] === 'private') {
                continue;
            }

            $properties[] = new PhpProperty(
                name: $field['name'],
                phpType: $this->typeMapper->map($field['type']),
                cppType: $field['type'],
                access: $field['access'],
                isStatic: $field['is_static'],
            );
        }

        return $properties;
    }

    // ------------------------------------------------------------------
    // Methods
    // ------------------------------------------------------------------

    /**
     * @param list<array{name: string, return_type: string, access: string, parameters: list<array{name: string, type: string, has_default: bool}>, is_static: bool, is_const: bool, is_virtual: bool, is_pure_virtual: bool, is_override: bool}> $methods
     * @return list<PhpMethod>
     */
    private function buildMethods(array $methods): array
    {
        // Filter private methods.
        $methods = array_filter($methods, static fn(array $m): bool => $m['access'] !== 'private');

        // Group by method name.
        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [];
        foreach ($methods as $method) {
            $grouped[$method['name']][] = $method;
        }

        $result = [];

        foreach ($grouped as $name => $variants) {
            $result[] = $this->mergeOverloads($name, $variants);
        }

        return $result;
    }

    /**
     * Merge one or more C++ method variants into a single PhpMethod.
     *
     * @param list<array<string, mixed>> $variants
     */
    private function mergeOverloads(string $name, array $variants): PhpMethod
    {
        // Build the MethodOverload list from all variants.
        $overloads = array_map($this->buildOverload(...), $variants);

        // Determine access: use the most permissive (public > protected).
        $access = $this->mostPermissiveAccess($variants);

        // Determine if all variants are static.
        $isStatic = $this->allStatic($variants);

        // Build the merged PHP return type.
        $returnType = $this->mergeReturnTypes($variants);

        // Build the merged PHP parameter list.
        $parameters = $this->mergeParameters($variants);

        return new PhpMethod(
            name: $name,
            access: $access,
            isStatic: $isStatic,
            returnType: $returnType,
            parameters: $parameters,
            overloads: $overloads,
        );
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function buildOverload(array $variant): MethodOverload
    {
        $params = array_map(
            static fn(array $p): OverloadParameter => new OverloadParameter(
                name: $p['name'],
                cppType: $p['type'],
                hasDefault: $p['has_default'],
            ),
            $variant['parameters'],
        );

        return new MethodOverload(
            returnType: $variant['return_type'],
            parameters: $params,
            isConst: $variant['is_const'],
            isStatic: $variant['is_static'],
            isVirtual: $variant['is_virtual'],
            isPureVirtual: $variant['is_pure_virtual'],
        );
    }

    // ------------------------------------------------------------------
    // Overload merging helpers
    // ------------------------------------------------------------------

    /**
     * Compute the merged PHP return type across all variants.
     *
     * If all variants have the same PHP return type, use it directly.
     * Otherwise produce a union type (e.g. "QPointF|QPoint").
     * A "void" mixed with non-void becomes the non-void type(s) + "null".
     *
     * @param list<array<string, mixed>> $variants
     */
    private function mergeReturnTypes(array $variants): string
    {
        $phpTypes = [];

        foreach ($variants as $v) {
            $phpTypes[] = $this->typeMapper->map($v['return_type']);
        }

        return $this->unionType($phpTypes);
    }

    /**
     * Compute the merged PHP parameter list across all overload variants.
     *
     * Strategy:
     * 1. The merged list has as many positions as the longest variant.
     * 2. For each position, collect the PHP types from all variants that
     *    have a parameter at that position and produce a union type.
     * 3. A position is optional (hasDefault = true) if:
     *    - Any variant is shorter than this position (some callers won't
     *      pass it), OR
     *    - All variants that have this position mark it as having a default.
     * 4. Parameter name is taken from the first variant that has a non-empty
     *    name at that position, falling back to "p{position}".
     *
     * @param list<array<string, mixed>> $variants
     * @return list<PhpParameter>
     */
    private function mergeParameters(array $variants): array
    {
        $maxParams = 0;
        $minParams = PHP_INT_MAX;

        foreach ($variants as $v) {
            $count = \count($v['parameters']);
            $maxParams = max($maxParams, $count);
            $minParams = min($minParams, $count);
        }

        if ($maxParams === 0) {
            return [];
        }

        $parameters = [];

        for ($i = 0; $i < $maxParams; $i++) {
            $phpTypes = [];
            $names = [];
            $allHaveDefault = true;
            $someVariantsShorter = false;

            foreach ($variants as $v) {
                $params = $v['parameters'];

                if ($i >= \count($params)) {
                    $someVariantsShorter = true;
                    continue;
                }

                $phpTypes[] = $this->typeMapper->map($params[$i]['type']);

                $pName = $params[$i]['name'];
                if ($pName !== '') {
                    $names[] = $pName;
                }

                if (!$params[$i]['has_default']) {
                    $allHaveDefault = false;
                }
            }

            $hasDefault = $someVariantsShorter || $allHaveDefault;
            $name = $names[0] ?? 'p' . $i;

            $parameters[] = new PhpParameter(
                name: $name,
                phpType: $this->unionType($phpTypes),
                hasDefault: $hasDefault,
                position: $i,
            );
        }

        return $parameters;
    }

    /**
     * Deduplicate a list of PHP type strings and produce a union type.
     *
     * @param list<string> $types
     */
    private function unionType(array $types): string
    {
        $unique = array_values(array_unique($types));

        if ($unique === []) {
            return 'mixed';
        }

        if (\count($unique) === 1) {
            return $unique[0];
        }

        // Sort for deterministic output: scalars first, then objects.
        sort($unique);

        return implode('|', $unique);
    }

    /**
     * @param list<array<string, mixed>> $variants
     */
    private function mostPermissiveAccess(array $variants): string
    {
        foreach ($variants as $v) {
            if ($v['access'] === 'public') {
                return 'public';
            }
        }

        return 'protected';
    }

    /**
     * @param list<array<string, mixed>> $variants
     */
    private function allStatic(array $variants): bool
    {
        foreach ($variants as $v) {
            if (!$v['is_static']) {
                return false;
            }
        }

        return true;
    }
}
