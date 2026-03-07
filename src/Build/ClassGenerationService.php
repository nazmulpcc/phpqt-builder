<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Build\EnumHolderRegistry;
use QtBuilder\CodeGen\TypeBridge;
use QtBuilder\Containers\QListSpecializationResolver;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Filtering\ClassExposurePolicy;
use QtBuilder\Filtering\MethodExposurePolicy;
use QtBuilder\Parsing\ClassDefinitionBuilder;
use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\CppToPhpTypeMapper;
use QtBuilder\Parsing\QtClassInspector;

class ClassGenerationService
{
    private const WIDGET_INHERITED_EVENT_METHODS = [
        'event',
        'mousePressEvent',
        'mouseReleaseEvent',
        'mouseDoubleClickEvent',
        'mouseMoveEvent',
        'wheelEvent',
    ];

    public function __construct(
        private readonly ClassExposurePolicy $classPolicy = new ClassExposurePolicy(),
        private readonly MethodExposurePolicy $methodPolicy = new MethodExposurePolicy(),
        private readonly ClassDefinitionBuilder $builder = new ClassDefinitionBuilder(),
        private readonly TypeBridge $typeBridge = new TypeBridge(),
        private readonly CppToPhpTypeMapper $typeMapper = new CppToPhpTypeMapper(),
        private readonly QListSpecializationResolver $listSpecializationResolver = new QListSpecializationResolver(),
    ) {}

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     * @param array<string, string> $classHeaders
     */
    public function generate(
        string $headerPath,
        string $className,
        array $includePaths,
        array $allowedClasses = [],
        array $classHeaders = [],
        bool $preferExternalDependencyReasons = false,
        ?EnumHolderRegistry $enumRegistry = null,
    ): ClassGenerationResult
    {
        $facts = $this->prepareDiscoveryFacts($headerPath, $className, $includePaths);
        if (($facts['status'] ?? 'error') !== 'ok') {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                (string) ($facts['reason_code'] ?? 'class_filtered'),
                (string) ($facts['reason_message'] ?? 'Class is filtered.'),
            );
        }

        /** @var array<string, mixed> $classData */
        $classData = $facts['class_data'];
        $classData = $this->mergeInheritedTypeMetadata(
            $classData,
            function (string $baseClass) use ($headerPath, $includePaths, $classHeaders): ?array {
                $baseHeaderPath = $classHeaders[$baseClass] ?? $headerPath;
                $facts = $this->prepareDiscoveryFacts($baseHeaderPath, $baseClass, $includePaths);

                return (($facts['status'] ?? 'error') === 'ok' && is_array($facts['class_data'] ?? null))
                    ? $facts['class_data']
                    : null;
            },
        );
        $classData['is_qobject_derived'] = $this->isQObjectDerivedClassData(
            $classData,
            function (string $baseClass) use ($headerPath, $includePaths, $classHeaders): ?array {
                $baseHeaderPath = $classHeaders[$baseClass] ?? $headerPath;
                $facts = $this->prepareDiscoveryFacts($baseHeaderPath, $baseClass, $includePaths);

                return (($facts['status'] ?? 'error') === 'ok' && is_array($facts['class_data'] ?? null))
                    ? $facts['class_data']
                    : null;
            },
        );
        $sourceClassData = $classData;
        $allowedClasses = $this->augmentAllowedClassesWithSyntheticParents($classData, $allowedClasses, $headerPath, $className);
        $classData = $this->normalizeSupportedListBases($classData, $headerPath, $className);

        $parentClass = is_string($classData['bases'][0] ?? null) ? $classData['bases'][0] : null;
        if ($parentClass !== null && !in_array($parentClass, $allowedClasses, true)) {
            if ($this->canIgnoreUnavailableParent($parentClass)) {
                $classData['bases'] = array_values(array_filter(
                    (array) ($classData['bases'] ?? []),
                    static fn(mixed $base): bool => is_string($base) && $base !== $parentClass,
                ));
                $parentClass = null;
            } else {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                $preferExternalDependencyReasons ? 'unsupported_external_module_dependency' : 'unsupported_parent_class',
                $preferExternalDependencyReasons
                    ? sprintf('Parent class %s requires unavailable external module ABI.', $parentClass)
                    : sprintf('Parent class %s is not available for generation.', $parentClass),
            );
            }
        }

        $filtered = $this->methodPolicy->filter($classData, $allowedClasses, $preferExternalDependencyReasons, $enumRegistry);
        $filtered['selected_methods'] = $this->mergeInheritedWidgetEventMethods(
            $classData,
            $filtered['selected_methods'],
            function (string $baseClass) use ($headerPath, $includePaths, $allowedClasses, $classHeaders): ?array {
                $baseHeaderPath = $classHeaders[$baseClass] ?? $headerPath;

                return $this->loadMethodFilteredClassData(
                    $baseHeaderPath,
                    $baseClass,
                    $includePaths,
                    $allowedClasses,
                    $classHeaders,
                );
            },
        );
        $signalFilter = $this->filterSignalCallbackMethods(
            $filtered['selected_methods'],
            $includePaths,
            $classHeaders,
        );
        $virtualFilter = $this->filterVirtualOverrideMethods(
            $signalFilter['selected_methods'],
            $includePaths,
            $classHeaders,
        );
        $classData['signals'] = $this->collectSignalVariants(
            $className,
            $headerPath,
            $includePaths,
            $allowedClasses,
            $classHeaders,
            $virtualFilter['selected_methods'],
        );
        $classData['methods'] = array_values(array_filter(
            $virtualFilter['selected_methods'],
            static fn(array $method): bool => ($method['is_signal'] ?? false) !== true,
        ));

        $phpClass = $this->builder->build($classData);
        $inheritanceFiltered = $this->filterConflictingInheritedMethods(
            $phpClass,
            $headerPath,
            $includePaths,
            $allowedClasses,
            $classHeaders,
        );
        $phpClass = $inheritanceFiltered['class'];
        $skippedMethods = [...$filtered['skipped_methods'], ...$signalFilter['skipped_methods'], ...$virtualFilter['skipped_methods'], ...$inheritanceFiltered['skipped_methods']];
        $abstractConstructorAdjusted = $this->filterUnsupportedAbstractConstructors(
            $phpClass,
            $sourceClassData,
            function () use ($className, $sourceClassData, $headerPath, $includePaths, $allowedClasses, $classHeaders): array {
                /** @var array<string, true> $names */
                $names = [];
                $visited = [];

                foreach ((array) ($sourceClassData['bases'] ?? []) as $baseClass) {
                    if (!is_string($baseClass) || $baseClass === '' || $baseClass === $className) {
                        continue;
                    }

                    foreach ($this->collectInheritedPureVirtualRequirementNames(
                        $baseClass,
                        $headerPath,
                        $includePaths,
                        $allowedClasses,
                        $classHeaders,
                        $visited,
                    ) as $methodName) {
                        $names[$methodName] = true;
                    }
                }

                return array_keys($names);
            },
        );
        $phpClass = $abstractConstructorAdjusted['class'];
        $skippedMethods = [...$skippedMethods, ...$abstractConstructorAdjusted['skipped_methods']];
        $phpClass = $this->ensureProtectedUnavailableConstructor($phpClass, $sourceClassData);
        $phpClass = $this->stripQObjectRuntimeMethods($phpClass);

        if (!$this->shouldGenerateClassShell($phpClass, $classData)) {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                'no_supported_methods',
                'No supported methods remained after filtering.',
                $skippedMethods,
            );
        }

        return ClassGenerationResult::ok($className, $headerPath, $phpClass, $skippedMethods);
    }

    /**
     * @param list<string> $includePaths
     * @return array<string, mixed>
     */
    public function prepareDiscoveryFacts(string $headerPath, string $className, array $includePaths): array
    {
        $decision = $this->classPolicy->decideClassName($className);
        if (!$decision->accepted) {
            return [
                'status' => 'skipped',
                'class' => $className,
                'header' => $headerPath,
                'reason_code' => $decision->reasonCode ?? 'class_filtered',
                'reason_message' => $decision->reasonMessage ?? 'Class is filtered.',
            ];
        }

        if ($this->isTemplateClassDeclaration($headerPath, $className)) {
            return [
                'status' => 'skipped',
                'class' => $className,
                'header' => $headerPath,
                'reason_code' => 'template_class',
                'reason_message' => 'Template classes are skipped in the current build mode.',
            ];
        }

        $inspector = new QtClassInspector(new ClangArgumentBuilder($includePaths));
        $classData = $inspector->inspect($headerPath, $className);
        if ($classData === null) {
            return [
                'status' => 'skipped',
                'class' => $className,
                'header' => $headerPath,
                'reason_code' => 'class_not_found',
                'reason_message' => 'Class definition was not found in the parsed header.',
            ];
        }

        $lifecycle = $this->analyzeLifecycleCapabilities($headerPath, $className, (bool) ($classData['is_struct'] ?? false));
        $classData['is_copy_constructible'] = $lifecycle['is_copy_constructible'];
        $classData['has_public_constructor'] = $lifecycle['has_public_constructor'];
        $classData['has_public_default_constructor'] = $lifecycle['has_public_default_constructor'];
        $classData['has_public_destructor'] = $lifecycle['has_public_destructor'];
        $classData['flag_aliases'] = $this->discoverFlagAliases($headerPath, $className);
        $classData['enum_names'] = $this->discoverEnumNames($headerPath, $className);
        $classData['methods'] = $this->annotateConstructorVariants(
            is_array($classData['methods'] ?? null) ? $classData['methods'] : [],
            $headerPath,
            $className,
            (bool) ($classData['is_struct'] ?? false),
        );

        return [
            'status' => 'ok',
            'class' => $className,
            'header' => $headerPath,
            'class_data' => $classData,
        ];
    }

    /**
     * @param array<string, mixed> $classData
     * @param list<string> $allowedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     */
    public function generateFromPreparedData(
        array $classData,
        string $headerPath,
        array $allowedClasses = [],
        array $preparedClassDataByClass = [],
        bool $preferExternalDependencyReasons = false,
        ?EnumHolderRegistry $enumRegistry = null,
    ): ClassGenerationResult {
        $className = (string) ($classData['name'] ?? '');
        if ($className === '') {
            return ClassGenerationResult::skipped(
                '',
                $headerPath,
                'invalid_class_data',
                'Prepared class data is missing the class name.',
            );
        }
        $classData = $this->mergeInheritedTypeMetadata(
            $classData,
            static fn(string $baseClass): ?array => is_array($preparedClassDataByClass[$baseClass] ?? null)
                ? $preparedClassDataByClass[$baseClass]
                : null,
        );
        $classData['is_qobject_derived'] = $this->isQObjectDerivedClassData(
            $classData,
            static fn(string $baseClass): ?array => is_array($preparedClassDataByClass[$baseClass] ?? null)
                ? $preparedClassDataByClass[$baseClass]
                : null,
        );
        $sourceClassData = $classData;
        $allowedClasses = $this->augmentAllowedClassesWithSyntheticParents($classData, $allowedClasses, $headerPath, $className);
        $classData = $this->normalizeSupportedListBases($classData, $headerPath, $className);

        $parentClass = is_string($classData['bases'][0] ?? null) ? $classData['bases'][0] : null;
        if ($parentClass !== null && !in_array($parentClass, $allowedClasses, true)) {
            if ($this->canIgnoreUnavailableParent($parentClass)) {
                $classData['bases'] = array_values(array_filter(
                    (array) ($classData['bases'] ?? []),
                    static fn(mixed $base): bool => is_string($base) && $base !== $parentClass,
                ));
                $parentClass = null;
            } else {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                $preferExternalDependencyReasons ? 'unsupported_external_module_dependency' : 'unsupported_parent_class',
                $preferExternalDependencyReasons
                    ? sprintf('Parent class %s requires unavailable external module ABI.', $parentClass)
                    : sprintf('Parent class %s is not available for generation.', $parentClass),
            );
            }
        }

        $filtered = $this->methodPolicy->filter($classData, $allowedClasses, $preferExternalDependencyReasons, $enumRegistry);
        $filtered['selected_methods'] = $this->mergeInheritedWidgetEventMethods(
            $classData,
            $filtered['selected_methods'],
            function (string $baseClass) use ($preparedClassDataByClass, $allowedClasses, $preferExternalDependencyReasons, $enumRegistry): ?array {
                $baseClassData = $preparedClassDataByClass[$baseClass] ?? null;
                if (!is_array($baseClassData)) {
                    return null;
                }

                $filtered = $this->methodPolicy->filter(
                    $baseClassData,
                    $allowedClasses,
                    $preferExternalDependencyReasons,
                    $enumRegistry,
                );
                $baseClassData['selected_methods'] = $filtered['selected_methods'];

                return $baseClassData;
            },
        );
        $signalFilter = $this->filterSignalCallbackMethods(
            $filtered['selected_methods'],
            [],
            [],
            $preparedClassDataByClass,
        );
        $virtualFilter = $this->filterVirtualOverrideMethods(
            $signalFilter['selected_methods'],
            [],
            [],
            $preparedClassDataByClass,
        );
        $classData['signals'] = $this->collectSignalVariantsFromPrepared(
            $classData,
            $headerPath,
            $allowedClasses,
            $preparedClassDataByClass,
            $virtualFilter['selected_methods'],
        );
        $classData['methods'] = array_values(array_filter(
            $virtualFilter['selected_methods'],
            static fn(array $method): bool => ($method['is_signal'] ?? false) !== true,
        ));

        $phpClass = $this->builder->build($classData);
        $inheritanceFiltered = $this->filterConflictingInheritedMethodsFromPrepared(
            $phpClass,
            $headerPath,
            $allowedClasses,
            $preparedClassDataByClass,
        );
        $phpClass = $inheritanceFiltered['class'];
        $skippedMethods = [...$filtered['skipped_methods'], ...$signalFilter['skipped_methods'], ...$virtualFilter['skipped_methods'], ...$inheritanceFiltered['skipped_methods']];
        $abstractConstructorAdjusted = $this->filterUnsupportedAbstractConstructors(
            $phpClass,
            $sourceClassData,
            function () use ($className, $sourceClassData, $headerPath, $allowedClasses, $preparedClassDataByClass): array {
                /** @var array<string, true> $names */
                $names = [];
                $visited = [];

                foreach ((array) ($sourceClassData['bases'] ?? []) as $baseClass) {
                    if (!is_string($baseClass) || $baseClass === '' || $baseClass === $className) {
                        continue;
                    }

                    foreach ($this->collectInheritedPureVirtualRequirementNamesFromPrepared(
                        $baseClass,
                        $headerPath,
                        $allowedClasses,
                        $preparedClassDataByClass,
                        $visited,
                    ) as $methodName) {
                        $names[$methodName] = true;
                    }
                }

                return array_keys($names);
            },
        );
        $phpClass = $abstractConstructorAdjusted['class'];
        $skippedMethods = [...$skippedMethods, ...$abstractConstructorAdjusted['skipped_methods']];
        $phpClass = $this->ensureProtectedUnavailableConstructor($phpClass, $sourceClassData);
        $phpClass = $this->stripQObjectRuntimeMethods($phpClass);

        if (!$this->shouldGenerateClassShell($phpClass, $classData)) {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                'no_supported_methods',
                'No supported methods remained after filtering.',
                $skippedMethods,
            );
        }

        return ClassGenerationResult::ok($className, $headerPath, $phpClass, $skippedMethods);
    }

    /**
     * @param array<string, mixed> $classData
     */
    private function shouldGenerateClassShell(PhpClass $phpClass, array $classData): bool
    {
        if ($phpClass->isAbstract) {
            return true;
        }

        if ($phpClass->methods !== [] || $phpClass->signals !== [] || $phpClass->properties !== []) {
            return true;
        }

        $enumNames = is_array($classData['enum_names'] ?? null) ? $classData['enum_names'] : [];
        $flagAliases = is_array($classData['flag_aliases'] ?? null) ? $classData['flag_aliases'] : [];

        return $enumNames !== [] || $flagAliases !== [];
    }

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     * @param array<string, string> $classHeaders
     * @return array{class: PhpClass, skipped_methods: list<array<string, string>>}
     */
    private function filterConflictingInheritedMethods(
        PhpClass $phpClass,
        string $headerPath,
        array $includePaths,
        array $allowedClasses,
        array $classHeaders,
    ): array {
        $parentClass = $phpClass->parent;
        if ($parentClass === null || $parentClass === '' || $parentClass === $phpClass->name) {
            return ['class' => $phpClass, 'skipped_methods' => []];
        }

        $parentMethods = $this->collectInheritedMethods(
            $parentClass,
            $headerPath,
            $includePaths,
            $allowedClasses,
            $classHeaders,
        );
        if ($parentMethods === []) {
            return ['class' => $phpClass, 'skipped_methods' => []];
        }

        $methods = [];
        $skippedMethods = [];
        $usedMethodNames = [];

        foreach ($parentMethods as $parentMethod) {
            $usedMethodNames[$parentMethod->name] = true;
        }

        foreach ($phpClass->methods as $method) {
            $parentMethod = $parentMethods[$method->name] ?? null;
            if ($parentMethod !== null && $this->isCompatibleInheritedMethod($method, $parentMethod)) {
                $normalizedMethod = $this->normalizeAbstractMethodAgainstParent($method, $parentMethod);
                $methods[] = $normalizedMethod;
                $usedMethodNames[$normalizedMethod->name] = true;
                continue;
            }

            $canonicalParentMethod = $this->findCanonicalContractParentMethod($method, $parentMethods);
            if ($canonicalParentMethod !== null) {
                $normalizedMethod = $this->normalizeAbstractMethodAgainstParent(
                    $this->withMethodName($method, $canonicalParentMethod->name),
                    $canonicalParentMethod,
                );
                $methods[] = $normalizedMethod;
                $usedMethodNames[$normalizedMethod->name] = true;
                continue;
            }

            if ($parentMethod === null) {
                $methods[] = $method;
                $usedMethodNames[$method->name] = true;
                continue;
            }

            $renamedMethod = $this->renameConflictingInheritedMethod($method, $usedMethodNames);
            if ($renamedMethod !== null) {
                $methods[] = $renamedMethod;
                $usedMethodNames[$renamedMethod->name] = true;
                continue;
            }

            $skippedMethods[] = [
                'name' => $method->name,
                'reason_code' => 'incompatible_inherited_method',
                'reason_message' => sprintf(
                    'Method %s is skipped because its PHP signature is incompatible with an inherited %s() method.',
                    $method->name,
                    $parentMethod->name,
                ),
            ];
        }

        $methods = $this->normalizeMethodsAgainstInheritedContracts($methods, $parentMethods);

        return [
            'class' => new PhpClass(
                name: $phpClass->name,
                parent: $phpClass->parent,
                isAbstract: $phpClass->isAbstract,
                isCopyConstructible: $phpClass->isCopyConstructible,
                hasPublicConstructor: $phpClass->hasPublicConstructor,
                hasPublicDestructor: $phpClass->hasPublicDestructor,
                isQObjectDerived: $phpClass->isQObjectDerived,
                properties: $phpClass->properties,
                methods: $methods,
                signals: $phpClass->signals,
                classConstants: $phpClass->classConstants,
                nativeIncludes: $phpClass->nativeIncludes,
                nativeAliasOf: $phpClass->nativeAliasOf,
            ),
            'skipped_methods' => $skippedMethods,
        ];
    }

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     * @param array<string, string> $classHeaders
     * @param array<string, bool> $visited
     * @return array<string, PhpMethod>
     */
    private function collectInheritedMethods(
        string $className,
        string $fallbackHeaderPath,
        array $includePaths,
        array $allowedClasses,
        array $classHeaders,
        array &$visited = [],
    ): array {
        if (isset($visited[$className])) {
            return [];
        }

        $visited[$className] = true;
        $headerPath = $classHeaders[$className] ?? $fallbackHeaderPath;
        $result = $this->generate($headerPath, $className, $includePaths, $allowedClasses, $classHeaders);
        $phpClass = $result->phpClass;
        if ($result->status !== 'ok' || $phpClass === null) {
            return [];
        }

        $methods = [];
        if ($phpClass->parent !== null && $phpClass->parent !== '' && $phpClass->parent !== $className) {
            $methods = $this->collectInheritedMethods(
                $phpClass->parent,
                $headerPath,
                $includePaths,
                $allowedClasses,
                $classHeaders,
                $visited,
            );
        }

        foreach ($phpClass->methods as $method) {
            if ($method->name === '__construct') {
                continue;
            }

            $methods[$method->name] = $method;
        }

        return $methods;
    }

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     * @param array<string, string> $classHeaders
     * @param list<array<string, mixed>> $selectedMethods
     * @return list<array<string, mixed>>
     */
    private function collectSignalVariants(
        string $className,
        string $headerPath,
        array $includePaths,
        array $allowedClasses,
        array $classHeaders,
        array $selectedMethods,
    ): array {
        $signals = [];

        foreach ($selectedMethods as $method) {
            if (($method['is_signal'] ?? false) === true) {
                $signals[] = $method;
            }
        }

        $rawClassData = $this->loadFilteredClassData(
            $headerPath,
            $className,
            $includePaths,
            $allowedClasses,
            $classHeaders,
        );
        $parentClass = is_array($rawClassData)
            ? (is_string($rawClassData['bases'][0] ?? null) ? $rawClassData['bases'][0] : null)
            : null;

        if ($parentClass === null || $parentClass === '' || $parentClass === $className || !in_array($parentClass, $allowedClasses, true)) {
            return $signals;
        }

        $visited = [];
        $inheritedSignals = $this->collectInheritedSignalVariants(
            $parentClass,
            $headerPath,
            $includePaths,
            $allowedClasses,
            $classHeaders,
            $visited,
        );

        return [...$inheritedSignals, ...$signals];
    }

    /**
     * @param array<string, mixed> $classData
     * @param list<string> $allowedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param list<array<string, mixed>> $selectedMethods
     * @return list<array<string, mixed>>
     */
    private function collectSignalVariantsFromPrepared(
        array $classData,
        string $headerPath,
        array $allowedClasses,
        array $preparedClassDataByClass,
        array $selectedMethods,
    ): array {
        $signals = [];

        foreach ($selectedMethods as $method) {
            if (($method['is_signal'] ?? false) === true) {
                $signals[] = $method;
            }
        }

        $className = (string) ($classData['name'] ?? '');
        $parentClass = is_string($classData['bases'][0] ?? null) ? $classData['bases'][0] : null;
        if ($parentClass === null || $parentClass === '' || $parentClass === $className || !in_array($parentClass, $allowedClasses, true)) {
            return $signals;
        }

        $visited = [];
        $inheritedSignals = $this->collectInheritedSignalVariantsFromPrepared(
            $parentClass,
            $headerPath,
            $allowedClasses,
            $preparedClassDataByClass,
            $visited,
        );

        return [...$inheritedSignals, ...$signals];
    }

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     * @param array<string, string> $classHeaders
     * @param array<string, bool> $visited
     * @return list<array<string, mixed>>
     */
    private function collectInheritedSignalVariants(
        string $className,
        string $fallbackHeaderPath,
        array $includePaths,
        array $allowedClasses,
        array $classHeaders,
        array &$visited,
    ): array {
        if (isset($visited[$className])) {
            return [];
        }

        $visited[$className] = true;
        $headerPath = $classHeaders[$className] ?? $fallbackHeaderPath;
        $classData = $this->loadFilteredClassData($headerPath, $className, $includePaths, $allowedClasses, $classHeaders);
        if ($classData === null) {
            return [];
        }

        $signals = [];
        $parentClass = is_string($classData['bases'][0] ?? null) ? $classData['bases'][0] : null;
        if ($parentClass !== null && $parentClass !== '' && $parentClass !== $className && in_array($parentClass, $allowedClasses, true)) {
            $signals = $this->collectInheritedSignalVariants(
                $parentClass,
                $headerPath,
                $includePaths,
                $allowedClasses,
                $classHeaders,
                $visited,
            );
        }

        $signalFilter = $this->filterSignalCallbackMethods(
            $classData['selected_methods'],
            $includePaths,
            $classHeaders,
        );
        foreach ($signalFilter['selected_methods'] as $method) {
            if (($method['is_signal'] ?? false) === true) {
                $signals[] = $method;
            }
        }

        return $signals;
    }

    /**
     * @param list<string> $allowedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, bool> $visited
     * @return list<array<string, mixed>>
     */
    private function collectInheritedSignalVariantsFromPrepared(
        string $className,
        string $fallbackHeaderPath,
        array $allowedClasses,
        array $preparedClassDataByClass,
        array &$visited,
    ): array {
        if (isset($visited[$className])) {
            return [];
        }

        $visited[$className] = true;
        $classData = $preparedClassDataByClass[$className] ?? null;
        if (!is_array($classData)) {
            return [];
        }

        $signals = [];
        $parentClass = is_string($classData['bases'][0] ?? null) ? $classData['bases'][0] : null;
        if ($parentClass !== null && $parentClass !== '' && $parentClass !== $className && in_array($parentClass, $allowedClasses, true)) {
            $signals = $this->collectInheritedSignalVariantsFromPrepared(
                $parentClass,
                $fallbackHeaderPath,
                $allowedClasses,
                $preparedClassDataByClass,
                $visited,
            );
        }

        $filtered = $this->methodPolicy->filter($classData, $allowedClasses);
        $signalFilter = $this->filterSignalCallbackMethods(
            $filtered['selected_methods'],
            [],
            [],
            $preparedClassDataByClass,
        );
        foreach ($signalFilter['selected_methods'] as $method) {
            if (($method['is_signal'] ?? false) === true) {
                $signals[] = $method;
            }
        }

        return $signals;
    }

    /**
     * @param list<array<string, mixed>> $selectedMethods
     * @param list<string> $includePaths
     * @param array<string, string> $classHeaders
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @return array{selected_methods: list<array<string, mixed>>, skipped_methods: list<array<string, string>>}
     */
    private function filterSignalCallbackMethods(
        array $selectedMethods,
        array $includePaths = [],
        array $classHeaders = [],
        array $preparedClassDataByClass = [],
    ): array {
        $keptMethods = [];
        $skippedMethods = [];
        /** @var array<string, array<string, mixed>|null> $parameterClassFactsCache */
        $parameterClassFactsCache = [];

        foreach ($selectedMethods as $method) {
            if (($method['is_signal'] ?? false) !== true) {
                $keptMethods[] = $method;
                continue;
            }

            $unsupportedReason = $this->unsupportedSignalCallbackReason(
                $method,
                $includePaths,
                $classHeaders,
                $preparedClassDataByClass,
                $parameterClassFactsCache,
            );
            if ($unsupportedReason !== null) {
                $skippedMethods[] = [
                    'name' => (string) ($method['name'] ?? ''),
                    'reason_code' => $unsupportedReason['code'],
                    'reason_message' => $unsupportedReason['message'],
                ];
                continue;
            }

            $keptMethods[] = $method;
        }

        return [
            'selected_methods' => $keptMethods,
            'skipped_methods' => $skippedMethods,
        ];
    }

    /**
     * @param list<array<string, mixed>> $selectedMethods
     * @param list<string> $includePaths
     * @param array<string, string> $classHeaders
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @return array{selected_methods: list<array<string, mixed>>, skipped_methods: list<array<string, string>>}
     */
    private function filterVirtualOverrideMethods(
        array $selectedMethods,
        array $includePaths = [],
        array $classHeaders = [],
        array $preparedClassDataByClass = [],
    ): array {
        $keptMethods = [];
        $skippedMethods = [];
        /** @var array<string, array<string, mixed>|null> $parameterClassFactsCache */
        $parameterClassFactsCache = [];

        foreach ($selectedMethods as $method) {
            if (($method['is_final'] ?? false) === true) {
                $method['is_virtual'] = false;
                $method['is_pure_virtual'] = false;
                $keptMethods[] = $method;
                continue;
            }

            if (($method['is_virtual'] ?? false) !== true && ($method['is_pure_virtual'] ?? false) !== true) {
                $keptMethods[] = $method;
                continue;
            }

            $unsupportedReason = $this->unsupportedVirtualOverrideReason(
                $method,
                $includePaths,
                $classHeaders,
                $preparedClassDataByClass,
                $parameterClassFactsCache,
            );
            if ($unsupportedReason !== null) {
                $skippedMethods[] = [
                    'name' => (string) ($method['name'] ?? ''),
                    'reason_code' => $unsupportedReason['code'],
                    'reason_message' => $unsupportedReason['message'],
                ];
                continue;
            }

            $keptMethods[] = $method;
        }

        return [
            'selected_methods' => $keptMethods,
            'skipped_methods' => $skippedMethods,
        ];
    }

    /**
     * @param array<string, mixed> $method
     * @param list<string> $includePaths
     * @param array<string, string> $classHeaders
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, array<string, mixed>|null> $parameterClassFactsCache
     * @return array{code: string, message: string}|null
     */
    private function unsupportedSignalCallbackReason(
        array $method,
        array $includePaths,
        array $classHeaders,
        array $preparedClassDataByClass,
        array &$parameterClassFactsCache,
    ): ?array {
        $parameters = $this->effectiveSignalCallbackParameters($method);

        foreach ($parameters as $parameter) {
            $cppType = is_string($parameter['type'] ?? null) ? $parameter['type'] : '';
            if ($cppType === '') {
                return [
                    'code' => 'unsupported_signal_callback_parameter',
                    'message' => sprintf('Signal %s() has a parameter with an unknown native type.', (string) ($method['name'] ?? '')),
                ];
            }

            $phpType = $this->typeMapper->map($cppType);
            $strategy = $this->typeBridge->returnStrategyForCpp($phpType, $cppType);
            if (in_array($strategy, ['scalar', 'string', 'qobject_pointer'], true)) {
                continue;
            }

            if ($strategy !== 'value_object') {
                return [
                    'code' => 'unsupported_signal_callback_parameter',
                    'message' => sprintf('Signal %s() uses callback parameter type %s which is not supported.', (string) ($method['name'] ?? ''), $cppType),
                ];
            }

            $classFacts = $this->signalParameterClassFacts(
                $phpType,
                $includePaths,
                $classHeaders,
                $preparedClassDataByClass,
                $parameterClassFactsCache,
            );
            if ($classFacts === null) {
                return [
                    'code' => 'unsupported_signal_callback_parameter',
                    'message' => sprintf('Signal %s() uses callback parameter type %s which cannot be prepared as a PHP object safely.', (string) ($method['name'] ?? ''), $cppType),
                ];
            }

            if (!$this->isSignalParameterCopyable($classFacts, $phpType)) {
                return [
                    'code' => 'unsupported_signal_callback_parameter',
                    'message' => sprintf('Signal %s() uses callback parameter type %s which is not copy-constructible.', (string) ($method['name'] ?? ''), $cppType),
                ];
            }

            if (!(bool) ($classFacts['has_public_destructor'] ?? false)) {
                return [
                    'code' => 'unsupported_signal_callback_parameter',
                    'message' => sprintf('Signal %s() uses callback parameter type %s which does not have a public destructor.', (string) ($method['name'] ?? ''), $cppType),
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $method
     * @return list<array<string, mixed>>
     */
    private function effectiveSignalCallbackParameters(array $method): array
    {
        $parameters = is_array($method['parameters'] ?? null) ? $method['parameters'] : [];
        if (($method['is_signal'] ?? false) !== true || $parameters === []) {
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
     * @param array<string, mixed> $method
     * @param list<string> $includePaths
     * @param array<string, string> $classHeaders
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, array<string, mixed>|null> $parameterClassFactsCache
     * @return array{code: string, message: string}|null
     */
    private function unsupportedVirtualOverrideReason(
        array $method,
        array $includePaths,
        array $classHeaders,
        array $preparedClassDataByClass,
        array &$parameterClassFactsCache,
    ): ?array {
        $methodName = (string) ($method['name'] ?? '');
        $returnType = is_string($method['return_type'] ?? null) ? $method['return_type'] : 'void';
        $mappedReturnType = $this->typeMapper->map($returnType);
        $returnStrategy = $this->typeBridge->returnStrategyForCpp($mappedReturnType, $returnType);
        if ($returnStrategy === 'array') {
            if (!$this->typeBridge->isSupportedContainerType($returnType)) {
                return [
                    'code' => 'unsupported_virtual_override_signature',
                    'message' => sprintf('Virtual method %s() return type %s is not supported for PHP overrides.', $methodName, $returnType),
                ];
            }
        } elseif ($returnStrategy === 'value_object') {
            $unsupportedReturnReason = $this->unsupportedVirtualValueObjectReason(
                $mappedReturnType,
                $returnType,
                $methodName,
                $includePaths,
                $classHeaders,
                $preparedClassDataByClass,
                $parameterClassFactsCache,
            );
            if ($unsupportedReturnReason !== null) {
                return $unsupportedReturnReason;
            }
        } elseif (!$this->isSupportedVirtualReturnStrategy($returnStrategy, $returnType)) {
            return [
                'code' => 'unsupported_virtual_override_signature',
                'message' => sprintf('Virtual method %s() return type %s is not supported for PHP overrides.', $methodName, $returnType),
            ];
        }

        $parameters = is_array($method['parameters'] ?? null) ? $method['parameters'] : [];
        foreach ($parameters as $parameter) {
            $cppType = is_string($parameter['type'] ?? null) ? $parameter['type'] : '';
            if ($cppType === '') {
                return [
                    'code' => 'unsupported_virtual_override_signature',
                    'message' => sprintf('Virtual method %s() has a parameter with an unknown native type.', $methodName),
                ];
            }

            if ($this->isWritableReferenceType($cppType)) {
                return [
                    'code' => 'unsupported_virtual_override_signature',
                    'message' => sprintf('Virtual method %s() uses writable reference parameter type %s which is not supported for PHP overrides.', $methodName, $cppType),
                ];
            }

            $phpType = $this->typeMapper->map($cppType);
            $strategy = $this->typeBridge->returnStrategyForCpp($phpType, $cppType);
            if ($strategy === 'array') {
                if (!$this->typeBridge->isSupportedContainerType($cppType)) {
                    return [
                        'code' => 'unsupported_virtual_override_signature',
                        'message' => sprintf('Virtual method %s() uses parameter type %s which is not supported for PHP overrides.', $methodName, $cppType),
                    ];
                }

                continue;
            }

            if (!$this->isSupportedVirtualParameterStrategy($strategy, $cppType)) {
                return [
                    'code' => 'unsupported_virtual_override_signature',
                    'message' => sprintf('Virtual method %s() uses parameter type %s which is not supported for PHP overrides.', $methodName, $cppType),
                ];
            }

            if ($strategy !== 'value_object') {
                continue;
            }

            $unsupportedParameterReason = $this->unsupportedVirtualValueObjectReason(
                $phpType,
                $cppType,
                $methodName,
                $includePaths,
                $classHeaders,
                $preparedClassDataByClass,
                $parameterClassFactsCache,
            );
            if ($unsupportedParameterReason !== null) {
                return $unsupportedParameterReason;
            }
        }

        return null;
    }

    /**
     * @param list<string> $includePaths
     * @param array<string, string> $classHeaders
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, array<string, mixed>|null> $parameterClassFactsCache
     * @return array<string, mixed>|null
     */
    private function signalParameterClassFacts(
        string $phpType,
        array $includePaths,
        array $classHeaders,
        array $preparedClassDataByClass,
        array &$parameterClassFactsCache,
    ): ?array {
        if (isset($preparedClassDataByClass[$phpType]) && is_array($preparedClassDataByClass[$phpType])) {
            return $preparedClassDataByClass[$phpType];
        }

        if (array_key_exists($phpType, $parameterClassFactsCache)) {
            return $parameterClassFactsCache[$phpType];
        }

        $headerPath = $classHeaders[$phpType] ?? null;
        if (!is_string($headerPath) || $headerPath === '') {
            $parameterClassFactsCache[$phpType] = null;

            return null;
        }

        $facts = $this->prepareDiscoveryFacts($headerPath, $phpType, $includePaths);
        if (($facts['status'] ?? 'error') !== 'ok' || !is_array($facts['class_data'] ?? null)) {
            $parameterClassFactsCache[$phpType] = null;

            return null;
        }

        $parameterClassFactsCache[$phpType] = $facts['class_data'];

        return $parameterClassFactsCache[$phpType];
    }

    /**
     * @param array<string, mixed> $classFacts
     */
    private function isSignalParameterCopyable(array $classFacts, string $className): bool
    {
        if (!(bool) ($classFacts['is_copy_constructible'] ?? false)) {
            return false;
        }

        $methods = is_array($classFacts['methods'] ?? null) ? $classFacts['methods'] : [];
        foreach ($methods as $method) {
            if (!is_array($method) || ($method['name'] ?? null) !== $className) {
                continue;
            }

            $parameters = is_array($method['parameters'] ?? null) ? $method['parameters'] : [];
            if (count($parameters) !== 1) {
                continue;
            }

            $parameterType = is_string($parameters[0]['type'] ?? null) ? $parameters[0]['type'] : '';
            if ($parameterType === '' || !str_contains($parameterType, '&')) {
                continue;
            }

            if ($this->normalizeSignalParameterClassName($parameterType) !== $className) {
                continue;
            }

            if (($method['is_deleted'] ?? false) === true) {
                return false;
            }

            return ($method['access'] ?? 'private') === 'public';
        }

        return true;
    }

    private function unsupportedVirtualValueObjectReason(
        string $phpType,
        string $cppType,
        string $methodName,
        array $includePaths,
        array $classHeaders,
        array $preparedClassDataByClass,
        array &$parameterClassFactsCache,
    ): ?array {
        $classFacts = $this->signalParameterClassFacts(
            $phpType,
            $includePaths,
            $classHeaders,
            $preparedClassDataByClass,
            $parameterClassFactsCache,
        );

        if ($classFacts === null) {
            if ($this->typeBridge->isValueType($phpType)) {
                return null;
            }

            return [
                'code' => 'unsupported_virtual_override_signature',
                'message' => sprintf('Virtual method %s() uses %s type %s which cannot be prepared safely.', $methodName, str_contains($cppType, '&') || str_contains($cppType, '*') ? 'parameter' : 'return', $cppType),
            ];
        }

        if (!$this->isSignalParameterCopyable($classFacts, $phpType)) {
            return [
                'code' => 'unsupported_virtual_override_signature',
                'message' => sprintf('Virtual method %s() uses %s type %s which is not copy-constructible.', $methodName, str_contains($cppType, '&') || str_contains($cppType, '*') ? 'parameter' : 'return', $cppType),
            ];
        }

        if (!(bool) ($classFacts['has_public_destructor'] ?? false)) {
            return [
                'code' => 'unsupported_virtual_override_signature',
                'message' => sprintf('Virtual method %s() uses %s type %s which does not have a public destructor.', $methodName, str_contains($cppType, '&') || str_contains($cppType, '*') ? 'parameter' : 'return', $cppType),
            ];
        }

        return null;
    }

    private function isSupportedVirtualReturnStrategy(string $strategy, string $cppType): bool
    {
        if ($strategy === 'array') {
            return $this->typeBridge->isSupportedContainerType($cppType);
        }

        return in_array($strategy, ['void', 'scalar', 'string', 'qobject_pointer'], true);
    }

    private function isSupportedVirtualParameterStrategy(string $strategy, string $cppType): bool
    {
        if ($strategy === 'array') {
            return $this->typeBridge->isSupportedContainerType($cppType);
        }

        return in_array($strategy, ['scalar', 'string', 'value_object', 'qobject_pointer'], true);
    }

    private function isWritableReferenceType(string $cppType): bool
    {
        $trimmed = trim($cppType);

        return str_contains($trimmed, '&') && preg_match('/^\s*const\b/', $trimmed) !== 1;
    }

    private function normalizeSignalParameterClassName(string $cppType): string
    {
        $type = trim($cppType);
        $type = preg_replace('/\bconst\b/', '', $type) ?? $type;
        $type = trim(preg_replace('/\s+/', ' ', $type) ?? $type);
        $type = rtrim($type, '& ');

        while (str_ends_with($type, '*')) {
            $type = rtrim(substr($type, 0, -1));
        }

        return trim($type);
    }

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     * @param array<string, string> $classHeaders
     * @return array{name: string, is_abstract: bool, is_copy_constructible?: bool, has_public_destructor?: bool, is_struct: bool, bases: list<string>, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>, selected_methods: list<array<string, mixed>>}|null
     */
    private function loadFilteredClassData(
        string $headerPath,
        string $className,
        array $includePaths,
        array $allowedClasses,
        array $classHeaders,
    ): ?array {
        $decision = $this->classPolicy->decideClassName($className);
        if (!$decision->accepted) {
            return null;
        }

        if ($this->isTemplateClassDeclaration($headerPath, $className)) {
            return null;
        }

        $inspector = new QtClassInspector(new ClangArgumentBuilder($includePaths));
        $classData = $inspector->inspect($headerPath, $className);
        if ($classData === null) {
            return null;
        }

        $lifecycle = $this->analyzeLifecycleCapabilities($headerPath, $className, (bool) ($classData['is_struct'] ?? false));
        $classData['is_copy_constructible'] = $lifecycle['is_copy_constructible'];
        $classData['has_public_constructor'] = $lifecycle['has_public_constructor'];
        $classData['has_public_default_constructor'] = $lifecycle['has_public_default_constructor'];
        $classData['has_public_destructor'] = $lifecycle['has_public_destructor'];
        $classData['flag_aliases'] = $this->discoverFlagAliases($headerPath, $className);
        $classData['enum_names'] = $this->discoverEnumNames($headerPath, $className);
        $classData['methods'] = $this->annotateConstructorVariants(
            is_array($classData['methods'] ?? null) ? $classData['methods'] : [],
            $headerPath,
            $className,
            (bool) ($classData['is_struct'] ?? false),
        );
        $classData = $this->mergeInheritedTypeMetadata(
            $classData,
            function (string $baseClass) use ($headerPath, $includePaths, $classHeaders): ?array {
                $baseHeaderPath = $classHeaders[$baseClass] ?? $headerPath;
                $facts = $this->prepareDiscoveryFacts($baseHeaderPath, $baseClass, $includePaths);

                return (($facts['status'] ?? 'error') === 'ok' && is_array($facts['class_data'] ?? null))
                    ? $facts['class_data']
                    : null;
            },
        );

        $parentClass = is_string($classData['bases'][0] ?? null) ? $classData['bases'][0] : null;
        if ($parentClass !== null && !in_array($parentClass, $allowedClasses, true)) {
            if ($this->canIgnoreUnavailableParent($parentClass)) {
                $classData['bases'] = array_values(array_filter(
                    (array) ($classData['bases'] ?? []),
                    static fn(mixed $base): bool => is_string($base) && $base !== $parentClass,
                ));
            } else {
                return null;
            }
        }

        $filtered = $this->methodPolicy->filter($classData, $allowedClasses);
        $classData['selected_methods'] = $filtered['selected_methods'];

        return $classData;
    }

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     * @param array<string, string> $classHeaders
     * @return array{name: string, is_abstract: bool, is_copy_constructible?: bool, has_public_destructor?: bool, is_struct: bool, bases: list<string>, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>, selected_methods: list<array<string, mixed>>}|null
     */
    private function loadMethodFilteredClassData(
        string $headerPath,
        string $className,
        array $includePaths,
        array $allowedClasses,
        array $classHeaders,
    ): ?array {
        $decision = $this->classPolicy->decideClassName($className);
        if (!$decision->accepted) {
            return null;
        }

        if ($this->isTemplateClassDeclaration($headerPath, $className)) {
            return null;
        }

        $facts = $this->prepareDiscoveryFacts($headerPath, $className, $includePaths);
        if (($facts['status'] ?? 'error') !== 'ok' || !is_array($facts['class_data'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $classData */
        $classData = $facts['class_data'];
        $classData = $this->mergeInheritedTypeMetadata(
            $classData,
            function (string $baseClass) use ($headerPath, $includePaths, $classHeaders): ?array {
                $baseHeaderPath = $classHeaders[$baseClass] ?? $headerPath;
                $facts = $this->prepareDiscoveryFacts($baseHeaderPath, $baseClass, $includePaths);

                return (($facts['status'] ?? 'error') === 'ok' && is_array($facts['class_data'] ?? null))
                    ? $facts['class_data']
                    : null;
            },
        );
        $classData['selected_methods'] = $this->methodPolicy->filter($classData, $allowedClasses)['selected_methods'];

        return $classData;
    }

    private function canIgnoreUnavailableParent(string $parentClass): bool
    {
        return $parentClass === 'QIODeviceBase';
    }

    /**
     * @param array<string, mixed> $classData
     * @param list<array<string, mixed>> $selectedMethods
     * @param callable(string): ?array<string, mixed> $baseClassLoader
     * @return list<array<string, mixed>>
     */
    private function mergeInheritedWidgetEventMethods(
        array $classData,
        array $selectedMethods,
        callable $baseClassLoader,
    ): array {
        // Some QWidget subclasses, notably QOpenGLWidget, rely on inherited input
        // virtuals being present on the generated native trampoline even when the
        // subclass header does not redeclare them. Keep this narrowly scoped to
        // the widget event allowlist instead of widening protected inheritance
        // exposure in general.
        if (!$this->isWidgetDescendantClassData($classData, $baseClassLoader)) {
            return $selectedMethods;
        }

        $declaredMethodNames = [];
        foreach ((array) ($classData['methods'] ?? []) as $method) {
            $methodName = is_string($method['name'] ?? null) ? $method['name'] : '';
            if ($methodName !== '') {
                $declaredMethodNames[$methodName] = true;
            }
        }

        $selectedMethodNames = [];
        foreach ($selectedMethods as $method) {
            $methodName = is_string($method['name'] ?? null) ? $method['name'] : '';
            if ($methodName !== '') {
                $selectedMethodNames[$methodName] = true;
            }
        }

        $visited = [];
        foreach ($this->collectInheritedWidgetEventMethodVariants($classData, $baseClassLoader, $visited) as $method) {
            $methodName = is_string($method['name'] ?? null) ? $method['name'] : '';
            if ($methodName === '') {
                continue;
            }

            if (isset($declaredMethodNames[$methodName]) || isset($selectedMethodNames[$methodName])) {
                continue;
            }

            $selectedMethods[] = $method;
            $selectedMethodNames[$methodName] = true;
        }

        return $selectedMethods;
    }

    /**
     * @param array<string, mixed> $classData
     * @param callable(string): ?array<string, mixed> $baseClassLoader
     * @param array<string, bool> $visited
     * @return array<string, mixed>
     */
    private function mergeInheritedTypeMetadata(
        array $classData,
        callable $baseClassLoader,
        array &$visited = [],
    ): array {
        $className = is_string($classData['name'] ?? null) ? $classData['name'] : '';
        if ($className !== '') {
            if (isset($visited[$className])) {
                return $classData;
            }

            $visited[$className] = true;
        }

        /** @var list<string> $enumNames */
        $enumNames = is_array($classData['enum_names'] ?? null)
            ? array_values(array_filter(array_map(
                static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                $classData['enum_names'],
            ), static fn(string $value): bool => $value !== ''))
            : [];
        /** @var array<string, string> $flagAliases */
        $flagAliases = is_array($classData['flag_aliases'] ?? null)
            ? array_filter(
                array_map(static fn(mixed $value): string => is_string($value) ? trim($value) : '', $classData['flag_aliases']),
                static fn(string $value): bool => $value !== '',
            )
            : [];

        foreach ((array) ($classData['bases'] ?? []) as $baseClass) {
            if (!is_string($baseClass) || $baseClass === '' || $baseClass === $className) {
                continue;
            }

            $baseClassData = $baseClassLoader($baseClass);
            if (!is_array($baseClassData)) {
                continue;
            }

            $baseVisited = $visited;
            $baseClassData = $this->mergeInheritedTypeMetadata($baseClassData, $baseClassLoader, $baseVisited);

            foreach ((array) ($baseClassData['enum_names'] ?? []) as $enumName) {
                if (!is_string($enumName) || trim($enumName) === '') {
                    continue;
                }

                $enumNames[] = trim($enumName);
            }

            foreach ((array) ($baseClassData['flag_aliases'] ?? []) as $flagName => $enumType) {
                if (!is_string($flagName) || $flagName === '' || !is_string($enumType) || trim($enumType) === '') {
                    continue;
                }

                $flagAliases[$flagName] ??= trim($enumType);
            }
        }

        $classData['enum_names'] = array_values(array_unique($enumNames));
        $classData['flag_aliases'] = $flagAliases;

        return $classData;
    }

    /**
     * @param array<string, mixed> $classData
     * @param callable(string): ?array<string, mixed> $baseClassLoader
     * @param array<string, bool> $visited
     * @return list<array<string, mixed>>
     */
    private function collectInheritedWidgetEventMethodVariants(
        array $classData,
        callable $baseClassLoader,
        array &$visited = [],
    ): array {
        $className = is_string($classData['name'] ?? null) ? $classData['name'] : '';
        if ($className !== '') {
            if (isset($visited[$className])) {
                return [];
            }

            $visited[$className] = true;
        }

        $methods = [];

        foreach ((array) ($classData['bases'] ?? []) as $baseClass) {
            if (!is_string($baseClass) || $baseClass === '' || $baseClass === $className) {
                continue;
            }

            $baseClassData = $baseClassLoader($baseClass);
            if (!is_array($baseClassData)) {
                continue;
            }

            $methods = [
                ...$methods,
                ...$this->collectInheritedWidgetEventMethodVariants($baseClassData, $baseClassLoader, $visited),
            ];

            foreach ((array) ($baseClassData['selected_methods'] ?? []) as $method) {
                $methodName = is_string($method['name'] ?? null) ? $method['name'] : '';
                if (in_array($methodName, self::WIDGET_INHERITED_EVENT_METHODS, true)) {
                    $methods[] = $method;
                }
            }
        }

        return $methods;
    }

    /**
     * @param array<string, mixed> $classData
     * @param callable(string): ?array<string, mixed> $baseClassLoader
     * @param array<string, bool> $visited
     */
    private function isWidgetDescendantClassData(
        array $classData,
        callable $baseClassLoader,
        array &$visited = [],
    ): bool {
        $className = is_string($classData['name'] ?? null) ? $classData['name'] : '';
        if ($className === 'QWidget') {
            return true;
        }

        if ($className !== '') {
            if (isset($visited[$className])) {
                return false;
            }

            $visited[$className] = true;
        }

        foreach ((array) ($classData['bases'] ?? []) as $baseClass) {
            if (!is_string($baseClass) || $baseClass === '' || $baseClass === $className) {
                continue;
            }

            if ($baseClass === 'QWidget') {
                return true;
            }

            $baseClassData = $baseClassLoader($baseClass);
            if (is_array($baseClassData) && $this->isWidgetDescendantClassData($baseClassData, $baseClassLoader, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $allowedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @return array{class: PhpClass, skipped_methods: list<array<string, string>>}
     */
    private function filterConflictingInheritedMethodsFromPrepared(
        PhpClass $phpClass,
        string $headerPath,
        array $allowedClasses,
        array $preparedClassDataByClass,
    ): array {
        $parentClass = $phpClass->parent;
        if ($parentClass === null || $parentClass === '' || $parentClass === $phpClass->name) {
            return ['class' => $phpClass, 'skipped_methods' => []];
        }

        $parentMethods = $this->collectInheritedMethodsFromPrepared(
            $parentClass,
            $headerPath,
            $allowedClasses,
            $preparedClassDataByClass,
        );
        if ($parentMethods === []) {
            return ['class' => $phpClass, 'skipped_methods' => []];
        }

        $methods = [];
        $skippedMethods = [];
        $usedMethodNames = [];

        foreach ($parentMethods as $parentMethod) {
            $usedMethodNames[$parentMethod->name] = true;
        }

        foreach ($phpClass->methods as $method) {
            $parentMethod = $parentMethods[$method->name] ?? null;
            if ($parentMethod !== null && $this->isCompatibleInheritedMethod($method, $parentMethod)) {
                $normalizedMethod = $this->normalizeAbstractMethodAgainstParent($method, $parentMethod);
                $methods[] = $normalizedMethod;
                $usedMethodNames[$normalizedMethod->name] = true;
                continue;
            }

            $canonicalParentMethod = $this->findCanonicalContractParentMethod($method, $parentMethods);
            if ($canonicalParentMethod !== null) {
                $normalizedMethod = $this->normalizeAbstractMethodAgainstParent(
                    $this->withMethodName($method, $canonicalParentMethod->name),
                    $canonicalParentMethod,
                );
                $methods[] = $normalizedMethod;
                $usedMethodNames[$normalizedMethod->name] = true;
                continue;
            }

            if ($parentMethod === null) {
                $methods[] = $method;
                $usedMethodNames[$method->name] = true;
                continue;
            }

            $renamedMethod = $this->renameConflictingInheritedMethod($method, $usedMethodNames);
            if ($renamedMethod !== null) {
                $methods[] = $renamedMethod;
                $usedMethodNames[$renamedMethod->name] = true;
                continue;
            }

            $skippedMethods[] = [
                'name' => $method->name,
                'reason_code' => 'incompatible_inherited_method',
                'reason_message' => sprintf(
                    'Method %s is skipped because its PHP signature is incompatible with an inherited %s() method.',
                    $method->name,
                    $parentMethod->name,
                ),
            ];
        }

        $methods = $this->normalizeMethodsAgainstInheritedContracts($methods, $parentMethods);

        return [
            'class' => new PhpClass(
                name: $phpClass->name,
                parent: $phpClass->parent,
                isAbstract: $phpClass->isAbstract,
                isCopyConstructible: $phpClass->isCopyConstructible,
                hasPublicConstructor: $phpClass->hasPublicConstructor,
                hasPublicDestructor: $phpClass->hasPublicDestructor,
                isQObjectDerived: $phpClass->isQObjectDerived,
                properties: $phpClass->properties,
                methods: $methods,
                signals: $phpClass->signals,
                classConstants: $phpClass->classConstants,
                nativeIncludes: $phpClass->nativeIncludes,
                nativeAliasOf: $phpClass->nativeAliasOf,
            ),
            'skipped_methods' => $skippedMethods,
        ];
    }

    /**
     * @param list<string> $allowedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, bool> $visited
     * @return array<string, PhpMethod>
     */
    private function collectInheritedMethodsFromPrepared(
        string $className,
        string $fallbackHeaderPath,
        array $allowedClasses,
        array $preparedClassDataByClass,
        array &$visited = [],
    ): array {
        if (isset($visited[$className])) {
            return [];
        }

        $visited[$className] = true;
        $classData = $preparedClassDataByClass[$className] ?? null;
        if (!is_array($classData)) {
            return [];
        }

        $result = $this->generateFromPreparedData(
            $classData,
            $fallbackHeaderPath,
            $allowedClasses,
            $preparedClassDataByClass,
        );
        $phpClass = $result->phpClass;
        if ($result->status !== 'ok' || $phpClass === null) {
            return [];
        }

        $methods = [];
        if ($phpClass->parent !== null && $phpClass->parent !== '' && $phpClass->parent !== $className) {
            $methods = $this->collectInheritedMethodsFromPrepared(
                $phpClass->parent,
                $fallbackHeaderPath,
                $allowedClasses,
                $preparedClassDataByClass,
                $visited,
            );
        }

        foreach ($phpClass->methods as $method) {
            if ($method->name === '__construct') {
                continue;
            }

            $methods[$method->name] = $method;
        }

        return $methods;
    }

    private function isCompatibleInheritedMethod(PhpMethod $child, PhpMethod $parent): bool
    {
        if ($child->isStatic !== $parent->isStatic) {
            return false;
        }

        if ($parent->access === 'public' && $child->access !== 'public' && !$parent->isAbstractMethod) {
            return false;
        }

        if (count($child->parameters) < count($parent->parameters)) {
            return false;
        }

        if ($this->requiredParameterCount($child) > $this->requiredParameterCount($parent) && !$parent->isAbstractMethod) {
            return false;
        }

        foreach ($parent->parameters as $index => $parentParameter) {
            $childParameter = $child->parameters[$index] ?? null;
            if ($childParameter === null) {
                return false;
            }

            if (
                !$this->isParentParameterTypeAcceptedByChild($parentParameter->phpType, $childParameter->phpType)
                && !$parent->isAbstractMethod
            ) {
                return false;
            }

            if ($parentParameter->hasDefault && !$childParameter->hasDefault && !$parent->isAbstractMethod) {
                return false;
            }
        }

        for ($index = count($parent->parameters); $index < count($child->parameters); $index++) {
            if (!($child->parameters[$index]->hasDefault ?? false)) {
                return false;
            }
        }

        if (
            !$this->isChildReturnTypeCompatibleWithParent($child->returnType, $parent->returnType)
            && !$parent->isAbstractMethod
        ) {
            return false;
        }

        return true;
    }

    private function normalizeAbstractMethodAgainstParent(PhpMethod $method, ?PhpMethod $parentMethod): PhpMethod
    {
        if ($parentMethod === null) {
            return $method;
        }

        $isAbstractMethod = $method->isAbstractMethod;
        if ($isAbstractMethod && !$parentMethod->isAbstractMethod) {
            $isAbstractMethod = false;
        }

        $access = $method->access;
        if ($parentMethod->isAbstractMethod && $parentMethod->access === 'public' && $access !== 'public') {
            $access = 'public';
        }

        $parameters = $method->parameters;
        if ($parentMethod->isAbstractMethod) {
            foreach ($parentMethod->parameters as $index => $parentParameter) {
                $childParameter = $parameters[$index] ?? null;
                if ($childParameter === null) {
                    continue;
                }

                $normalizedType = $this->mergeParentTypesIntoChild($parentParameter->phpType, $childParameter->phpType);
                $normalizedDefault = $childParameter->hasDefault || $parentParameter->hasDefault;
                if (
                    $normalizedType === $childParameter->phpType
                    && $normalizedDefault === $childParameter->hasDefault
                ) {
                    continue;
                }

                $parameters[$index] = new PhpParameter(
                    name: $childParameter->name,
                    phpType: $normalizedType,
                    hasDefault: $normalizedDefault,
                    position: $childParameter->position,
                    isByRef: $childParameter->isByRef,
                    isNullableByRef: $childParameter->isNullableByRef,
                );
            }

            for ($index = count($parentMethod->parameters); $index < count($parameters); $index++) {
                $childParameter = $parameters[$index] ?? null;
                if ($childParameter === null || $childParameter->hasDefault) {
                    continue;
                }

                $parameters[$index] = new PhpParameter(
                    name: $childParameter->name,
                    phpType: $childParameter->phpType,
                    hasDefault: true,
                    position: $childParameter->position,
                    isByRef: $childParameter->isByRef,
                    isNullableByRef: $childParameter->isNullableByRef,
                );
            }
        }

        $returnType = $method->returnType;
        if (
            $parentMethod->isAbstractMethod
            && !$this->isChildReturnTypeCompatibleWithParent($returnType, $parentMethod->returnType)
        ) {
            $returnType = $parentMethod->returnType;
        }

        if (
            $isAbstractMethod === $method->isAbstractMethod
            && $access === $method->access
            && $parameters === $method->parameters
            && $returnType === $method->returnType
        ) {
            return $method;
        }

        return new PhpMethod(
            name: $method->name,
            access: $access,
            isStatic: $method->isStatic,
            isSignal: $method->isSignal,
            isSlot: $method->isSlot,
            isAbstractMethod: $isAbstractMethod,
            returnType: $returnType,
            parameters: $parameters,
            overloads: $method->overloads,
            cppName: $method->cppName,
        );
    }

    /**
     * @param list<PhpMethod> $methods
     * @param array<string, PhpMethod> $parentMethods
     * @return list<PhpMethod>
     */
    private function normalizeMethodsAgainstInheritedContracts(array $methods, array $parentMethods): array
    {
        $normalized = [];

        foreach ($methods as $method) {
            if ($method->name === '__construct') {
                $normalized[] = $method;
                continue;
            }

            $parentMethod = $parentMethods[$method->name] ?? $this->findCanonicalContractParentMethod($method, $parentMethods);
            $normalized[] = $this->normalizeAbstractMethodAgainstParent($method, $parentMethod);
        }

        return $normalized;
    }

    /**
     * @param array<string, PhpMethod> $parentMethods
     */
    private function findCanonicalContractParentMethod(PhpMethod $method, array $parentMethods): ?PhpMethod
    {
        $baseName = $method->cppName ?? $method->name;
        $signatureSuffix = $this->methodSignatureSuffix($method);
        $candidateNames = array_values(array_unique(array_filter([
            $baseName,
            $signatureSuffix !== '' ? $baseName . $signatureSuffix : null,
            $method->name !== $baseName ? $method->name : null,
        ], static fn(?string $name): bool => is_string($name) && $name !== '')));

        foreach ($candidateNames as $candidateName) {
            $candidate = $parentMethods[$candidateName] ?? null;
            if ($candidate === null || !$candidate->isAbstractMethod) {
                continue;
            }

            if (!$this->isCompatibleInheritedMethod($method, $candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function withMethodName(PhpMethod $method, string $newName): PhpMethod
    {
        if ($newName === $method->name) {
            return $method;
        }

        return new PhpMethod(
            name: $newName,
            access: $method->access,
            isStatic: $method->isStatic,
            isSignal: $method->isSignal,
            isSlot: $method->isSlot,
            isAbstractMethod: $method->isAbstractMethod,
            returnType: $method->returnType,
            parameters: $method->parameters,
            overloads: $method->overloads,
            cppName: $method->cppName,
        );
    }

    private function isParentParameterTypeAcceptedByChild(string $parentType, string $childType): bool
    {
        $parentParts = $this->normalizeUnionTypeParts($parentType);
        $childParts = $this->normalizeUnionTypeParts($childType);

        if ($childParts === ['mixed'] || $parentParts === []) {
            return true;
        }

        if ($childParts === []) {
            return false;
        }

        foreach ($parentParts as $part) {
            if (!in_array($part, $childParts, true)) {
                return false;
            }
        }

        return true;
    }

    private function isChildReturnTypeCompatibleWithParent(string $childType, string $parentType): bool
    {
        $childParts = $this->normalizeUnionTypeParts($childType);
        $parentParts = $this->normalizeUnionTypeParts($parentType);

        if ($parentParts === ['mixed'] || $childParts === []) {
            return true;
        }

        if ($parentParts === []) {
            return $childParts === [];
        }

        foreach ($childParts as $part) {
            if (!in_array($part, $parentParts, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function normalizeUnionTypeParts(string $type): array
    {
        $parts = array_values(array_filter(
            array_map(static fn(string $part): string => trim($part), explode('|', $type)),
            static fn(string $part): bool => $part !== '',
        ));

        if ($parts === []) {
            return [];
        }

        sort($parts);

        return array_values(array_unique($parts));
    }

    private function mergeParentTypesIntoChild(string $parentType, string $childType): string
    {
        $parts = array_values(array_unique(array_merge(
            $this->normalizeUnionTypeParts($parentType),
            $this->normalizeUnionTypeParts($childType),
        )));

        return implode('|', $parts);
    }

    /**
     * @param array<string, bool> $usedMethodNames
     */
    private function renameConflictingInheritedMethod(PhpMethod $method, array $usedMethodNames): ?PhpMethod
    {
        if ($method->name === '__construct') {
            return null;
        }

        $baseName = $method->name . $this->methodSignatureSuffix($method);
        if ($baseName === $method->name) {
            $baseName .= 'As' . $this->typeSuffix($method->returnType);
        }

        $candidate = $baseName;
        if (isset($usedMethodNames[$candidate])) {
            $candidate .= 'As' . $this->typeSuffix($method->returnType);
        }

        $counter = 2;
        while (isset($usedMethodNames[$candidate])) {
            $candidate = $baseName . $counter;
            $counter++;
        }

        if ($candidate === $method->name) {
            return null;
        }

        return new PhpMethod(
            name: $candidate,
            access: $method->access,
            isStatic: $method->isStatic,
            isSignal: $method->isSignal,
            isSlot: $method->isSlot,
            isAbstractMethod: $method->isAbstractMethod,
            returnType: $method->returnType,
            parameters: $method->parameters,
            overloads: $method->overloads,
            cppName: $method->cppName,
        );
    }

    private function methodSignatureSuffix(PhpMethod $method): string
    {
        $parts = [];
        foreach ($method->parameters as $parameter) {
            if ($parameter->phpType === '' || $parameter->phpType === 'null') {
                continue;
            }

            $parts[] = $this->typeSuffix($parameter->phpType);
        }

        return implode('', array_values(array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    private function typeSuffix(string $phpType): string
    {
        $parts = array_values(array_filter(
            explode('|', $phpType),
            static fn(string $part): bool => $part !== '' && $part !== 'null',
        ));

        if ($parts === []) {
            return 'Value';
        }

        $suffixes = array_map(
            fn(string $part): string => $this->singleTypeSuffix($part),
            $parts,
        );

        return implode('Or', array_values(array_filter($suffixes, static fn(string $part): bool => $part !== '')));
    }

    private function singleTypeSuffix(string $phpType): string
    {
        return match ($phpType) {
            'int' => 'Int',
            'float' => 'Float',
            'bool' => 'Bool',
            'string' => 'String',
            'array' => 'Array',
            'mixed' => 'Mixed',
            default => $this->normalizedObjectTypeSuffix($phpType),
        };
    }

    private function normalizedObjectTypeSuffix(string $phpType): string
    {
        $type = ltrim($phpType, '\\');
        if (str_contains($type, '\\')) {
            $parts = explode('\\', $type);
            $type = (string) end($parts);
        }

        if (preg_match('/^Q[A-Z]/', $type) === 1) {
            return substr($type, 1);
        }

        return ucfirst($type);
    }

    /**
     * @param callable(): list<string> $inheritedPureVirtualCollector
     * @return array{class: PhpClass, skipped_methods: list<array<string, string>>}
     */
    private function filterUnsupportedAbstractConstructors(
        PhpClass $phpClass,
        array $classData,
        callable $inheritedPureVirtualCollector,
    ): array {
        if (!$phpClass->isAbstract) {
            return ['class' => $phpClass, 'skipped_methods' => []];
        }

        $hasConstructor = false;
        foreach ($phpClass->methods as $method) {
            if ($method->name === '__construct') {
                $hasConstructor = true;
                break;
            }
        }

        if (!$hasConstructor) {
            return ['class' => $phpClass, 'skipped_methods' => []];
        }

        if ($this->canInstantiateAbstractSubclass($phpClass, $classData, $inheritedPureVirtualCollector)) {
            return ['class' => $phpClass, 'skipped_methods' => []];
        }

        $methods = array_values(array_filter(
            $phpClass->methods,
            static fn(PhpMethod $method): bool => $method->name !== '__construct',
        ));

        return [
            'class' => new PhpClass(
                name: $phpClass->name,
                parent: $phpClass->parent,
                isAbstract: $phpClass->isAbstract,
                isCopyConstructible: $phpClass->isCopyConstructible,
                hasPublicConstructor: $phpClass->hasPublicConstructor,
                hasPublicDestructor: $phpClass->hasPublicDestructor,
                isQObjectDerived: $phpClass->isQObjectDerived,
                properties: $phpClass->properties,
                methods: $methods,
                signals: $phpClass->signals,
                classConstants: $phpClass->classConstants,
                nativeIncludes: $phpClass->nativeIncludes,
                nativeAliasOf: $phpClass->nativeAliasOf,
            ),
            'skipped_methods' => [[
                'name' => '__construct',
                'reason_code' => 'unsupported_abstract_subclass_constructor',
                'reason_message' => 'Abstract class constructors are only exposed when the generated native subclass can satisfy all pure virtual requirements.',
            ]],
        ];
    }

    /**
     * Keep internally-owned, non-instantiable classes concrete in PHP while still
     * preventing direct construction from userland.
     *
     * @param array<string, mixed> $classData
     */
    private function ensureProtectedUnavailableConstructor(PhpClass $phpClass, array $classData): PhpClass
    {
        if ($phpClass->isAbstract) {
            return $phpClass;
        }

        $hasUsableSurface = $phpClass->methods !== []
            || $phpClass->signals !== []
            || $phpClass->properties !== []
            || $phpClass->classConstants !== [];
        if (!$hasUsableSurface) {
            return $phpClass;
        }

        if ((bool) ($classData['has_public_constructor'] ?? true)) {
            return $phpClass;
        }
        if (!(bool) ($classData['has_public_destructor'] ?? true)) {
            return $phpClass;
        }

        foreach ($phpClass->methods as $method) {
            if ($method->name === '__construct') {
                return $phpClass;
            }
        }

        $methods = $phpClass->methods;
        array_unshift($methods, new PhpMethod(
            name: '__construct',
            access: 'protected',
            isStatic: false,
            isSignal: false,
            isSlot: false,
            isAbstractMethod: false,
            returnType: 'void',
            parameters: [],
            overloads: [],
            cppName: '__construct',
        ));

        return new PhpClass(
            name: $phpClass->name,
            parent: $phpClass->parent,
            isAbstract: $phpClass->isAbstract,
            isCopyConstructible: $phpClass->isCopyConstructible,
            hasPublicConstructor: $phpClass->hasPublicConstructor,
            hasPublicDestructor: $phpClass->hasPublicDestructor,
            isQObjectDerived: $phpClass->isQObjectDerived,
            properties: $phpClass->properties,
            methods: $methods,
            signals: $phpClass->signals,
            classConstants: $phpClass->classConstants,
            nativeIncludes: $phpClass->nativeIncludes,
            nativeAliasOf: $phpClass->nativeAliasOf,
        );
    }

    /**
     * @param callable(): list<string> $inheritedPureVirtualCollector
     */
    private function canInstantiateAbstractSubclass(
        PhpClass $phpClass,
        array $classData,
        callable $inheritedPureVirtualCollector,
    ): bool {
        /** @var array<string, PhpMethod> $generatedMethodsByName */
        $generatedMethodsByName = [];
        foreach ($phpClass->methods as $method) {
            if ($method->name === '__construct') {
                continue;
            }

            $generatedMethodsByName[$method->cppName ?? $method->name] = $method;
        }

        $requiresPureVirtualCoverage = false;
        foreach ($this->declaredPureVirtualMethodNames($classData) as $methodName) {
            $requiresPureVirtualCoverage = true;
            if (!isset($generatedMethodsByName[$methodName])) {
                return false;
            }
        }

        foreach ($inheritedPureVirtualCollector() as $methodName) {
            $requiresPureVirtualCoverage = true;
            if (!isset($generatedMethodsByName[$methodName])) {
                return false;
            }
        }

        return $requiresPureVirtualCoverage;
    }

    /**
     * @return list<string>
     */
    private function declaredPureVirtualMethodNames(array $classData): array
    {
        $className = (string) ($classData['name'] ?? '');
        $methods = is_array($classData['methods'] ?? null) ? $classData['methods'] : [];
        $names = [];

        foreach ($methods as $method) {
            if (!is_array($method) || ($method['is_pure_virtual'] ?? false) !== true) {
                continue;
            }

            $declaringClass = is_string($method['declaring_class'] ?? null)
                ? (string) $method['declaring_class']
                : $className;
            if ($declaringClass !== '' && $className !== '' && $declaringClass !== $className) {
                continue;
            }

            $methodName = is_string($method['name'] ?? null) ? (string) $method['name'] : '';
            if ($methodName === '' || $methodName === $className) {
                continue;
            }

            $names[$methodName] = true;
        }

        return array_keys($names);
    }

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     * @param array<string, string> $classHeaders
     * @param array<string, bool> $visited
     * @return list<string>
     */
    private function collectInheritedPureVirtualRequirementNames(
        string $className,
        string $fallbackHeaderPath,
        array $includePaths,
        array $allowedClasses,
        array $classHeaders,
        array &$visited = [],
    ): array {
        if (isset($visited[$className])) {
            return [];
        }

        $visited[$className] = true;
        $headerPath = $classHeaders[$className] ?? $fallbackHeaderPath;
        $facts = $this->prepareDiscoveryFacts($headerPath, $className, $includePaths);
        if (($facts['status'] ?? 'error') !== 'ok' || !is_array($facts['class_data'] ?? null)) {
            return [];
        }

        /** @var array<string, mixed> $classData */
        $classData = $facts['class_data'];
        /** @var array<string, true> $names */
        $names = [];

        foreach ($this->declaredPureVirtualMethodNames($classData) as $methodName) {
            $names[$methodName] = true;
        }

        foreach ((array) ($classData['bases'] ?? []) as $baseClass) {
            if (!is_string($baseClass) || $baseClass === '' || $baseClass === $className || !in_array($baseClass, $allowedClasses, true)) {
                continue;
            }

            foreach ($this->collectInheritedPureVirtualRequirementNames(
                $baseClass,
                $headerPath,
                $includePaths,
                $allowedClasses,
                $classHeaders,
                $visited,
            ) as $methodName) {
                $names[$methodName] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param list<string> $allowedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, bool> $visited
     * @return list<string>
     */
    private function collectInheritedPureVirtualRequirementNamesFromPrepared(
        string $className,
        string $fallbackHeaderPath,
        array $allowedClasses,
        array $preparedClassDataByClass,
        array &$visited = [],
    ): array {
        if (isset($visited[$className])) {
            return [];
        }

        $visited[$className] = true;
        $classData = $preparedClassDataByClass[$className] ?? null;
        if (!is_array($classData)) {
            return [];
        }

        /** @var array<string, true> $names */
        $names = [];
        foreach ($this->declaredPureVirtualMethodNames($classData) as $methodName) {
            $names[$methodName] = true;
        }

        foreach ((array) ($classData['bases'] ?? []) as $baseClass) {
            if (!is_string($baseClass) || $baseClass === '' || $baseClass === $className || !in_array($baseClass, $allowedClasses, true)) {
                continue;
            }

            foreach ($this->collectInheritedPureVirtualRequirementNamesFromPrepared(
                $baseClass,
                $fallbackHeaderPath,
                $allowedClasses,
                $preparedClassDataByClass,
                $visited,
            ) as $methodName) {
                $names[$methodName] = true;
            }
        }

        return array_keys($names);
    }

    private function requiredParameterCount(PhpMethod $method): int
    {
        $count = 0;

        foreach ($method->parameters as $parameter) {
            if ($parameter->hasDefault) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return array{
     *   is_copy_constructible: bool,
     *   has_public_constructor: bool,
     *   has_public_default_constructor: bool,
     *   has_public_destructor: bool
     * }
     */
    private function analyzeLifecycleCapabilities(string $headerPath, string $className, bool $isStruct): array
    {
        $resolved = $this->resolveClassDefinitionSource($headerPath, $className);
        if ($resolved === null) {
            return [
                'is_copy_constructible' => true,
                'has_public_constructor' => true,
                'has_public_default_constructor' => true,
                'has_public_destructor' => true,
            ];
        }

        $sourceContents = $resolved['contents'];
        $classBody = is_array($resolved['body'] ?? null) && is_string($resolved['body']['body'] ?? null)
            ? $resolved['body']['body']
            : null;
        $defaultAccess = $resolved['body'] !== null && $resolved['body']['kind'] === 'struct'
            ? 'public'
            : ($isStruct ? 'public' : 'private');
        $lifecycleSegments = $classBody !== null
            ? $this->topLevelClassSegments($classBody, $defaultAccess)
            : $this->lifecycleAccessBlocks($sourceContents);

        $hasExplicitConstructor = false;
        $hasPublicConstructor = false;
        $hasPublicDefaultConstructor = false;
        $hasExplicitDestructor = false;
        $hasPublicDestructor = true;
        $isCopyConstructible = !$this->containsCopyDisablingMacro($classBody ?? $sourceContents, $className);

        foreach ($lifecycleSegments as $segmentInfo) {
            $access = $segmentInfo['access'];
            $segment = $segmentInfo['segment'];

            foreach ($this->constructorSignatures($segment, $className) as $constructorSignature) {
                $hasExplicitConstructor = true;

                if ($this->isCopyConstructorSignature($constructorSignature, $className)) {
                    if ($access !== 'public') {
                        $isCopyConstructible = false;
                    }
                    continue;
                }

                if ($this->isMoveConstructorSignature($constructorSignature, $className)) {
                    continue;
                }

                if ($access === 'public') {
                    $hasPublicConstructor = true;
                    if ($this->isDefaultConstructorSignature($constructorSignature)) {
                        $hasPublicDefaultConstructor = true;
                    }
                }
            }

            if ($this->containsDestructorSignature($segment, $className)
                || $this->containsDefaultedRo5LifecycleMacro($segment, $className)) {
                $hasExplicitDestructor = true;
                if ($access !== 'public') {
                    $hasPublicDestructor = false;
                }
            }
        }

        if (!$hasExplicitConstructor) {
            // Implicitly-declared special members are public even for `class`.
            // Keep this optimistic unless an explicit constructor says otherwise.
            $hasPublicConstructor = true;
            $hasPublicDefaultConstructor = true;
        }

        if (!$hasExplicitDestructor) {
            $hasPublicDestructor = true;
        }

        return [
            'is_copy_constructible' => $isCopyConstructible,
            'has_public_constructor' => $hasPublicConstructor,
            'has_public_default_constructor' => $hasPublicDefaultConstructor,
            'has_public_destructor' => $hasPublicDestructor,
        ];
    }

    /**
     * @param list<array<string, mixed>> $methods
     * @return list<array<string, mixed>>
     */
    private function annotateConstructorVariants(array $methods, string $headerPath, string $className, bool $isStruct): array
    {
        $resolved = $this->resolveClassDefinitionSource($headerPath, $className);
        if ($resolved === null) {
            return $methods;
        }

        $classBody = is_array($resolved['body'] ?? null) && is_string($resolved['body']['body'] ?? null)
            ? $resolved['body']['body']
            : null;
        $defaultAccess = $resolved['body'] !== null && $resolved['body']['kind'] === 'struct'
            ? 'public'
            : ($isStruct ? 'public' : 'private');
        $segments = $classBody !== null
            ? $this->topLevelClassSegments($classBody, $defaultAccess)
            : $this->lifecycleAccessBlocks($resolved['contents']);
        $metadata = $this->constructorVariantMetadata($segments, $className);
        if ($metadata === []) {
            return $methods;
        }

        foreach ($methods as &$method) {
            if (($method['name'] ?? null) !== $className) {
                continue;
            }

            $key = $this->methodVariantKey($method);
            $variantMetadata = $metadata[$key] ?? null;
            if ($variantMetadata === null) {
                continue;
            }

            $method['access'] = $variantMetadata['access'];
            $method['is_deleted'] = $variantMetadata['is_deleted'];
        }
        unset($method);

        return $methods;
    }

    /**
     * @return array{path: string, contents: string, body: array{kind: string, body: string}|null}|null
     */
    private function resolveClassDefinitionSource(string $headerPath, string $className): ?array
    {
        $queue = [$headerPath];
        $visited = [];
        $fallback = null;

        while ($queue !== []) {
            $path = array_shift($queue);
            if (!is_string($path) || $path === '' || isset($visited[$path])) {
                continue;
            }
            $visited[$path] = true;

            $contents = @file_get_contents($path);
            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $body = $this->extractClassBody($contents, $className);
            if ($body !== null) {
                return [
                    'path' => $path,
                    'contents' => $contents,
                    'body' => $body,
                ];
            }

            if (preg_match('/\b' . preg_quote($className, '/') . '\b/', $contents) === 1 && $fallback === null) {
                $fallback = [
                    'path' => $path,
                    'contents' => $contents,
                    'body' => null,
                ];
            }

            foreach ($this->resolveIncludedHeaders($path, $contents) as $includePath) {
                if (!isset($visited[$includePath])) {
                    $queue[] = $includePath;
                }
            }
        }

        return $fallback;
    }

    /**
     * @return list<string>
     */
    private function resolveIncludedHeaders(string $headerPath, string $contents): array
    {
        $matchCount = preg_match_all('/^\s*#\s*include\s*[<"]([^">]+)[">]/m', $contents, $matches);
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        $candidates = [];
        foreach ($matches[1] as $include) {
            if (!is_string($include) || $include === '') {
                continue;
            }

            foreach ($this->candidateIncludePaths($headerPath, $include) as $candidate) {
                $real = realpath($candidate);
                if ($real !== false && is_file($real)) {
                    $candidates[] = $real;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return list<string>
     */
    private function candidateIncludePaths(string $headerPath, string $include): array
    {
        $paths = [];
        $headerDir = dirname($headerPath);
        $basename = basename($include);

        $paths[] = $headerDir . '/' . $include;
        $paths[] = $headerDir . '/' . $basename;

        if (preg_match('~^(.*?/QtCore\\.framework/Headers)(?:/.*)?$~', $headerPath, $matches) === 1) {
            $frameworkRoot = $matches[1];
            $paths[] = $frameworkRoot . '/' . $include;
            $paths[] = $frameworkRoot . '/' . $basename;

            if (str_starts_with($include, 'QtCore/')) {
                $paths[] = $frameworkRoot . '/' . substr($include, strlen('QtCore/'));
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array{kind: string, body: string}|null
     */
    private function extractClassBody(string $contents, string $className): ?array
    {
        $pattern = sprintf(
            '/(?:^|\n)\s*(class|struct)\s+(?:[A-Za-z_][A-Za-z0-9_]*\s+)*%s\b(?:\s*:[^{]+)?\s*\{/s',
            preg_quote($className, '/'),
        );

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $kind = is_string($matches[1][0] ?? null) ? $matches[1][0] : 'class';
        $matchText = is_string($matches[0][0] ?? null) ? $matches[0][0] : null;
        $matchOffset = is_int($matches[0][1] ?? null) ? $matches[0][1] : null;
        if ($matchText === null || $matchOffset === null) {
            return null;
        }

        $braceOffset = strpos($matchText, '{');
        if ($braceOffset === false) {
            return null;
        }

        $bodyStart = $matchOffset + $braceOffset + 1;
        $depth = 1;
        $length = strlen($contents);

        for ($index = $bodyStart; $index < $length; $index++) {
            $char = $contents[$index];

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return [
                        'kind' => $kind,
                        'body' => substr($contents, $bodyStart, $index - $bodyStart),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{access: string, segment: string}>
     */
    private function topLevelClassSegments(string $body, string $defaultAccess): array
    {
        $segments = [];
        $access = $defaultAccess;
        $buffer = '';
        $braceDepth = 0;

        foreach (preg_split("/(\r?\n)/", $body) ?: [] as $line) {
            if ($braceDepth === 0 && preg_match('/^\s*(public|protected|private)\s*:\s*(.*)$/', $line, $matches) === 1) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $segments[] = ['access' => $access, 'segment' => $trimmed];
                    $buffer = '';
                }

                $access = $matches[1];
                $remainder = trim((string) ($matches[2] ?? ''));
                if ($remainder !== '') {
                    $buffer .= $remainder . "\n";
                }
                continue;
            }

            $buffer .= $line . "\n";
            $braceDepth += substr_count($line, '{');
            $braceDepth -= substr_count($line, '}');

            if ($braceDepth === 0) {
                $trimmed = trim($buffer);
                if ($trimmed !== '' && (str_contains($trimmed, ';') || str_ends_with($trimmed, '}'))) {
                    $segments[] = ['access' => $access, 'segment' => $trimmed];
                    $buffer = '';
                }
            }
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $segments[] = ['access' => $access, 'segment' => $trimmed];
        }

        return $segments;
    }

    private function containsCopyDisablingMacro(string $segment, string $className): bool
    {
        return preg_match(
            '/Q(?:_EVENT)?_DISABLE_COPY(?:_MOVE)?\(\s*' . preg_quote($className, '/') . '\s*\)/',
            $segment,
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function constructorSignatures(string $segment, string $className): array
    {
        $matchCount = preg_match_all(
            '/(?<!~)\b' . preg_quote($className, '/') . '\s*\((.*?)\)\s*(?:noexcept\b[^;{]*)?(?:=\s*(?:default|delete)\s*)?(?:;|\{)/s',
            $segment,
            $matches,
        );
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $value): string => is_string($value) ? trim($value) : '',
            $matches[0],
        ), static fn(string $value): bool => $value !== ''));
    }

    /**
     * @param list<array{access: string, segment: string}> $segments
     * @return array<string, array{access: string, is_deleted: bool}>
     */
    private function constructorVariantMetadata(array $segments, string $className): array
    {
        $metadata = [];

        foreach ($segments as $segmentInfo) {
            $access = $segmentInfo['access'];
            $segment = $segmentInfo['segment'];

            foreach ($this->constructorDeclarations($segment, $className) as $declaration) {
                $key = $this->parameterSignatureKey($declaration['parameters']);
                if ($key === null || isset($metadata[$key])) {
                    continue;
                }

                $metadata[$key] = [
                    'access' => $access,
                    'is_deleted' => $declaration['is_deleted'],
                ];
            }
        }

        return $metadata;
    }

    /**
     * @return list<array{parameters: list<string>, is_deleted: bool}>
     */
    private function constructorDeclarations(string $segment, string $className): array
    {
        $matchCount = preg_match_all(
            '/(?<!~)\b' . preg_quote($className, '/') . '\s*\((.*?)\)\s*([^;{]*)\s*(?:;|\{)/s',
            $segment,
            $matches,
            PREG_SET_ORDER,
        );
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        $declarations = [];
        foreach ($matches as $match) {
            $parameterList = is_string($match[1] ?? null) ? $match[1] : '';
            $suffix = is_string($match[2] ?? null) ? $match[2] : '';

            $declarations[] = [
                'parameters' => $this->normalizeParameterDeclarations($parameterList),
                'is_deleted' => str_contains($suffix, '= delete') || str_contains($suffix, 'Q_DECL_EQ_DELETE'),
            ];
        }

        return $declarations;
    }

    /**
     * @param array<string, mixed> $method
     */
    private function methodVariantKey(array $method): ?string
    {
        $parameters = is_array($method['parameters'] ?? null) ? $method['parameters'] : [];
        $types = [];

        foreach ($parameters as $parameter) {
            $type = is_string($parameter['type'] ?? null) ? $parameter['type'] : '';
            if ($type === '') {
                return null;
            }

            $types[] = $this->normalizeSignatureType($type);
        }

        return $this->parameterSignatureKey($types);
    }

    /**
     * @return list<string>
     */
    private function normalizeParameterDeclarations(string $parameterList): array
    {
        $parameterList = trim($parameterList);
        if ($parameterList === '') {
            return [];
        }

        $parameters = [];
        foreach ($this->splitTopLevelParameterList($parameterList) as $parameter) {
            $parameter = $this->stripTopLevelDefaultValue($parameter);
            $parameter = $this->stripParameterName($parameter);
            if ($parameter === '') {
                continue;
            }

            $parameters[] = $this->normalizeSignatureType($parameter);
        }

        return $parameters;
    }

    /**
     * @param list<string> $types
     */
    private function parameterSignatureKey(array $types): ?string
    {
        return implode('|', $types);
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelParameterList(string $parameterList): array
    {
        $parts = [];
        $buffer = '';
        $angleDepth = 0;
        $parenDepth = 0;
        $braceDepth = 0;
        $bracketDepth = 0;
        $length = strlen($parameterList);

        for ($index = 0; $index < $length; $index++) {
            $char = $parameterList[$index];

            switch ($char) {
                case '<':
                    $angleDepth++;
                    break;
                case '>':
                    $angleDepth = max(0, $angleDepth - 1);
                    break;
                case '(':
                    $parenDepth++;
                    break;
                case ')':
                    $parenDepth = max(0, $parenDepth - 1);
                    break;
                case '{':
                    $braceDepth++;
                    break;
                case '}':
                    $braceDepth = max(0, $braceDepth - 1);
                    break;
                case '[':
                    $bracketDepth++;
                    break;
                case ']':
                    $bracketDepth = max(0, $bracketDepth - 1);
                    break;
                case ',':
                    if ($angleDepth === 0 && $parenDepth === 0 && $braceDepth === 0 && $bracketDepth === 0) {
                        $trimmed = trim($buffer);
                        if ($trimmed !== '') {
                            $parts[] = $trimmed;
                        }
                        $buffer = '';
                        continue 2;
                    }
                    break;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $parts[] = $trimmed;
        }

        return $parts;
    }

    private function stripTopLevelDefaultValue(string $parameter): string
    {
        $angleDepth = 0;
        $parenDepth = 0;
        $braceDepth = 0;
        $bracketDepth = 0;
        $length = strlen($parameter);

        for ($index = 0; $index < $length; $index++) {
            $char = $parameter[$index];

            switch ($char) {
                case '<':
                    $angleDepth++;
                    break;
                case '>':
                    $angleDepth = max(0, $angleDepth - 1);
                    break;
                case '(':
                    $parenDepth++;
                    break;
                case ')':
                    $parenDepth = max(0, $parenDepth - 1);
                    break;
                case '{':
                    $braceDepth++;
                    break;
                case '}':
                    $braceDepth = max(0, $braceDepth - 1);
                    break;
                case '[':
                    $bracketDepth++;
                    break;
                case ']':
                    $bracketDepth = max(0, $bracketDepth - 1);
                    break;
                case '=':
                    if ($angleDepth === 0 && $parenDepth === 0 && $braceDepth === 0 && $bracketDepth === 0) {
                        return trim(substr($parameter, 0, $index));
                    }
                    break;
            }
        }

        return trim($parameter);
    }

    private function stripParameterName(string $parameter): string
    {
        if (preg_match('/^(.*(?:\*|&|&&))([A-Za-z_][A-Za-z0-9_]*)$/', $parameter, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        if (preg_match('/^(.*\S)\s+([A-Za-z_][A-Za-z0-9_]*)$/', $parameter, $matches) !== 1) {
            return trim($parameter);
        }

        return trim((string) $matches[1]);
    }

    private function normalizeSignatureType(string $type): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', trim($type)) ?? trim($type));
        $normalized = preg_replace('/\s*([*&])\s*/', '$1', $normalized) ?? $normalized;

        return $normalized;
    }

    private function containsDestructorSignature(string $segment, string $className): bool
    {
        return preg_match('/~\s*' . preg_quote($className, '/') . '\s*\(/', $segment) === 1;
    }

    private function containsDefaultedRo5LifecycleMacro(string $segment, string $className): bool
    {
        return preg_match(
            '/\bQT_DECLARE_RO5_SMF_AS_DEFAULTED\s*\(\s*' . preg_quote($className, '/') . '\s*\)/',
            $segment,
        ) === 1;
    }

    private function isCopyConstructorSignature(string $signature, string $className): bool
    {
        return preg_match(
            '/\b' . preg_quote($className, '/') . '\s*\(\s*const\s+' . preg_quote($className, '/') . '\s*&(?:\s+[A-Za-z_][A-Za-z0-9_]*)?\s*\)/',
            $signature,
        ) === 1;
    }

    private function isMoveConstructorSignature(string $signature, string $className): bool
    {
        return preg_match(
            '/\b' . preg_quote($className, '/') . '\s*\(\s*' . preg_quote($className, '/') . '\s*&&(?:\s+[A-Za-z_][A-Za-z0-9_]*)?\s*\)/',
            $signature,
        ) === 1;
    }

    private function isDefaultConstructorSignature(string $signature): bool
    {
        return preg_match('/\(\s*\)/', $signature) === 1;
    }

    /**
     * @return list<array{access: string, segment: string}>
     */
    private function lifecycleAccessBlocks(string $contents): array
    {
        $matchCount = preg_match_all(
            '/\b(public|protected|private)\s*:\s*(.*?)(?=\b(?:public|protected|private)\s*:|\z)/s',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        $blocks = [];
        foreach ($matches as $match) {
            $access = is_string($match[1] ?? null) ? trim($match[1]) : '';
            $segment = is_string($match[2] ?? null) ? trim($match[2]) : '';
            if ($access === '' || $segment === '') {
                continue;
            }
            $blocks[] = [
                'access' => $access,
                'segment' => $segment,
            ];
        }

        return $blocks;
    }

    private function isTemplateClassDeclaration(string $headerPath, string $className): bool
    {
        $contents = @file_get_contents($headerPath);
        if (!is_string($contents) || $contents === '') {
            return false;
        }

        $pattern = sprintf(
            '/template\s*<[\s\S]*?>\s*(?:class|struct)\s+(?:[A-Za-z_][A-Za-z0-9_]*\s+)*%s\b/s',
            preg_quote($className, '/'),
        );

        return preg_match($pattern, $contents) === 1;
    }

    /**
     * @return array<string, string>
     */
    private function discoverFlagAliases(string $headerPath, ?string $className = null): array
    {
        $contents = $this->introspectionContents($headerPath, $className);
        if ($contents === '') {
            return [];
        }

        $matchCount = preg_match_all(
            '/Q_DECLARE_FLAGS\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*,\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)/',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        $aliases = [];
        foreach ($matches as $match) {
            $aliases[$match[1]] = $match[2];
        }

        return $aliases;
    }

    /**
     * @return list<string>
     */
    private function discoverEnumNames(string $headerPath, ?string $className = null): array
    {
        $contents = $this->introspectionContents($headerPath, $className);
        if ($contents === '') {
            return [];
        }

        $matchCount = preg_match_all(
            '/enum(?:\s+class)?\s+([A-Za-z_][A-Za-z0-9_]*)\b/',
            $contents,
            $matches,
        );
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        $names = array_values(array_unique(array_filter(
            array_map(static fn(mixed $value): string => is_string($value) ? trim($value) : '', $matches[1] ?? []),
            static fn(string $value): bool => $value !== '',
        )));

        return $names;
    }

    /**
     * @param array<string, mixed> $classData
     * @param callable(string): ?array<string, mixed> $baseLoader
     * @param array<string, bool> $visited
     */
    private function isQObjectDerivedClassData(array $classData, callable $baseLoader, array &$visited = []): bool
    {
        $className = is_string($classData['name'] ?? null) ? $classData['name'] : '';
        if ($className === 'QObject') {
            return true;
        }

        foreach ((array) ($classData['bases'] ?? []) as $baseClass) {
            if (!is_string($baseClass) || $baseClass === '') {
                continue;
            }

            if ($baseClass === 'QObject') {
                return true;
            }

            if (isset($visited[$baseClass])) {
                continue;
            }
            $visited[$baseClass] = true;

            $baseClassData = $baseLoader($baseClass);
            if (is_array($baseClassData) && $this->isQObjectDerivedClassData($baseClassData, $baseLoader, $visited)) {
                return true;
            }
        }

        return false;
    }

    private function stripQObjectRuntimeMethods(PhpClass $phpClass): PhpClass
    {
        if ($phpClass->name !== 'QObject') {
            return $phpClass;
        }

        $methods = array_values(array_filter(
            $phpClass->methods,
            static fn(PhpMethod $method): bool => !in_array($method->name, ['property', 'setProperty'], true),
        ));

        return new PhpClass(
            name: $phpClass->name,
            parent: $phpClass->parent,
            isAbstract: $phpClass->isAbstract,
            isCopyConstructible: $phpClass->isCopyConstructible,
            hasPublicConstructor: $phpClass->hasPublicConstructor,
            hasPublicDestructor: $phpClass->hasPublicDestructor,
            properties: $phpClass->properties,
            methods: $methods,
            signals: $phpClass->signals,
            isQObjectDerived: $phpClass->isQObjectDerived,
            classConstants: $phpClass->classConstants,
            nativeIncludes: $phpClass->nativeIncludes,
            nativeAliasOf: $phpClass->nativeAliasOf,
        );
    }

    /**
     * @param list<string> $allowedClasses
     * @return list<string>
     */
    private function augmentAllowedClassesWithSyntheticParents(array $classData, array $allowedClasses, string $headerPath, string $className): array
    {
        foreach ($this->specializableBases($classData, $headerPath, $className) as $baseClass) {
            if (!is_string($baseClass) || $baseClass === '') {
                continue;
            }

            $specialized = $this->listSpecializationResolver->classNameFor($baseClass);
            if ($specialized === null) {
                continue;
            }

            $allowedClasses[] = $specialized;
        }

        return array_values(array_unique($allowedClasses));
    }

    /**
     * @param array<string, mixed> $classData
     * @return array<string, mixed>
     */
    private function normalizeSupportedListBases(array $classData, string $headerPath, string $className): array
    {
        $bases = $this->specializableBases($classData, $headerPath, $className);
        if ($bases === []) {
            return $classData;
        }

        $classData['bases'] = array_values(array_map(
            fn(mixed $base): mixed => is_string($base)
                ? ($this->listSpecializationResolver->classNameFor($base) ?? $base)
                : $base,
            $bases,
        ));

        return $classData;
    }

    /**
     * @param array<string, mixed> $classData
     * @return list<string>
     */
    private function specializableBases(array $classData, string $headerPath, string $className): array
    {
        $sourceBases = $this->classBaseDeclarationsFromSource($headerPath, $className);
        if ($sourceBases !== []) {
            return $sourceBases;
        }

        return array_values(array_filter(
            array_map(static fn(mixed $base): string => is_string($base) ? trim($base) : '', (array) ($classData['bases'] ?? [])),
            static fn(string $base): bool => $base !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function classBaseDeclarationsFromSource(string $headerPath, string $className): array
    {
        $resolved = $this->resolveClassDefinitionSource($headerPath, $className);
        if ($resolved === null || !is_string($resolved['contents'] ?? null)) {
            return [];
        }

        $pattern = sprintf(
            '/(?:^|\n)\s*(?:class|struct)\s+(?:[A-Za-z_][A-Za-z0-9_]*\s+)*%s\b(?P<bases>\s*:[^{]+)?\s*\{/s',
            preg_quote($className, '/'),
        );
        if (preg_match($pattern, $resolved['contents'], $matches) !== 1) {
            return [];
        }

        $basesClause = is_string($matches['bases'] ?? null) ? trim($matches['bases']) : '';
        if ($basesClause === '' || !str_starts_with($basesClause, ':')) {
            return [];
        }

        $basesClause = trim(substr($basesClause, 1));
        if ($basesClause === '') {
            return [];
        }

        $bases = [];
        foreach ($this->splitTopLevelBaseList($basesClause) as $base) {
            $base = preg_replace('/\b(public|protected|private|virtual)\b/', ' ', $base) ?? $base;
            $base = trim(preg_replace('/\s+/', ' ', $base) ?? $base);
            if ($base !== '') {
                $bases[] = $base;
            }
        }

        return $bases;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelBaseList(string $basesClause): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($basesClause);

        for ($i = 0; $i < $length; $i++) {
            $char = $basesClause[$i];

            if ($char === '<') {
                $depth++;
                $current .= $char;
                continue;
            }

            if ($char === '>') {
                $depth = max(0, $depth - 1);
                $current .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private function introspectionContents(string $headerPath, ?string $className = null): string
    {
        if ($className !== null && $className !== '') {
            $resolved = $this->resolveClassDefinitionSource($headerPath, $className);
            if (is_array($resolved) && is_array($resolved['body'] ?? null) && is_string($resolved['body']['body'] ?? null)) {
                return $resolved['body']['body'];
            }
        }

        $contents = @file_get_contents($headerPath);
        if (!is_string($contents) || $contents === '') {
            return '';
        }

        return $contents;
    }
}
