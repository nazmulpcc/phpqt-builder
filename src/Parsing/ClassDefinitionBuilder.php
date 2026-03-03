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
     * @param array{name: string, is_abstract: bool, is_copy_constructible?: bool, has_public_destructor?: bool, is_struct: bool, bases: list<string>, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>, signals?: list<array<string, mixed>>} $classData
     */
    public function build(array $classData): PhpClass
    {
        $className = $classData['name'];
        $properties = $this->buildProperties($classData['properties']);
        $methods = $this->buildMethods($classData['methods'], $className);
        $signals = $this->buildMethods($classData['signals'] ?? [], $className);

        // Use the first base class as the PHP parent (single inheritance).
        $parent = $classData['bases'][0] ?? null;

        return new PhpClass(
            name: $classData['name'],
            parent: $parent,
            isAbstract: $classData['is_abstract'],
            isCopyConstructible: (bool) ($classData['is_copy_constructible'] ?? true),
            hasPublicDestructor: (bool) ($classData['has_public_destructor'] ?? true),
            properties: $properties,
            methods: $methods,
            signals: $signals,
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
     * @param list<array{name: string, return_type: string, access: string, parameters: list<array{name: string, type: string, has_default: bool}>, is_static: bool, is_const: bool, is_virtual: bool, is_pure_virtual: bool, is_override: bool, is_signal?: bool, is_slot?: bool}> $methods
     * @return list<PhpMethod>
     */
    private function buildMethods(array $methods, string $className): array
    {
        // Filter private methods.
        $methods = array_filter($methods, static fn(array $m): bool => $m['access'] !== 'private');

        // Filter out C++ operator overloads — these aren't valid PHP method names.
        // Operators like operator+=, operator==, etc. need PHP-specific alternatives
        // (e.g. __add, __equals) which can be added via template overrides later.
        $methods = array_filter(
            $methods,
            static fn(array $m): bool => !str_starts_with($m['name'], 'operator'),
        );

        // Filter out C++ destructors (e.g. ~QPoint) — not needed in PHP.
        $methods = array_filter(
            $methods,
            static fn(array $m): bool => !str_starts_with($m['name'], '~'),
        );

        // Rename constructors: C++ constructors have the class name (e.g. "QPoint"),
        // PHP uses __construct.
        $methods = array_map(
            static function (array $m) use ($className): array {
                if ($m['name'] === $className) {
                    $m['name'] = '__construct';
                    $m['return_type'] = 'void'; // constructors have no return type
                }
                return $m;
            },
            $methods,
        );

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
            isSignal: $this->allFlagged($variants, 'is_signal'),
            isSlot: $this->allFlagged($variants, 'is_slot'),
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
        $parameters = $this->normalizeWritableParameterDefaults($variant['parameters']);
        $params = array_map(
            function (array $p): OverloadParameter {
                $metadata = $this->analyzeCppParameterType((string) ($p['type'] ?? ''));

                return new OverloadParameter(
                    name: (string) ($p['name'] ?? ''),
                    cppType: (string) ($p['type'] ?? ''),
                    hasDefault: (bool) ($p['has_default'] ?? false),
                    isReference: $metadata['is_reference'],
                    isConstReference: $metadata['is_const_reference'],
                    isNonConstReference: $metadata['is_non_const_reference'],
                    pointerDepth: $metadata['pointer_depth'],
                );
            },
            $parameters,
        );

        return new MethodOverload(
            declaringClass: (string) ($variant['declaring_class'] ?? ''),
            returnType: $variant['return_type'],
            parameters: $params,
            isConst: $variant['is_const'],
            isStatic: $variant['is_static'],
            isVirtual: $variant['is_virtual'],
            isPureVirtual: $variant['is_pure_virtual'],
        );
    }

    /**
     * @param list<array<string, mixed>> $variants
     */
    private function allFlagged(array $variants, string $key): bool
    {
        if ($variants === []) {
            return false;
        }

        foreach ($variants as $variant) {
            if (($variant[$key] ?? false) !== true) {
                return false;
            }
        }

        return true;
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
            $count = \count($this->normalizeWritableParameterDefaults($v['parameters']));
            $maxParams = max($maxParams, $count);
            $minParams = min($minParams, $count);
        }

        if ($maxParams === 0) {
            return [];
        }

        $parameters = [];
        $usedNames = [];

        for ($i = 0; $i < $maxParams; $i++) {
            $phpTypes = [];
            $names = [];
            $allHaveDefault = true;
            $someVariantsShorter = false;

            foreach ($variants as $v) {
                $params = $this->normalizeWritableParameterDefaults($v['parameters']);

                if ($i >= \count($params)) {
                    $someVariantsShorter = true;
                    continue;
                }

                $phpTypes[] = $this->typeMapper->map($params[$i]['type']);

                $pName = $params[$i]['name'];
                if ($pName !== '' && !\in_array($pName, $names, true)) {
                    $names[] = $pName;
                }

                if (!$params[$i]['has_default']) {
                    $allHaveDefault = false;
                }
            }

            $hasDefault = $someVariantsShorter || $allHaveDefault;

            // Pick a unique name. Try names from the variants first, then fallback.
            $name = $this->pickUniqueName($names, $i, $usedNames);
            $usedNames[$name] = true;

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
     * @param list<array{name: string, type: string, has_default: bool}> $parameters
     * @return list<array{name: string, type: string, has_default: bool}>
     */
    private function normalizeWritableParameterDefaults(array $parameters): array
    {
        $normalized = array_values($parameters);
        $count = \count($normalized);

        for ($i = 0; $i < $count - 1; $i++) {
            $currentType = (string) ($normalized[$i]['type'] ?? '');
            $nextType = (string) ($normalized[$i + 1]['type'] ?? '');

            if (!$this->isWritableArgcType($currentType) || !$this->isCharPointerArrayType($nextType)) {
                continue;
            }

            $normalized[$i]['has_default'] = true;
            $normalized[$i + 1]['has_default'] = true;
        }

        return $normalized;
    }

    /**
     * @return array{is_reference: bool, is_const_reference: bool, is_non_const_reference: bool, pointer_depth: int}
     */
    private function analyzeCppParameterType(string $cppType): array
    {
        $normalized = trim($cppType);
        $isReference = str_contains($normalized, '&');
        $isConstReference = $isReference && preg_match('/^\s*const\b/', $normalized) === 1;

        return [
            'is_reference' => $isReference,
            'is_const_reference' => $isConstReference,
            'is_non_const_reference' => $isReference && !$isConstReference,
            'pointer_depth' => substr_count($normalized, '*'),
        ];
    }

    private function isWritableArgcType(string $cppType): bool
    {
        return preg_match('/^\s*int\s*&\s*$/', trim($cppType)) === 1;
    }

    private function isCharPointerArrayType(string $cppType): bool
    {
        $normalized = preg_replace('/\bconst\b/', '', $cppType) ?? $cppType;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        return preg_match('/^char\s*\*\s*\*$/', $normalized) === 1;
    }

    /**
     * Pick a unique parameter name that hasn't been used yet.
     *
     * @param list<string> $candidates  Names from variant parameters at this position
     * @param int $position             Parameter position (for fallback)
     * @param array<string, true> $usedNames  Already-used names
     */
    private function pickUniqueName(array $candidates, int $position, array $usedNames): string
    {
        // Try each candidate name from variants
        foreach ($candidates as $name) {
            if (!isset($usedNames[$name])) {
                return $name;
            }
        }

        // All candidate names are taken. Try suffixing the first candidate.
        if ($candidates !== []) {
            $base = $candidates[0];
            for ($suffix = 2; $suffix <= 20; $suffix++) {
                $try = $base . $suffix;
                if (!isset($usedNames[$try])) {
                    return $try;
                }
            }
        }

        // Fallback: positional name
        $fallback = 'p' . $position;
        if (!isset($usedNames[$fallback])) {
            return $fallback;
        }

        // Last resort
        return 'arg' . $position;
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
