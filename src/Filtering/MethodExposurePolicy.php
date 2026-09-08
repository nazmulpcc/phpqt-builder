<?php

declare(strict_types=1);

namespace QtBuilder\Filtering;

use QtBuilder\Build\EnumHolderRegistry;
use QtBuilder\CodeGen\ContainerBridge;
use QtBuilder\CodeGen\TypeBridge;
use QtBuilder\Parsing\CppToPhpTypeMapper;
use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\CppName;
use QtBuilder\Support\OpenGLNumericPointerArrayRegistry;
use QtBuilder\Support\SameClassReferenceResolver;
use QtBuilder\Support\TypeResolutionContext;

class MethodExposurePolicy
{
    /** @var list<string> */
    private const array NAME_SKIP = [
        'connect',
        'disconnect',
        'metaObject',
        'qt_metacall',
        'qt_metacast',
        'qt_check_for_QGADGET_macro',
        'tr',
        'trUtf8',
        'fromRawData',
        'data_ptr',
        'd_func',
        'q_func',
    ];

    private CppToPhpTypeMapper $typeMapper;
    private TypeBridge $typeBridge;
    private ContainerBridge $containerBridge;

    public function __construct(
        ?CppToPhpTypeMapper $typeMapper = null,
        ?TypeBridge $typeBridge = null,
        ?ContainerBridge $containerBridge = null,
    ) {
        $this->typeMapper = $typeMapper ?? new CppToPhpTypeMapper();
        $this->typeBridge = $typeBridge ?? new TypeBridge();
        $this->containerBridge = $containerBridge ?? new ContainerBridge(typeMapper: $this->typeMapper);
    }

    /**
     * @param array{name: string, methods: list<array<string, mixed>>} $classData
     * @param list<string> $allowedClasses
     * @return array{selected_methods: list<array<string, mixed>>, skipped_methods: list<array<string, string>>}
     */
    public function filter(
        array $classData,
        array $allowedClasses = [],
        bool $preferExternalDependencyReasons = false,
        ?EnumHolderRegistry $enumRegistry = null,
        ?CppClassTypeResolver $classTypeResolver = null,
    ): array
    {
        $selectedMethods = [];
        $skippedMethods = [];
        $grouped = [];
        /** @var array<string, string> $flagAliases */
        $flagAliases = is_array($classData['flag_aliases'] ?? null) ? $classData['flag_aliases'] : [];
        /** @var list<string> $enumNames */
        $enumNames = is_array($classData['enum_names'] ?? null)
            ? array_values(array_filter(array_map(
                static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                $classData['enum_names'],
            ), static fn(string $value): bool => $value !== ''))
            : [];
        /** @var array<string, string> $smartPointerAliases */
        $smartPointerAliases = is_array($classData['smart_pointer_aliases'] ?? null) ? $classData['smart_pointer_aliases'] : [];

        foreach ($classData['methods'] as $method) {
            $grouped[$method['name']][] = $method;
        }

        $isCopyConstructible = (bool) ($classData['is_copy_constructible'] ?? true);
        $hasPublicConstructor = (bool) ($classData['has_public_constructor'] ?? true);
        $hasPublicDefaultConstructor = (bool) ($classData['has_public_default_constructor'] ?? true);
        $hasPublicDestructor = (bool) ($classData['has_public_destructor'] ?? true);
        $isAbstractClass = (bool) ($classData['is_abstract'] ?? false);
        $resolutionContext = TypeResolutionContext::fromClassData($classData);
        $this->containerBridge->setTypeResolutionMetadata($classTypeResolver, $resolutionContext, $smartPointerAliases);

        foreach ($grouped as $methodName => $variants) {
            $result = $this->selectVariants(
                $classData['name'],
                $methodName,
                $variants,
                $allowedClasses,
                $flagAliases,
                $enumNames,
                $isCopyConstructible,
                $hasPublicConstructor,
                $hasPublicDefaultConstructor,
                $hasPublicDestructor,
                $isAbstractClass,
                $preferExternalDependencyReasons,
                $enumRegistry,
                $classTypeResolver,
                $resolutionContext,
                $smartPointerAliases,
            );
            foreach ($result['selected'] as $selectedVariant) {
                $selectedMethods[] = $selectedVariant;
            }
            foreach ($result['skipped'] as $skipped) {
                $skippedMethods[] = $skipped;
            }
        }

        return [
            'selected_methods' => $selectedMethods,
            'skipped_methods' => $skippedMethods,
        ];
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @param list<string> $allowedClasses
     * @param array<string, string> $flagAliases
     * @param list<string> $enumNames
     * @return array{selected: list<array<string, mixed>>, skipped: list<array<string, string>>}
     */
    private function selectVariants(
        string $className,
        string $methodName,
        array $variants,
        array $allowedClasses,
        array $flagAliases,
        array $enumNames,
        bool $isCopyConstructible,
        bool $hasPublicConstructor,
        bool $hasPublicDefaultConstructor,
        bool $hasPublicDestructor,
        bool $isAbstractClass,
        bool $preferExternalDependencyReasons = false,
        ?EnumHolderRegistry $enumRegistry = null,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): array
    {
        if ($this->isFilteredMethodName($methodName)) {
            return [
                'selected' => [],
                'skipped' => [[
                    'name' => $methodName,
                    'reason_code' => 'method_name_filtered',
                    'reason_message' => sprintf('Method %s is filtered by name.', $methodName),
                ]],
            ];
        }

        $seenSignatures = [];
        /** @var array<string, array{variant: array<string, mixed>, score: list<int>}> $selectedByDispatch */
        $selectedByDispatch = [];
        $skipped = [];

        foreach ($variants as $variant) {
            $signature = $this->signature($variant);
            if (isset($seenSignatures[$signature])) {
                continue;
            }
            $seenSignatures[$signature] = true;

            $normalizedVariant = $this->normalizeSpecialTypes(
                $className,
                $variant,
                $flagAliases,
                $enumNames,
                $isAbstractClass,
                $enumRegistry,
                $resolutionContext,
            );
            $normalizedVariant = $this->canonicalizeVariantTypes($normalizedVariant, $classTypeResolver, $resolutionContext);
            $unsupportedReason = $this->unsupportedReason(
                $className,
                $normalizedVariant,
                $allowedClasses,
                $flagAliases,
                $enumNames,
                $isCopyConstructible,
                $hasPublicConstructor,
                $hasPublicDefaultConstructor,
                $hasPublicDestructor,
                $isAbstractClass,
                $preferExternalDependencyReasons,
                $enumRegistry,
                $classTypeResolver,
                $resolutionContext,
                $smartPointerAliases,
            );
            if ($unsupportedReason !== null) {
                $skipped[] = [
                    'name' => $methodName,
                    'reason_code' => $unsupportedReason['code'],
                    'reason_message' => $unsupportedReason['message'],
                ];
                continue;
            }

            $dispatchSignature = $this->dispatchSignature($normalizedVariant, $classTypeResolver, $resolutionContext);
            $score = $this->score($normalizedVariant);
            $existing = $selectedByDispatch[$dispatchSignature] ?? null;

            if ($existing === null) {
                $selectedByDispatch[$dispatchSignature] = [
                    'variant' => $normalizedVariant,
                    'score' => $score,
                ];
                continue;
            }

            if ($this->compareScores($score, $existing['score']) < 0) {
                $skipped[] = [
                    'name' => $methodName,
                    'reason_code' => 'indistinguishable_overload',
                    'reason_message' => 'Overload collapses to the same PHP runtime signature as a preferred variant.',
                ];
                $selectedByDispatch[$dispatchSignature] = [
                    'variant' => $normalizedVariant,
                    'score' => $score,
                ];
                continue;
            }

            $skipped[] = [
                'name' => $methodName,
                'reason_code' => 'indistinguishable_overload',
                'reason_message' => 'Overload collapses to the same PHP runtime signature as a preferred variant.',
            ];
        }

        $selected = array_values(array_map(
            static fn(array $entry): array => $entry['variant'],
            $selectedByDispatch,
        ));

        if ($selected === []) {
            return ['selected' => [], 'skipped' => $skipped];
        }

        return [
            'selected' => $selected,
            'skipped' => $skipped,
        ];
    }

    private function isFilteredMethodName(string $methodName): bool
    {
        if (str_starts_with($methodName, '~') || str_starts_with($methodName, 'operator')) {
            return true;
        }

        if (in_array($methodName, self::NAME_SKIP, true)) {
            return true;
        }

        // Macro artifacts can leak through cparser as pseudo-methods.
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $methodName) === 1) {
            return true;
        }

        // Qt private helpers frequently use this suffix and are not public API.
        if (str_ends_with($methodName, '_helper')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $variant
     * @param list<string> $allowedClasses
     * @param array<string, string> $flagAliases
     * @param list<string> $enumNames
     * @return array{code: string, message: string}|null
     */
    private function unsupportedReason(
        string $className,
        array $variant,
        array $allowedClasses,
        array $flagAliases = [],
        array $enumNames = [],
        bool $isCopyConstructible = true,
        bool $hasPublicConstructor = true,
        bool $hasPublicDefaultConstructor = true,
        bool $hasPublicDestructor = true,
        bool $isAbstractClass = false,
        bool $preferExternalDependencyReasons = false,
        ?EnumHolderRegistry $enumRegistry = null,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): ?array
    {
        $access = (string) ($variant['access'] ?? 'unknown');
        $isConstructor = $this->isConstructor($className, $variant);
        if ($isConstructor) {
            if ($isAbstractClass) {
                if ($access !== 'public' && $access !== 'protected') {
                    return ['code' => 'non_public_constructor', 'message' => sprintf('Constructors with %s access are not exposed.', $access)];
                }
            } elseif ($access !== 'public') {
                return ['code' => 'non_public_constructor', 'message' => sprintf('Constructors with %s access are not exposed.', $access)];
            }
        }

        if (!$isConstructor && ($access === 'private' || $access === 'unknown')) {
            return ['code' => 'non_public_method', 'message' => sprintf('Methods with %s access are not exposed.', $access)];
        }

        if ($isConstructor) {
            if (($variant['is_deleted'] ?? false) === true) {
                return ['code' => 'deleted_constructor', 'message' => 'Deleted constructors are not exposed.'];
            }

            if ($this->isCopyConstructor($className, $variant, $classTypeResolver, $resolutionContext)) {
                return ['code' => 'copy_constructor_filtered', 'message' => 'Copy constructors are not exposed as PHP constructors.'];
            }

            if (!$hasPublicDestructor) {
                return ['code' => 'non_public_destructor', 'message' => 'Classes with non-public destructors cannot be directly instantiated.'];
            }

            if (!$isAbstractClass && !$hasPublicConstructor) {
                return ['code' => 'non_public_constructor', 'message' => 'Class does not provide a public constructor for direct instantiation.'];
            }

            if (!$isAbstractClass && $this->isDefaultConstructor($variant) && !$hasPublicDefaultConstructor) {
                return ['code' => 'non_public_constructor', 'message' => 'Default constructor is not publicly accessible.'];
            }
        }

        if (!$isCopyConstructible && $this->isCopyConstructor($className, $variant, $classTypeResolver, $resolutionContext)) {
            return ['code' => 'noncopyable_copy_constructor', 'message' => 'Copy constructor is disabled by the native class definition.'];
        }

        $returnType = (string) $variant['return_type'];
        if ($this->isSameClassReferenceReturn($returnType, $className, $variant, $classTypeResolver, $resolutionContext)) {
            // Safe fluent same-class return — do not reject as unsafe reference return
        } elseif ($this->isUnsafeReferenceReturn($returnType)) {
            return ['code' => 'unsupported_reference_return', 'message' => 'Non-const reference returns are skipped.'];
        }

        if ($this->isUnsupportedValueBufferReturn($returnType)) {
            return ['code' => 'unsupported_buffer_return', 'message' => sprintf('Return type %s exposes a raw internal buffer.', $returnType)];
        }

        $externalReturnDependency = $preferExternalDependencyReasons
            ? $this->unavailableExternalClassDependency($returnType, $className, $allowedClasses, $classTypeResolver, $resolutionContext, $smartPointerAliases)
            : null;
        if ($externalReturnDependency !== null) {
            return [
                'code' => 'unsupported_external_module_dependency',
                'message' => sprintf(
                    'Return type %s requires unavailable external class %s.',
                    $returnType,
                    $externalReturnDependency,
                ),
            ];
        }

        if (!$this->isSupportedType($returnType, $className, $allowedClasses, true, $flagAliases, $enumNames, $enumRegistry, $classTypeResolver, $resolutionContext, $smartPointerAliases)) {
            return ['code' => 'unsupported_return_type', 'message' => sprintf('Return type %s is not supported.', $returnType)];
        }

        foreach ($this->effectiveSignalParameters($variant) as $parameter) {
            $type = (string) $parameter['type'];
            if ($this->isUnsupportedWritableByRefParameter($type)) {
                return ['code' => 'unsupported_output_parameter', 'message' => sprintf('Parameter type %s looks like an output parameter.', $type)];
            }
            $externalParameterDependency = $preferExternalDependencyReasons
                ? $this->unavailableExternalClassDependency($type, $className, $allowedClasses, $classTypeResolver, $resolutionContext, $smartPointerAliases)
                : null;
            if ($externalParameterDependency !== null) {
                return [
                    'code' => 'unsupported_external_module_dependency',
                    'message' => sprintf(
                        'Parameter type %s requires unavailable external class %s.',
                        $type,
                        $externalParameterDependency,
                    ),
                ];
            }
            if (!$this->isSupportedType($type, $className, $allowedClasses, false, $flagAliases, $enumNames, $enumRegistry, $classTypeResolver, $resolutionContext, $smartPointerAliases)) {
                return ['code' => 'unsupported_parameter_type', 'message' => sprintf('Parameter type %s is not supported.', $type)];
            }
        }

        return null;
    }

    /**
     * @param list<string> $allowedClasses
     */
    private function unavailableExternalClassDependency(
        string $cppType,
        string $className,
        array $allowedClasses,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): ?string
    {
        $trimmed = trim($this->canonicalizeType($cppType, $classTypeResolver, $resolutionContext));
        if ($trimmed === '') {
            return null;
        }

        if ($this->containerBridge->isSupported($trimmed)) {
            foreach ($this->containerBridge->classRefs($trimmed) as $classRef) {
                $resolvedClassRef = $this->allowedClassLookupKey($classRef, $classTypeResolver, $resolutionContext);
                if ($resolvedClassRef !== $className && !$this->allowedClassesContainType($allowedClasses, $resolvedClassRef)) {
                    return $classRef;
                }
            }

            return null;
        }

        $phpType = $this->typeMapper->map($trimmed, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);
        if (!$this->typeBridge->isObjectType($phpType) || $phpType === $className) {
            return null;
        }

        $allowedLookup = $this->allowedClassLookupKey(
            $this->resolveSmartPointerAliasTargetCppType($trimmed, $smartPointerAliases) ?? $trimmed,
            $classTypeResolver,
            $resolutionContext,
        );

        return $this->allowedClassesContainType($allowedClasses, $allowedLookup) ? null : $phpType;
    }

    /**
     * @param array<string, mixed> $variant
     * @return list<array<string, mixed>>
     */
    private function effectiveSignalParameters(array $variant): array
    {
        $parameters = is_array($variant['parameters'] ?? null) ? $variant['parameters'] : [];
        if (($variant['is_signal'] ?? false) !== true || $parameters === []) {
            return $parameters;
        }

        $lastIndex = count($parameters) - 1;
        $lastType = is_string($parameters[$lastIndex]['type'] ?? null)
            ? trim((string) $parameters[$lastIndex]['type'])
            : '';

        if ($lastType === 'QPrivateSignal') {
            array_pop($parameters);
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function isCopyConstructor(
        string $className,
        array $variant,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
    ): bool
    {
        if (!$this->isConstructor($className, $variant)) {
            return false;
        }

        $parameters = is_array($variant['parameters'] ?? null) ? $variant['parameters'] : [];
        if (count($parameters) !== 1) {
            return false;
        }

        $type = is_string($parameters[0]['type'] ?? null) ? $parameters[0]['type'] : '';
        if ($type === '') {
            return false;
        }

        $trimmed = trim($type);
        if (str_contains($trimmed, '*') || !str_contains($trimmed, '&')) {
            return false;
        }

        $normalized = $this->normalizeSelfType($trimmed);
        $resolvedPhpClass = $classTypeResolver?->resolvePhpClassIdentity($normalized, $resolutionContext);
        if ($resolvedPhpClass !== null) {
            return $resolvedPhpClass === $className;
        }

        if (str_contains($normalized, '::')) {
            $normalized = (string) substr($normalized, (int) strrpos($normalized, '::') + 2);
        }

        return $normalized === $className;
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function isConstructor(string $className, array $variant): bool
    {
        return ($variant['name'] ?? null) === $className;
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function isDefaultConstructor(array $variant): bool
    {
        $parameters = is_array($variant['parameters'] ?? null) ? $variant['parameters'] : [];

        return count($parameters) === 0;
    }

    private function normalizeSelfType(string $cppType): string
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

    public function isSameClassReferenceReturn(
        string $returnType,
        string $className,
        array $variant,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
    ): bool {
        return SameClassReferenceResolver::isSameClassReference(
            $returnType,
            $className,
            (bool) ($variant['is_static'] ?? false),
            is_string($variant['declaring_class'] ?? null) ? $variant['declaring_class'] : null,
            $classTypeResolver,
            $resolutionContext,
        );
    }

    private function isUnsafeReferenceReturn(string $cppType): bool
    {
        $trimmed = trim($cppType);

        return str_contains($trimmed, '&') && !str_starts_with($trimmed, 'const ');
    }

    /**
     * @param list<string> $allowedClasses
     * @param array<string, string> $flagAliases
     * @param list<string> $enumNames
     */
    private function isSupportedType(
        string $cppType,
        string $className,
        array $allowedClasses,
        bool $isReturn,
        array $flagAliases = [],
        array $enumNames = [],
        ?EnumHolderRegistry $enumRegistry = null,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
        array $smartPointerAliases = [],
    ): bool
    {
        $trimmed = trim($this->canonicalizeType($cppType, $classTypeResolver, $resolutionContext));
        if ($trimmed === '') {
            return false;
        }

        if ($trimmed === 'void') {
            return true;
        }

        if ($this->isDisambiguationTagType($trimmed)) {
            return false;
        }

        if (preg_match('/\(\s*\*/', $trimmed) === 1 || str_contains($trimmed, 'std::function')) {
            return false;
        }

        if (str_contains($trimmed, '<') && !$this->isSupportedTemplateType($trimmed)) {
            return false;
        }

        if ($this->hasMultiplePointerIndirection($trimmed) && !$this->isSupportedArrayType($trimmed, $className)) {
            return false;
        }

        if (
            $this->isUnsupportedScalarPointerType($trimmed, $className)
            && !(!$isReturn && $this->isSupportedWritableScalarPointerType($trimmed, $className))
        ) {
            return false;
        }

        if ($this->isEnumOrFlagType($trimmed, $className, $flagAliases, $enumNames)) {
            return true;
        }

        if ($enumRegistry?->supportsType($trimmed) === true) {
            return true;
        }

        if ($this->isResolvedForeignNestedEnumType($trimmed, $classTypeResolver, $resolutionContext)) {
            return true;
        }

        $phpType = $this->typeMapper->map($trimmed, $className, $classTypeResolver, $resolutionContext, $smartPointerAliases);
        if (
            str_contains($trimmed, '::')
            && in_array($phpType, ['int', 'float', 'bool'], true)
            && !$this->isKnownQualifiedScalarType($trimmed)
        ) {
            return false;
        }

        if (in_array($phpType, ['int', 'float', 'bool', 'string', 'void'], true)) {
            return true;
        }

        if ($phpType === 'array') {
            if ($this->isSupportedArrayType($trimmed, $className, $isReturn)) {
                return true;
            }

            if (!$this->containerBridge->isSupported($trimmed)) {
                return false;
            }

            foreach ($this->containerBridge->classRefs($trimmed) as $classRef) {
                $resolvedClassRef = $this->allowedClassLookupKey($classRef, $classTypeResolver, $resolutionContext);
                if ($resolvedClassRef !== $className && !$this->allowedClassesContainType($allowedClasses, $resolvedClassRef)) {
                    return false;
                }
            }

            return true;
        }

        if ($phpType === 'mixed') {
            return false;
        }

        if ($phpType === $className || $phpType === '\\' . ($resolutionContext?->module !== null ? \QtBuilder\Support\ModuleNamespace::forQtModule($resolutionContext->module) . '\\' . $className : $className)) {
            return true;
        }

        if ($this->typeBridge->isObjectType($phpType)) {
            return $this->allowedClassesContainType(
                $allowedClasses,
                $this->allowedClassLookupKey(
                    $this->resolveSmartPointerAliasTargetCppType($trimmed, $smartPointerAliases) ?? $trimmed,
                    $classTypeResolver,
                    $resolutionContext,
                ),
            );
        }

        return false;
    }

    private function allowedClassLookupKey(
        string $type,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
    ): string {
        $trimmed = trim($type);
        if ($trimmed === '') {
            return $trimmed;
        }

        if ($classTypeResolver === null) {
            return $this->normalizedClassLikeLookupKey($trimmed);
        }

        return $this->normalizedClassLikeLookupKey(
            $classTypeResolver->canonicalizeType($trimmed, $resolutionContext ?? TypeResolutionContext::fromNames($trimmed)),
        );
    }

    /**
     * @param list<string> $allowedClasses
     */
    private function allowedClassesContainType(array $allowedClasses, string $lookup): bool
    {
        if (in_array($lookup, $allowedClasses, true)) {
            return true;
        }

        $bareLookup = CppName::unqualify($lookup);

        return $bareLookup !== $lookup && in_array($bareLookup, $allowedClasses, true);
    }

    private function normalizedClassLikeLookupKey(string $type): string
    {
        $trimmed = trim($type);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^(?:const\s+)?(?<base>(?:::)?(?:[A-Za-z_][A-Za-z0-9_]*::)*[A-Za-z_][A-Za-z0-9_]*)(?:\s*[*&]\s*)*$/', $trimmed, $matches) === 1) {
            return trim((string) ($matches['base'] ?? $trimmed));
        }

        return $trimmed;
    }

    /**
     * @param array<string, string> $smartPointerAliases
     */
    private function resolveSmartPointerAliasTargetCppType(string $cppType, array $smartPointerAliases): ?string
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

    private function isResolvedForeignNestedEnumType(
        string $cppType,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
    ): bool {
        if ($classTypeResolver === null || $resolutionContext === null || !str_contains($cppType, '::')) {
            return false;
        }

        $lastSeparator = (int) strrpos($cppType, '::');
        if ($lastSeparator <= 0) {
            return false;
        }

        $owner = substr($cppType, 0, $lastSeparator);
        $member = substr($cppType, $lastSeparator + 2);
        if ($owner === '' || $member === '') {
            return false;
        }

        if (!$this->looksLikeForeignNestedEnumName($member)) {
            return false;
        }

        return $classTypeResolver->resolvePhpClassIdentity($owner, $resolutionContext) !== null;
    }

    private function looksLikeForeignNestedEnumName(string $name): bool
    {
        if ($this->looksLikeInheritedOrGlobalEnumName($name)) {
            return true;
        }

        foreach ([
            'Type',
            'Types',
            'Mode',
            'Modes',
            'Flag',
            'Flags',
            'Policy',
            'Format',
            'Formats',
            'Function',
            'Functions',
            'Face',
            'Faces',
            'Target',
            'Targets',
            'Filter',
            'Filters',
        ] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function hasMultiplePointerIndirection(string $cppType): bool
    {
        $normalized = preg_replace('/\bconst\b/', '', $cppType) ?? $cppType;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        return preg_match('/\*\s*\*/', $normalized) === 1;
    }

    private function isSupportedTemplateType(string $cppType): bool
    {
        $trimmed = trim($cppType);

        return str_starts_with($trimmed, 'QFlags<') || $this->containerBridge->isSupported($trimmed);
    }

    private function isEnumOrFlagType(string $cppType, string $className, array $flagAliases = [], array $enumNames = []): bool
    {
        $trimmed = trim($cppType);

        if ($this->isDisambiguationTagType($trimmed) || str_starts_with($trimmed, 'std::')) {
            return false;
        }

        if (str_starts_with($trimmed, 'QFlags<')) {
            return true;
        }

        if (str_contains($trimmed, '::')) {
            $prefix = substr($trimmed, 0, (int) strrpos($trimmed, '::'));
            $suffix = substr($trimmed, (int) strrpos($trimmed, '::') + 2);
            if ($suffix === '') {
                return false;
            }

            if ($prefix === 'Qt') {
                return $this->looksLikeQualifiedEnumName($suffix);
            }

            if ($prefix === $className) {
                return isset($flagAliases[$suffix])
                    || in_array($suffix, $enumNames, true)
                    || $this->looksLikeInheritedOrGlobalEnumName($suffix);
            }

            return isset($flagAliases[$suffix])
                || in_array($suffix, $enumNames, true);
        }

        if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            return false;
        }

        if (isset($flagAliases[$trimmed]) || in_array($trimmed, $enumNames, true)) {
            return true;
        }

        if ($this->looksLikeInheritedOrGlobalEnumName($trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $variant
     * @param array<string, string> $flagAliases
     * @param list<string> $enumNames
     * @return array<string, mixed>
     */
    private function normalizeSpecialTypes(
        string $className,
        array $variant,
        array $flagAliases,
        array $enumNames = [],
        bool $isAbstractClass = false,
        ?EnumHolderRegistry $enumRegistry = null,
        ?TypeResolutionContext $resolutionContext = null,
    ): array
    {
        $variant['return_type'] = $this->normalizeEnumType(
            $className,
            (string) $variant['return_type'],
            $flagAliases,
            $enumNames,
            $enumRegistry,
            $resolutionContext,
        );
        $variant['parameters'] = array_map(
            function (array $parameter) use ($className, $flagAliases, $enumNames, $enumRegistry, $resolutionContext): array {
                $parameter['type'] = $this->normalizeEnumType(
                    $className,
                    (string) ($parameter['type'] ?? ''),
                    $flagAliases,
                    $enumNames,
                    $enumRegistry,
                    $resolutionContext,
                );

                return $parameter;
            },
            $variant['parameters'],
        );
        $variant['parameters'] = $this->stripTrailingDisambiguationTagParameters($variant['parameters']);

        if ($isAbstractClass && $this->isConstructor($className, $variant)) {
            $variant['access'] = 'protected';
        }

        return $variant;
    }

    /**
     * Qt 6 injects defaulted disambiguation tag parameters into some public
     * APIs. They are not user-facing API surface and should not block method
     * exposure when they appear as optional trailing parameters.
     *
     * @param list<array<string, mixed>> $parameters
     * @return list<array<string, mixed>>
     */
    private function stripTrailingDisambiguationTagParameters(array $parameters): array
    {
        while ($parameters !== []) {
            $last = $parameters[array_key_last($parameters)];
            $type = is_string($last['type'] ?? null) ? trim((string) $last['type']) : '';
            $hasDefault = ($last['has_default'] ?? false) === true;
            if (!$hasDefault || !$this->isDisambiguationTagType($type)) {
                break;
            }

            array_pop($parameters);
        }

        return array_values($parameters);
    }

    /**
     * @param array<string, string> $flagAliases
     * @param list<string> $enumNames
     */
    private function normalizeEnumType(
        string $className,
        string $cppType,
        array $flagAliases = [],
        array $enumNames = [],
        ?EnumHolderRegistry $enumRegistry = null,
        ?TypeResolutionContext $resolutionContext = null,
    ): string
    {
        $trimmed = trim($cppType);
        $ownerQualifiedClass = $resolutionContext?->qualifiedClassName ?? $className;

        if ($this->isDisambiguationTagType($trimmed)) {
            return $trimmed;
        }

        if (isset($flagAliases[$trimmed])) {
            return sprintf('QFlags<%s::%s>', $ownerQualifiedClass, $flagAliases[$trimmed]);
        }

        $qualifiedPrefix = $className . '::';
        if (str_starts_with($trimmed, $qualifiedPrefix)) {
            $nested = substr($trimmed, strlen($qualifiedPrefix));
            if ($nested !== '' && isset($flagAliases[$nested])) {
                return sprintf('QFlags<%s::%s>', $ownerQualifiedClass, $flagAliases[$nested]);
            }

            if ($nested !== '') {
                return sprintf('%s::%s', $ownerQualifiedClass, $nested);
            }
        }

        if (str_contains($trimmed, '::')) {
            $lastSeparator = (int) strrpos($trimmed, '::');
            $prefix = substr($trimmed, 0, $lastSeparator);
            $nested = substr($trimmed, $lastSeparator + 2);
            if (
                $prefix !== ''
                && $nested !== ''
                && !str_contains($prefix, '::')
                && $prefix !== $className
                && in_array($nested, $enumNames, true)
            ) {
                return sprintf('%s::%s::%s', $className, $prefix, $nested);
            }

            if ($prefix !== '' && $nested !== '' && isset($flagAliases[$nested])) {
                return sprintf('QFlags<%s::%s>', $prefix, $flagAliases[$nested]);
            }

            if ($prefix === $className && $nested !== '') {
                return sprintf('%s::%s', $ownerQualifiedClass, $nested);
            }
        }

        if (!$this->isEnumOrFlagType($trimmed, $className, $flagAliases, $enumNames)) {
            if ($enumRegistry?->supportsType($trimmed) === true) {
                return $trimmed;
            }

            return $trimmed;
        }

        if (str_starts_with($trimmed, 'QFlags<') || str_contains($trimmed, '::')) {
            return $trimmed;
        }

        // Global Qt enums (e.g. QtMsgType) are already fully named.
        if (str_starts_with($trimmed, 'Qt')) {
            return $trimmed;
        }

        return $ownerQualifiedClass . '::' . $trimmed;
    }

    private function looksLikeInheritedOrGlobalEnumName(string $name): bool
    {
        if (str_starts_with($name, 'Qt')) {
            if ($name === 'QtMsgType') {
                return true;
            }

            foreach (['Type', 'Mode', 'Flag', 'Flags', 'Policy'] as $suffix) {
                if (str_ends_with($name, $suffix)) {
                    return true;
                }
            }

            return false;
        }

        // Avoid treating Qt classes as enums by default.
        if (str_starts_with($name, 'Q')) {
            return false;
        }

        foreach (['Mode', 'Modes', 'Flag', 'Flags'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeQualifiedEnumName(string $name): bool
    {
        if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $name) !== 1) {
            return false;
        }

        if (str_ends_with($name, '_t')) {
            return false;
        }

        foreach (['Result', 'Private', 'Data', 'Pointer', 'Iterator', 'Ref', 'Helper', 'Connection', 'Provider', 'Callback'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return false;
            }
        }

        return true;
    }

    private function isDisambiguationTagType(string $cppType): bool
    {
        return trim($cppType) === 'Qt::Disambiguated_t';
    }

    private function isUnsupportedScalarPointerType(string $cppType, ?string $className = null): bool
    {
        if (!str_contains($cppType, '*')) {
            return false;
        }

        if (preg_match('/\(\s*\*/', $cppType) === 1 || str_contains($cppType, 'std::function')) {
            return true;
        }

        $phpType = $this->typeMapper->map($cppType, $className);

        return in_array($phpType, ['int', 'float', 'bool'], true);
    }

    private function isSupportedWritableScalarPointerType(string $cppType, ?string $className = null): bool
    {
        $trimmed = trim($cppType);
        if (substr_count($trimmed, '*') !== 1 || str_contains($trimmed, '&')) {
            return false;
        }

        if (preg_match('/^\s*const\b/', $trimmed) === 1) {
            return false;
        }

        $phpType = $this->typeMapper->map($trimmed, $className);

        return in_array($phpType, ['int', 'float', 'bool'], true);
    }

    private function isUnsupportedWritableByRefParameter(string $cppType): bool
    {
        $trimmed = trim($cppType);
        if ($trimmed === '') {
            return false;
        }

        $pointerDepth = substr_count($trimmed, '*');
        $isRvalueReference = str_contains($trimmed, '&&');
        $isReference = !$isRvalueReference && str_contains($trimmed, '&');
        $isConstReference = $isReference && preg_match('/^\s*const\b/', $trimmed) === 1;
        $isNonConstReference = $isReference && !$isConstReference;
        $isNonConstPointer = $pointerDepth === 1 && !$isReference && preg_match('/^\s*const\b/', $trimmed) !== 1;

        if (!$isNonConstReference && !$isNonConstPointer) {
            return false;
        }

        if ($pointerDepth > 1) {
            return true;
        }

        $phpType = $this->typeMapper->map($trimmed);
        if (in_array($phpType, ['int', 'float', 'bool'], true)) {
            return false;
        }

        if ($this->typeBridge->isObjectType($phpType)) {
            return false;
        }

        $baseType = $this->normalizeSelfType($trimmed);
        if ($phpType === 'string' && ($baseType === 'QString' || $baseType === 'QByteArray')) {
            return false;
        }

        return true;
    }

    private function isSupportedArrayType(string $cppType, ?string $className = null, bool $isReturn = false): bool
    {
        $normalized = preg_replace('/\bconst\b/', '', $cppType) ?? $cppType;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        if (preg_match('/^char\s*\*\s*\*$/', $normalized) === 1) {
            return true;
        }

        if ($isReturn) {
            return false;
        }

        if (OpenGLNumericPointerArrayRegistry::supports($cppType)) {
            return true;
        }

        if (!$this->isOpenGLScopedOwner($className)) {
            return false;
        }

        return preg_match('/^const (?:GL)?void\s*\*$/', trim($cppType)) === 1;
    }

    private function isOpenGLScopedOwner(?string $className): bool
    {
        return is_string($className) && $className !== '' && str_starts_with($className, 'QOpenGL');
    }

    private function isKnownQualifiedScalarType(string $cppType): bool
    {
        $trimmed = trim($cppType);

        return str_starts_with($trimmed, 'std::chrono::');
    }

    private function isUnsupportedValueBufferReturn(string $cppType): bool
    {
        $trimmed = trim($cppType);
        if (!str_contains($trimmed, '*')) {
            return false;
        }

        return $this->normalizeSelfType($trimmed) === 'QChar';
    }

    /**
     * @param array<string, mixed> $variant
     * @return list<int>
     */
    private function score(array $variant): array
    {
        $required = 0;
        $pointerPenalty = substr_count((string) $variant['return_type'], '*');
        $templatePenalty = str_contains((string) $variant['return_type'], '<') ? 1 : 0;
        $returnIsSelfRef = false;
        $declaringClass = is_string($variant['declaring_class'] ?? null) ? $variant['declaring_class'] : '';
        if ($declaringClass !== '' && !($variant['is_static'] ?? false)) {
            $returnIsSelfRef = SameClassReferenceResolver::isSameClassReference(
                (string) $variant['return_type'],
                $declaringClass,
                false,
                $declaringClass,
            );
        }
        $referencePenalty = (!$returnIsSelfRef && str_contains((string) $variant['return_type'], '&')) ? 1 : 0;

        foreach ($variant['parameters'] as $parameter) {
            if (!$parameter['has_default']) {
                $required++;
            }
            $pointerPenalty += substr_count((string) $parameter['type'], '*');
            $templatePenalty += str_contains((string) $parameter['type'], '<') ? 1 : 0;
            $referencePenalty += str_contains((string) $parameter['type'], '&') ? 1 : 0;
        }

        return [
            $required,
            count($variant['parameters']),
            $pointerPenalty,
            $templatePenalty,
            $referencePenalty,
        ];
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     */
    private function compareScores(array $left, array $right): int
    {
        foreach ($left as $index => $value) {
            $comparison = $value <=> $right[$index];
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function signature(array $variant): string
    {
        $parts = [$variant['name'], $variant['return_type']];
        foreach ($variant['parameters'] as $parameter) {
            $parts[] = $parameter['type'];
            $parts[] = $parameter['has_default'] ? '1' : '0';
        }

        return implode('|', $parts);
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function dispatchSignature(
        array $variant,
        ?CppClassTypeResolver $classTypeResolver = null,
        ?TypeResolutionContext $resolutionContext = null,
    ): string
    {
        $parts = [
            $variant['name'],
            (($variant['is_static'] ?? false) === true) ? 'static' : 'instance',
        ];

        foreach ($variant['parameters'] as $parameter) {
            $parts[] = $this->typeMapper->map(
                (string) ($parameter['type'] ?? ''),
                $variant['declaring_class'] ?? null,
                $classTypeResolver,
                $resolutionContext,
            );
            $parts[] = (($parameter['has_default'] ?? false) === true) ? '1' : '0';
        }

        return implode('|', $parts);
    }

    /**
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    private function canonicalizeVariantTypes(
        array $variant,
        ?CppClassTypeResolver $classTypeResolver,
        ?TypeResolutionContext $resolutionContext,
    ): array {
        $variant['return_type'] = $this->canonicalizeType(
            (string) ($variant['return_type'] ?? ''),
            $classTypeResolver,
            $resolutionContext,
        );
        $variant['parameters'] = array_map(
            fn(array $parameter): array => [
                ...$parameter,
                'type' => $this->canonicalizeType(
                    (string) ($parameter['type'] ?? ''),
                    $classTypeResolver,
                    $resolutionContext,
                ),
            ],
            $variant['parameters'] ?? [],
        );

        return $variant;
    }

    private function canonicalizeType(
        string $cppType,
        ?CppClassTypeResolver $classTypeResolver,
        ?TypeResolutionContext $resolutionContext,
    ): string {
        if ($classTypeResolver === null || $resolutionContext === null) {
            return $cppType;
        }

        return $classTypeResolver->canonicalizeType($cppType, $resolutionContext);
    }
}
