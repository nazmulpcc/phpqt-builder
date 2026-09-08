<?php

declare(strict_types=1);

namespace QtBuilder\Parsing;

use QtBuilder\Definition\MethodOverload;
use QtBuilder\Definition\OverloadParameter;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpClassConstant;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Definition\PhpProperty;
use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\GeneratedTypeIdentity;
use QtBuilder\Support\SameClassReferenceResolver;
use QtBuilder\Support\TypeResolutionContext;

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
     * @param array{name: string, qualified_name?: string, is_abstract: bool, is_copy_constructible?: bool, has_public_constructor?: bool, has_public_destructor?: bool, is_qobject_derived?: bool, is_struct: bool, bases: list<string>, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>, signals?: list<array<string, mixed>>, enum_constants?: list<array<string, mixed>>} $classData
     */
    public function build(array $classData, ?CppClassTypeResolver $classTypeResolver = null): PhpClass
    {
        $className = $classData['name'];
        $qualifiedName = is_string($classData['qualified_name'] ?? null)
            ? trim((string) $classData['qualified_name'])
            : '';
        $resolutionContext = TypeResolutionContext::fromClassData($classData);
        $smartPointerAliases = $this->canonicalizeSmartPointerAliases(
            is_array($classData['smart_pointer_aliases'] ?? null) ? $classData['smart_pointer_aliases'] : [],
            $classTypeResolver,
            $resolutionContext,
        );
        $properties = $this->buildProperties($classData['properties'], $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);
        $methods = $this->buildMethods($classData['methods'], $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);
        $signals = $this->buildMethods($classData['signals'] ?? [], $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);
        $classConstants = $this->buildClassConstants($classData['enum_constants'] ?? []);

        // Use the first base class as the PHP parent (single inheritance).
        $parentCppType = is_string($classData['bases'][0] ?? null) ? (string) $classData['bases'][0] : null;
        $parent = $parentCppType !== null
            ? $this->typeMapper->map($parentCppType, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases)
            : null;

        return new PhpClass(
            name: $classData['name'],
            parent: $parent,
            isAbstract: $classData['is_abstract'],
            isCopyConstructible: (bool) ($classData['is_copy_constructible'] ?? true),
            hasPublicConstructor: (bool) ($classData['has_public_constructor'] ?? true),
            hasPublicDestructor: (bool) ($classData['has_public_destructor'] ?? true),
            isQObjectDerived: (bool) ($classData['is_qobject_derived'] ?? false),
            properties: $properties,
            methods: $methods,
            signals: $signals,
            classConstants: $classConstants,
            nativeCppType: $qualifiedName !== '' && $qualifiedName !== $className ? $qualifiedName : null,
            generationId: GeneratedTypeIdentity::fromNames($classData['name'], $qualifiedName !== '' ? $qualifiedName : null)->generationId,
            smartPointerAliases: $smartPointerAliases,
        );
    }

    /**
     * @param list<array<string, mixed>> $constants
     * @return list<PhpClassConstant>
     */
    private function buildClassConstants(array $constants): array
    {
        $result = [];
        $seenNames = [];

        foreach ($constants as $constant) {
            $name = is_string($constant['name'] ?? null) ? trim($constant['name']) : '';
            if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                continue;
            }
            if (isset($seenNames[$name])) {
                continue;
            }

            $value = $constant['value'] ?? null;
            if (!is_int($value) && !is_float($value) && !is_string($value)) {
                continue;
            }

            $enumName = is_string($constant['enum_name'] ?? null)
                ? trim((string) $constant['enum_name'])
                : '';

            $result[] = new PhpClassConstant(
                name: $name,
                value: $value,
                enumName: $enumName,
            );
            $seenNames[$name] = true;
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Properties
    // ------------------------------------------------------------------

    /**
     * @param list<array{name: string, type: string, access: string, is_static: bool}> $fields
     * @return list<PhpProperty>
     */
    private function buildProperties(
        array $fields,
        string $className,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): array
    {
        $properties = [];

        foreach ($fields as $field) {
            if ($field['access'] === 'private') {
                continue;
            }

            $cppType = $this->canonicalizeCppType(
                (string) ($field['type'] ?? ''),
                $classTypeResolver,
                $resolutionContext,
            );

            $properties[] = new PhpProperty(
                name: $field['name'],
                phpType: $this->typeMapper->map($cppType, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases),
                cppType: $cppType,
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
    private function buildMethods(
        array $methods,
        string $className,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): array
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
            $result[] = $this->mergeOverloads($name, $variants, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);
        }

        return $result;
    }

    /**
     * Merge one or more C++ method variants into a single PhpMethod.
     *
     * @param list<array<string, mixed>> $variants
     */
    private function mergeOverloads(
        string $name,
        array $variants,
        string $className,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): PhpMethod
    {
        // Build the MethodOverload list from all variants.
        $overloads = array_map(
            fn(array $variant): MethodOverload => $this->buildOverload($variant, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases),
            $variants,
        );

        // Determine access: use the most permissive (public > protected).
        $access = $this->mostPermissiveAccess($variants);

        // Determine if all variants are static.
        $isStatic = $this->allStatic($variants);

        // Build the merged PHP return type.
        $returnType = $this->mergeReturnTypes($variants, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);

        // Build the merged PHP parameter list.
        $parameters = $this->mergeParameters($variants, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);

        return new PhpMethod(
            name: $name,
            access: $access,
            isStatic: $isStatic,
            isSignal: $this->allFlagged($variants, 'is_signal'),
            isSlot: $this->allFlagged($variants, 'is_slot'),
            isAbstractMethod: $name !== '__construct' && $this->allFlagged($variants, 'is_pure_virtual'),
            returnType: $returnType,
            parameters: $parameters,
            overloads: $overloads,
            cppName: $name,
        );
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function buildOverload(
        array $variant,
        string $className,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): MethodOverload
    {
        $parameters = $this->normalizeWritableParameterDefaults($variant['parameters']);
        $params = array_map(
            function (array $p) use ($className, $classTypeResolver, $resolutionContext, $smartPointerAliases): OverloadParameter {
                $cppType = $this->canonicalizeCppType(
                    (string) ($p['type'] ?? ''),
                    $classTypeResolver,
                    $resolutionContext,
                );
                $metadata = $this->analyzeCppParameterType($cppType);
                $phpType = $this->typeMapper->map($cppType, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);
                if ($this->shouldExpandStringLikeForClass($className) && $this->canAcceptPhpStringForParameter($metadata)) {
                    $phpType = $this->expandStringLikeParameterPhpType($phpType, $cppType);
                }
                $smartPointerTargetCppType = $this->resolveSmartPointerTargetCppType($cppType, $smartPointerAliases);
                $writableByRefMeta = $this->analyzeWritableByRefParameter(
                    $cppType,
                    $phpType,
                    $metadata,
                    (bool) ($p['has_default'] ?? false),
                );

                return new OverloadParameter(
                    name: (string) ($p['name'] ?? ''),
                    cppType: $cppType,
                    hasDefault: (bool) ($p['has_default'] ?? false),
                    smartPointerTargetCppType: $smartPointerTargetCppType,
                    isReference: $metadata['is_reference'],
                    isConstReference: $metadata['is_const_reference'],
                    isNonConstReference: $metadata['is_non_const_reference'],
                    isRvalueReference: $metadata['is_rvalue_reference'],
                    pointerDepth: $metadata['pointer_depth'],
                    isWritableByRef: $writableByRefMeta['is_writable_by_ref'],
                    isWritableByRefPointer: $writableByRefMeta['is_writable_by_ref_pointer'],
                    isWritableQtString: $writableByRefMeta['is_writable_qt_string'],
                );
            },
            $parameters,
        );

        $returnCppType = $this->canonicalizeCppType(
            (string) ($variant['return_type'] ?? 'void'),
            $classTypeResolver,
            $resolutionContext,
        );
        $declaringClass = $this->canonicalizeCppType(
            (string) ($variant['declaring_class'] ?? ''),
            $classTypeResolver,
            $resolutionContext,
        );

        return new MethodOverload(
            declaringClass: $declaringClass,
            returnType: $returnCppType,
            smartPointerReturnTargetCppType: $this->resolveSmartPointerTargetCppType($returnCppType, $smartPointerAliases),
            parameters: $params,
            access: (string) ($variant['access'] ?? 'public'),
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
    private function mergeReturnTypes(
        array $variants,
        string $className,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): string
    {
        $phpTypes = [];

        foreach ($variants as $v) {
            $returnType = (string) ($v['return_type'] ?? 'void');
            if (
                !($v['is_static'] ?? false)
                && SameClassReferenceResolver::isSameClassReference(
                    $returnType,
                    $className,
                    false,
                    is_string($v['declaring_class'] ?? null) ? $v['declaring_class'] : null,
                    $classTypeResolver,
                    $resolutionContext,
                )
            ) {
                $phpTypes[] = 'static';
                continue;
            }

            $cppType = $this->canonicalizeCppType(
                $returnType,
                $classTypeResolver,
                $resolutionContext,
            );
            $phpTypes[] = $this->typeMapper->map($cppType, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);
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
    private function mergeParameters(
        array $variants,
        string $className,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): array
    {
        $maxParams = 0;
        $minRequiredCount = PHP_INT_MAX;

        foreach ($variants as $v) {
            $params = $this->normalizeWritableParameterDefaults($v['parameters']);
            $count = \count($params);
            $maxParams = max($maxParams, $count);

            $requiredCount = 0;
            foreach ($params as $param) {
                if (($param['has_default'] ?? false) !== true) {
                    $requiredCount++;
                }
            }

            $minRequiredCount = min($minRequiredCount, $requiredCount);
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
            $allPresentVariantsWritableByRef = true;
            $writableKinds = [];
            $writablePhpTypes = [];
            $allWritablePointerVariantsHaveDefault = true;

            foreach ($variants as $v) {
                $params = $this->normalizeWritableParameterDefaults($v['parameters']);

                if ($i >= \count($params)) {
                    $someVariantsShorter = true;
                    continue;
                }

                $paramType = $this->canonicalizeCppType(
                    (string) ($params[$i]['type'] ?? ''),
                    $classTypeResolver,
                    $resolutionContext,
                );
                $mappedParamPhpType = $this->typeMapper->map($paramType, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);
                $paramMeta = $this->analyzeCppParameterType($paramType);
                $paramPhpType = ($this->shouldExpandStringLikeForClass($className) && $this->canAcceptPhpStringForParameter($paramMeta))
                    ? $this->expandStringLikeParameterPhpType($mappedParamPhpType, $paramType)
                    : $mappedParamPhpType;
                $phpTypes[] = $paramPhpType;
                $writableMeta = $this->analyzeWritableByRefParameter(
                    $paramType,
                    $paramPhpType,
                    $paramMeta,
                    (bool) ($params[$i]['has_default'] ?? false),
                );
                if (!$writableMeta['is_writable_by_ref']) {
                    $allPresentVariantsWritableByRef = false;
                } else {
                    $writableKinds[] = $writableMeta['kind'];
                    $writablePhpTypes[] = $paramPhpType;
                    if ($writableMeta['is_writable_by_ref_pointer'] && !$writableMeta['has_default']) {
                        $allWritablePointerVariantsHaveDefault = false;
                    }
                }

                $pName = $params[$i]['name'];
                if ($pName !== '' && !\in_array($pName, $names, true)) {
                    $names[] = $pName;
                }

                if (!$params[$i]['has_default']) {
                    $allHaveDefault = false;
                }
            }

            $hasDefault = $i >= $minRequiredCount || $someVariantsShorter || $allHaveDefault;
            $uniquePhpTypes = array_values(array_unique($phpTypes));
            $uniqueWritablePhpTypes = array_values(array_unique($writablePhpTypes));
            $uniqueWritableKinds = array_values(array_unique($writableKinds));
            $isByRef = $allPresentVariantsWritableByRef
                && $uniquePhpTypes !== []
                && \count($uniquePhpTypes) === 1
                && \count($uniqueWritablePhpTypes) === 1
                && \count($uniqueWritableKinds) === 1;
            $isNullableByRef = $isByRef
                && $uniqueWritableKinds[0] === 'pointer'
                && $allWritablePointerVariantsHaveDefault;

            // Pick a unique name. Try names from the variants first, then fallback.
            $name = $this->pickUniqueName($names, $i, $usedNames);
            $usedNames[$name] = true;

            $parameters[] = new PhpParameter(
                name: $name,
                phpType: $this->unionType($phpTypes),
                hasDefault: $hasDefault,
                position: $i,
                isByRef: $isByRef,
                isNullableByRef: $isNullableByRef,
            );
        }

        return $parameters;
    }

    private function canonicalizeCppType(
        string $cppType,
        ?CppClassTypeResolver $classTypeResolver,
        ?TypeResolutionContext $resolutionContext,
    ): string {
        if ($classTypeResolver === null || $resolutionContext === null) {
            return $cppType;
        }

        return $classTypeResolver->canonicalizeType($cppType, $resolutionContext);
    }

    /**
     * @param array<string, string> $aliases
     * @return array<string, string>
     */
    private function canonicalizeSmartPointerAliases(
        array $aliases,
        ?CppClassTypeResolver $classTypeResolver,
        ?TypeResolutionContext $resolutionContext,
    ): array {
        $resolved = [];
        foreach ($aliases as $alias => $target) {
            if (!is_string($alias) || !is_string($target)) {
                continue;
            }
            $resolved[trim($alias)] = $this->canonicalizeCppType(trim($target), $classTypeResolver, $resolutionContext);
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $smartPointerAliases
     */
    private function resolveSmartPointerTargetCppType(string $cppType, array $smartPointerAliases): ?string
    {
        if ($smartPointerAliases === []) {
            return null;
        }

        if (preg_match('/^(?:const\s+)?(?<alias>[A-Za-z_][A-Za-z0-9_]*)\s*(?:[&*]\s*)?$/', trim($cppType), $matches) !== 1) {
            return null;
        }

        $alias = trim((string) ($matches['alias'] ?? ''));
        if ($alias === '') {
            return null;
        }

        return $smartPointerAliases[$alias] ?? null;
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
     * @return array{is_reference: bool, is_const_reference: bool, is_non_const_reference: bool, is_rvalue_reference: bool, pointer_depth: int}
     */
    private function analyzeCppParameterType(string $cppType): array
    {
        $normalized = trim($cppType);
        $isRvalueReference = str_contains($normalized, '&&');
        $isReference = $isRvalueReference || str_contains($normalized, '&');
        $isConstReference = !$isRvalueReference && $isReference && preg_match('/^\s*const\b/', $normalized) === 1;

        return [
            'is_reference' => $isReference,
            'is_const_reference' => $isConstReference,
            'is_non_const_reference' => !$isRvalueReference && $isReference && !$isConstReference,
            'is_rvalue_reference' => $isRvalueReference,
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
     * @param array{is_reference: bool, is_const_reference: bool, is_non_const_reference: bool, is_rvalue_reference: bool, pointer_depth: int} $paramMetadata
     * @return array{is_writable_by_ref: bool, is_writable_by_ref_pointer: bool, is_writable_qt_string: bool, has_default: bool, kind: string}
     */
    private function analyzeWritableByRefParameter(
        string $cppType,
        string $phpType,
        array $paramMetadata,
        bool $hasDefault,
    ): array {
        $trimmed = trim($cppType);
        $baseType = $this->normalizeBaseCppType($trimmed);
        $isScalar = \in_array($phpType, ['int', 'float', 'bool'], true);
        $isQtString = $phpType === 'string' && \in_array($baseType, ['QString', 'QByteArray'], true);
        if (!$isScalar && !$isQtString) {
            return [
                'is_writable_by_ref' => false,
                'is_writable_by_ref_pointer' => false,
                'is_writable_qt_string' => false,
                'has_default' => $hasDefault,
                'kind' => 'none',
            ];
        }

        $isNonConstPointer = $paramMetadata['pointer_depth'] === 1
            && !$paramMetadata['is_reference']
            && preg_match('/^\s*const\b/', $trimmed) !== 1;
        $isWritableRef = $paramMetadata['is_non_const_reference'] && $paramMetadata['pointer_depth'] === 0;
        $isWritableByRef = $isWritableRef || $isNonConstPointer;

        return [
            'is_writable_by_ref' => $isWritableByRef,
            'is_writable_by_ref_pointer' => $isNonConstPointer,
            'is_writable_qt_string' => $isQtString,
            'has_default' => $hasDefault,
            'kind' => $isNonConstPointer ? 'pointer' : ($isWritableRef ? 'reference' : 'none'),
        ];
    }

    private function normalizeBaseCppType(string $cppType): string
    {
        $type = trim($cppType);
        $type = preg_replace('/\bconst\b/', '', $type) ?? $type;
        $type = trim(preg_replace('/\s+/', ' ', $type) ?? $type);
        $type = rtrim($type, '& ');
        if (!str_contains($type, '<')) {
            while (str_ends_with($type, '*')) {
                $type = rtrim(substr($type, 0, -1));
            }
        }

        return trim($type);
    }

    private function expandStringLikeParameterPhpType(string $phpType, string $cppType): string
    {
        if ($phpType === '' || $phpType === 'mixed' || str_contains($phpType, 'string')) {
            return $phpType;
        }

        $baseType = $this->normalizeBaseCppType($cppType);
        if (!\in_array($baseType, [
            'QString',
            'QByteArray',
            'QStringView',
            'QLatin1StringView',
            'QAnyStringView',
        ], true)) {
            return $phpType;
        }

        return $this->unionType([$phpType, 'string']);
    }

    /**
     * @param array{is_reference: bool, is_const_reference: bool, is_non_const_reference: bool, is_rvalue_reference: bool, pointer_depth: int} $paramMetadata
     */
    private function canAcceptPhpStringForParameter(array $paramMetadata): bool
    {
        return !$paramMetadata['is_non_const_reference']
            && !$paramMetadata['is_rvalue_reference']
            && $paramMetadata['pointer_depth'] === 0;
    }

    private function shouldExpandStringLikeForClass(string $className): bool
    {
        return !in_array($className, [
            'QString',
            'QByteArray',
            'QStringView',
            'QLatin1StringView',
            'QAnyStringView',
        ], true);
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

        if (\count($unique) > 1 && in_array('void', $unique, true)) {
            $unique = array_values(array_filter(
                $unique,
                static fn(string $type): bool => $type !== 'void',
            ));
            $unique[] = 'null';
            $unique = array_values(array_unique($unique));
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
