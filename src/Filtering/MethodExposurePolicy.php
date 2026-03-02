<?php

declare(strict_types=1);

namespace QtBuilder\Filtering;

use QtBuilder\CodeGen\TypeBridge;
use QtBuilder\Parsing\CppToPhpTypeMapper;

class MethodExposurePolicy
{
    /** @var list<string> */
    private const array NAME_SKIP = [
        'metaObject',
        'qt_metacall',
        'qt_metacast',
        'tr',
        'trUtf8',
        'fromRawData',
        'data_ptr',
        'd_func',
        'q_func',
    ];

    private CppToPhpTypeMapper $typeMapper;
    private TypeBridge $typeBridge;

    public function __construct(
        ?CppToPhpTypeMapper $typeMapper = null,
        ?TypeBridge $typeBridge = null,
    ) {
        $this->typeMapper = $typeMapper ?? new CppToPhpTypeMapper();
        $this->typeBridge = $typeBridge ?? new TypeBridge();
    }

    /**
     * @param array{name: string, methods: list<array<string, mixed>>} $classData
     * @param list<string> $allowedClasses
     * @return array{selected_methods: list<array<string, mixed>>, skipped_methods: list<array<string, string>>}
     */
    public function filter(array $classData, array $allowedClasses = []): array
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

        foreach ($classData['methods'] as $method) {
            $grouped[$method['name']][] = $method;
        }

        $isCopyConstructible = (bool) ($classData['is_copy_constructible'] ?? true);

        foreach ($grouped as $methodName => $variants) {
            $result = $this->selectVariant($classData['name'], $methodName, $variants, $allowedClasses, $flagAliases, $enumNames, $isCopyConstructible);
            if ($result['selected'] !== null) {
                $selectedMethods[] = $result['selected'];
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
     * @return array{selected: ?array<string, mixed>, skipped: list<array<string, string>>}
     */
    private function selectVariant(string $className, string $methodName, array $variants, array $allowedClasses, array $flagAliases, array $enumNames, bool $isCopyConstructible): array
    {
        if (str_starts_with($methodName, '~') || str_starts_with($methodName, 'operator') || in_array($methodName, self::NAME_SKIP, true)) {
            return [
                'selected' => null,
                'skipped' => [[
                    'name' => $methodName,
                    'reason_code' => 'method_name_filtered',
                    'reason_message' => sprintf('Method %s is filtered by name.', $methodName),
                ]],
            ];
        }

        $seenSignatures = [];
        $ranked = [];
        $skipped = [];

        foreach ($variants as $variant) {
            $signature = $this->signature($variant);
            if (isset($seenSignatures[$signature])) {
                continue;
            }
            $seenSignatures[$signature] = true;

            $unsupportedReason = $this->unsupportedReason($className, $variant, $allowedClasses, $flagAliases, $enumNames, $isCopyConstructible);
            if ($unsupportedReason !== null) {
                $skipped[] = [
                    'name' => $methodName,
                    'reason_code' => $unsupportedReason['code'],
                    'reason_message' => $unsupportedReason['message'],
                ];
                continue;
            }

            $ranked[] = [
                'variant' => $variant,
                'score' => $this->score($variant),
            ];
        }

        if ($ranked === []) {
            return ['selected' => null, 'skipped' => $skipped];
        }

        usort($ranked, fn(array $a, array $b): int => $this->compareScores($a['score'], $b['score']));

        if (count($ranked) > 1 && $this->compareScores($ranked[0]['score'], $ranked[1]['score']) === 0) {
            $skipped[] = [
                'name' => $methodName,
                'reason_code' => 'ambiguous_overload',
                'reason_message' => sprintf('Method %s has multiple equally-ranked overloads.', $methodName),
            ];

            return ['selected' => null, 'skipped' => $skipped];
        }

        return [
            'selected' => $this->normalizeSpecialTypes($className, $ranked[0]['variant'], $flagAliases, $enumNames),
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $variant
     * @param list<string> $allowedClasses
     * @param array<string, string> $flagAliases
     * @param list<string> $enumNames
     * @return array{code: string, message: string}|null
     */
    private function unsupportedReason(string $className, array $variant, array $allowedClasses, array $flagAliases = [], array $enumNames = [], bool $isCopyConstructible = true): ?array
    {
        $access = (string) ($variant['access'] ?? 'unknown');
        if ($access !== 'public') {
            return ['code' => 'non_public_method', 'message' => sprintf('Methods with %s access are not exposed.', $access)];
        }

        if (!$isCopyConstructible && $this->isCopyConstructor($className, $variant)) {
            return ['code' => 'noncopyable_copy_constructor', 'message' => 'Copy constructor is disabled by the native class definition.'];
        }

        if (($variant['is_pure_virtual'] ?? false) === true) {
            return ['code' => 'pure_virtual_method', 'message' => 'Pure virtual methods are not exposed.'];
        }

        $returnType = (string) $variant['return_type'];
        if ($this->isUnsafeReferenceReturn($returnType)) {
            return ['code' => 'unsupported_reference_return', 'message' => 'Non-const reference returns are skipped.'];
        }

        if (!$this->isSupportedType($returnType, $className, $allowedClasses, true, $flagAliases, $enumNames)) {
            return ['code' => 'unsupported_return_type', 'message' => sprintf('Return type %s is not supported.', $returnType)];
        }

        foreach ($variant['parameters'] as $parameter) {
            $type = (string) $parameter['type'];
            if ($this->isUnsupportedReferenceParameter($type)) {
                return ['code' => 'unsupported_reference_parameter', 'message' => sprintf('Parameter type %s is a non-const reference.', $type)];
            }
            if ($this->isUnsupportedOutParameter($type, $className, $flagAliases, $enumNames)) {
                return ['code' => 'unsupported_output_parameter', 'message' => sprintf('Parameter type %s looks like an output parameter.', $type)];
            }
            if (!$this->isSupportedType($type, $className, $allowedClasses, false, $flagAliases, $enumNames)) {
                return ['code' => 'unsupported_parameter_type', 'message' => sprintf('Parameter type %s is not supported.', $type)];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $variant
     */
    private function isCopyConstructor(string $className, array $variant): bool
    {
        if (($variant['name'] ?? null) !== $className) {
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

        return $this->normalizeSelfType($type) === $className;
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

    private function isUnsafeReferenceReturn(string $cppType): bool
    {
        $trimmed = trim($cppType);

        return str_contains($trimmed, '&') && !str_starts_with($trimmed, 'const ');
    }

    private function isUnsupportedReferenceParameter(string $cppType): bool
    {
        $trimmed = trim($cppType);

        return str_contains($trimmed, '&') && !str_starts_with($trimmed, 'const ');
    }

    private function isUnsupportedOutParameter(string $cppType, string $className, array $flagAliases = [], array $enumNames = []): bool
    {
        $trimmed = trim($cppType);
        if (!str_contains($trimmed, '*') || str_starts_with($trimmed, 'const ')) {
            return false;
        }

        if ($this->isEnumOrFlagType($trimmed, $className, $flagAliases, $enumNames)) {
            return true;
        }

        $phpType = $this->typeMapper->map($trimmed);
        if (in_array($phpType, ['int', 'float', 'bool', 'string', 'array', 'mixed'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $allowedClasses
     * @param array<string, string> $flagAliases
     * @param list<string> $enumNames
     */
    private function isSupportedType(string $cppType, string $className, array $allowedClasses, bool $isReturn, array $flagAliases = [], array $enumNames = []): bool
    {
        $trimmed = trim($cppType);
        if ($trimmed === '') {
            return false;
        }

        if ($trimmed === 'void') {
            return true;
        }

        if (preg_match('/\(\s*\*/', $trimmed) === 1 || str_contains($trimmed, 'std::function')) {
            return false;
        }

        if (str_contains($trimmed, '<') && !$this->isSupportedTemplateType($trimmed)) {
            return false;
        }

        if ($this->isEnumOrFlagType($trimmed, $className, $flagAliases, $enumNames)) {
            return true;
        }

        $phpType = $this->typeMapper->map($trimmed);
        if (in_array($phpType, ['int', 'float', 'bool', 'string', 'void'], true)) {
            return true;
        }

        if (in_array($phpType, ['array', 'mixed'], true)) {
            return false;
        }

        if ($this->typeBridge->isValueType($phpType)) {
            return true;
        }

        if ($phpType === $className) {
            return true;
        }

        return in_array($phpType, $allowedClasses, true);
    }

    private function isSupportedTemplateType(string $cppType): bool
    {
        return str_starts_with(trim($cppType), 'QFlags<');
    }

    private function isEnumOrFlagType(string $cppType, string $className, array $flagAliases = [], array $enumNames = []): bool
    {
        $trimmed = trim($cppType);

        if (str_starts_with($trimmed, 'QFlags<')) {
            return true;
        }

        if (str_contains($trimmed, '::')) {
            $suffix = substr($trimmed, (int) strrpos($trimmed, '::') + 2);
            if ($suffix === '') {
                return false;
            }

            return isset($flagAliases[$suffix])
                || in_array($suffix, $enumNames, true)
                || $this->looksLikeQualifiedEnumName($suffix);
        }

        if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            return false;
        }

        if (isset($flagAliases[$trimmed]) || in_array($trimmed, $enumNames, true)) {
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
    private function normalizeSpecialTypes(string $className, array $variant, array $flagAliases, array $enumNames = []): array
    {
        $variant['return_type'] = $this->normalizeEnumType($className, (string) $variant['return_type'], $flagAliases, $enumNames);
        $variant['parameters'] = array_map(
            function (array $parameter) use ($className, $flagAliases, $enumNames): array {
                $parameter['type'] = $this->normalizeEnumType($className, (string) ($parameter['type'] ?? ''), $flagAliases, $enumNames);

                return $parameter;
            },
            $variant['parameters'],
        );

        return $variant;
    }

    /**
     * @param array<string, string> $flagAliases
     * @param list<string> $enumNames
     */
    private function normalizeEnumType(string $className, string $cppType, array $flagAliases = [], array $enumNames = []): string
    {
        $trimmed = trim($cppType);

        if (isset($flagAliases[$trimmed])) {
            return sprintf('QFlags<%s::%s>', $className, $flagAliases[$trimmed]);
        }

        $qualifiedPrefix = $className . '::';
        if (str_starts_with($trimmed, $qualifiedPrefix)) {
            $nested = substr($trimmed, strlen($qualifiedPrefix));
            if ($nested !== '' && isset($flagAliases[$nested])) {
                return sprintf('QFlags<%s::%s>', $className, $flagAliases[$nested]);
            }
        }

        if (!$this->isEnumOrFlagType($trimmed, $className, $flagAliases, $enumNames)) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, 'QFlags<') || str_contains($trimmed, '::')) {
            return $trimmed;
        }

        return $className . '::' . $trimmed;
    }

    private function looksLikeQualifiedEnumName(string $name): bool
    {
        foreach (['Result', 'Private', 'Data', 'Pointer', 'Iterator', 'Ref', 'Helper'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return false;
            }
        }

        return true;
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
        $referencePenalty = str_contains((string) $variant['return_type'], '&') ? 1 : 0;

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
}
