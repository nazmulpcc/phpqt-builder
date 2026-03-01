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

        foreach ($classData['methods'] as $method) {
            $grouped[$method['name']][] = $method;
        }

        foreach ($grouped as $methodName => $variants) {
            $result = $this->selectVariant($classData['name'], $methodName, $variants, $allowedClasses);
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
     * @return array{selected: ?array<string, mixed>, skipped: list<array<string, string>>}
     */
    private function selectVariant(string $className, string $methodName, array $variants, array $allowedClasses): array
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

            $unsupportedReason = $this->unsupportedReason($className, $variant, $allowedClasses);
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
            'selected' => $ranked[0]['variant'],
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $variant
     * @param list<string> $allowedClasses
     * @return array{code: string, message: string}|null
     */
    private function unsupportedReason(string $className, array $variant, array $allowedClasses): ?array
    {
        if (($variant['access'] ?? 'public') === 'private') {
            return ['code' => 'private_method', 'message' => 'Private methods are not exposed.'];
        }

        $returnType = (string) $variant['return_type'];
        if ($this->isUnsafeReferenceReturn($returnType)) {
            return ['code' => 'unsupported_reference_return', 'message' => 'Non-const reference returns are skipped.'];
        }

        if (!$this->isSupportedType($returnType, $className, $allowedClasses, true)) {
            return ['code' => 'unsupported_return_type', 'message' => sprintf('Return type %s is not supported.', $returnType)];
        }

        foreach ($variant['parameters'] as $parameter) {
            $type = (string) $parameter['type'];
            if (!$this->isSupportedType($type, $className, $allowedClasses, false)) {
                return ['code' => 'unsupported_parameter_type', 'message' => sprintf('Parameter type %s is not supported.', $type)];
            }
        }

        return null;
    }

    private function isUnsafeReferenceReturn(string $cppType): bool
    {
        $trimmed = trim($cppType);

        return str_contains($trimmed, '&') && !str_starts_with($trimmed, 'const ');
    }

    /**
     * @param list<string> $allowedClasses
     */
    private function isSupportedType(string $cppType, string $className, array $allowedClasses, bool $isReturn): bool
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
