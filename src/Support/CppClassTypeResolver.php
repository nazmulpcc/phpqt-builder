<?php

declare(strict_types=1);

namespace QtBuilder\Support;

use QtBuilder\Support\ModuleNamespace;

final class CppClassTypeResolver
{
    /** @var array<string, string> */
    private array $qualifiedByExact = [];

    /** @var array<string, array<string, string>> */
    private array $qualifiedByNamespaceAndBare = [];

    /** @var array<string, array<string, string>> */
    private array $qualifiedByModuleAndBare = [];

    /** @var array<string, list<string>> */
    private array $qualifiedByBare = [];

    /**
     * @param list<array{name: string, qualified_name?: string|null, module?: string|null}> $classUniverse
     */
    public function __construct(array $classUniverse = [])
    {
        foreach ($classUniverse as $entry) {
            $bareName = is_string($entry['name'] ?? null) ? trim((string) $entry['name']) : '';
            if ($bareName === '') {
                continue;
            }

            $qualifiedName = is_string($entry['qualified_name'] ?? null)
                ? trim((string) $entry['qualified_name'])
                : '';
            if ($qualifiedName === '') {
                $qualifiedName = $bareName;
            }

            $module = is_string($entry['module'] ?? null)
                ? trim((string) $entry['module'])
                : (TypeResolutionContext::moduleForQualifiedName($qualifiedName) ?? '');
            $namespace = TypeResolutionContext::namespaceForQualifiedName($qualifiedName) ?? '';

            $this->qualifiedByExact[$qualifiedName] = $qualifiedName;
            $this->qualifiedByBare[$bareName][] = $qualifiedName;

            if ($namespace !== '') {
                $this->qualifiedByNamespaceAndBare[$namespace][$bareName] = $qualifiedName;
            }

            if ($module !== '') {
                $this->qualifiedByModuleAndBare[$module][$bareName] = $qualifiedName;
            }
        }

        foreach ($this->qualifiedByBare as $bareName => $qualifiedNames) {
            $this->qualifiedByBare[$bareName] = array_values(array_unique($qualifiedNames));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $classDataByClass
     */
    public static function fromPreparedClassData(array $classDataByClass): self
    {
        $entries = [];

        foreach ($classDataByClass as $className => $classData) {
            if (!is_array($classData)) {
                continue;
            }

            $entries[] = [
                'name' => is_string($classData['name'] ?? null) ? (string) $classData['name'] : (string) $className,
                'qualified_name' => is_string($classData['qualified_name'] ?? null)
                    ? (string) $classData['qualified_name']
                    : null,
                'module' => TypeResolutionContext::moduleForQualifiedName(
                    is_string($classData['qualified_name'] ?? null) ? (string) $classData['qualified_name'] : null,
                ),
            ];
        }

        return new self($entries);
    }

    public static function forSingleClass(string $className, ?string $qualifiedClassName = null): self
    {
        return new self([[
            'name' => $className,
            'qualified_name' => $qualifiedClassName,
            'module' => TypeResolutionContext::moduleForQualifiedName($qualifiedClassName),
        ]]);
    }

    public function canonicalizeType(string $cppType, TypeResolutionContext $context): string
    {
        $parsed = $this->parseDirectClassReference($cppType);
        if ($parsed !== null) {
            $resolved = $this->resolveQualifiedName($parsed['base'], $context);
            if ($resolved !== null) {
                return $parsed['prefix'] . $resolved . $parsed['suffix'];
            }
        }

        $nestedParsed = $this->parseNestedOwnerReference($cppType);
        if ($nestedParsed === null) {
            return $cppType;
        }

        $resolvedOwner = $this->resolveQualifiedName($nestedParsed['owner'], $context);
        if ($resolvedOwner === null) {
            return $cppType;
        }

        return $nestedParsed['prefix'] . $resolvedOwner . '::' . $nestedParsed['member'] . $nestedParsed['suffix'];
    }

    public function resolvePhpClassIdentity(string $cppType, ?TypeResolutionContext $context = null): ?string
    {
        $resolved = $this->resolveQualifiedClassName($cppType, $context);
        if ($resolved === null) {
            return null;
        }

        return CppName::unqualify($resolved);
    }

    public function resolvePhpType(string $cppType, ?TypeResolutionContext $context = null, ?string $ownerPhpNamespace = null): ?string
    {
        $resolved = $this->resolveQualifiedClassName($cppType, $context);
        if ($resolved === null) {
            return null;
        }

        $bareName = CppName::unqualify($resolved);
        $module = TypeResolutionContext::moduleForQualifiedName($resolved);
        if ($module === null) {
            return $bareName;
        }

        $targetPhpNamespace = ModuleNamespace::forQtModule($module);
        if (str_starts_with($targetPhpNamespace, 'Qt\\Qt')) {
            return '\\' . $targetPhpNamespace . '\\' . $bareName;
        }

        if ($ownerPhpNamespace !== null && $ownerPhpNamespace !== '' && $targetPhpNamespace !== $ownerPhpNamespace) {
            return '\\' . $targetPhpNamespace . '\\' . $bareName;
        }

        return $bareName;
    }

    public function resolveQualifiedClassName(string $cppType, ?TypeResolutionContext $context = null): ?string
    {
        $parsed = $this->parseDirectClassReference($cppType);
        if ($parsed === null) {
            return null;
        }

        return $this->resolveQualifiedName($parsed['base'], $context);
    }

    private function resolveQualifiedName(string $baseType, ?TypeResolutionContext $context = null): ?string
    {
        $trimmed = trim($baseType);
        if ($trimmed === '') {
            return null;
        }

        if (str_contains($trimmed, '::') && isset($this->qualifiedByExact[$trimmed])) {
            return $this->qualifiedByExact[$trimmed];
        }

        $bareName = CppName::unqualify($trimmed);
        if ($bareName === '') {
            return null;
        }

        if ($context !== null && $context->qualifiedClassName !== null && $context->className === $bareName) {
            return $context->qualifiedClassName;
        }

        if ($context !== null && $context->namespace !== null) {
            $namespaceMatch = $this->qualifiedByNamespaceAndBare[$context->namespace][$bareName] ?? null;
            if (is_string($namespaceMatch) && $namespaceMatch !== '') {
                return $namespaceMatch;
            }
        }

        if ($context !== null && $context->module !== null) {
            $moduleMatch = $this->qualifiedByModuleAndBare[$context->module][$bareName] ?? null;
            if (is_string($moduleMatch) && $moduleMatch !== '') {
                return $moduleMatch;
            }
        }

        $matches = $this->qualifiedByBare[$bareName] ?? [];
        if (count($matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @return array{prefix: string, base: string, suffix: string}|null
     */
    private function parseDirectClassReference(string $cppType): ?array
    {
        $trimmed = trim($cppType);
        if ($trimmed === '' || str_contains($trimmed, '<') || str_contains($trimmed, '(') || str_contains($trimmed, '[')) {
            return null;
        }

        if (preg_match('/^(?<prefix>(?:const\s+)?)(?<base>(?:::)?(?:[A-Za-z_][A-Za-z0-9_]*::)*[A-Za-z_][A-Za-z0-9_]*)(?<suffix>(?:\s*[*&]\s*)*)$/', $trimmed, $matches) !== 1) {
            return null;
        }

        return [
            'prefix' => $matches['prefix'] ?? '',
            'base' => trim($matches['base'] ?? ''),
            'suffix' => $matches['suffix'] ?? '',
        ];
    }

    /**
     * @return array{prefix: string, owner: string, member: string, suffix: string}|null
     */
    private function parseNestedOwnerReference(string $cppType): ?array
    {
        $trimmed = trim($cppType);
        if ($trimmed === '' || str_contains($trimmed, '<') || str_contains($trimmed, '(') || str_contains($trimmed, '[')) {
            return null;
        }

        if (preg_match('/^(?<prefix>(?:const\s+)?)(?<owner>(?:::)?(?:[A-Za-z_][A-Za-z0-9_]*::)*[A-Za-z_][A-Za-z0-9_]*)::(?<member>[A-Za-z_][A-Za-z0-9_]*)(?<suffix>(?:\s*[*&]\s*)*)$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $owner = trim($matches['owner'] ?? '');
        $member = trim($matches['member'] ?? '');
        if ($owner === '' || $member === '') {
            return null;
        }

        return [
            'prefix' => $matches['prefix'] ?? '',
            'owner' => $owner,
            'member' => $member,
            'suffix' => $matches['suffix'] ?? '',
        ];
    }
}
