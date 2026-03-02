<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Filtering\ClassExposurePolicy;
use QtBuilder\Filtering\MethodExposurePolicy;
use QtBuilder\Parsing\ClassDefinitionBuilder;
use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;

class ClassGenerationService
{
    public function __construct(
        private readonly ClassExposurePolicy $classPolicy = new ClassExposurePolicy(),
        private readonly MethodExposurePolicy $methodPolicy = new MethodExposurePolicy(),
        private readonly ClassDefinitionBuilder $builder = new ClassDefinitionBuilder(),
    ) {}

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     */
    public function generate(string $headerPath, string $className, array $includePaths, array $allowedClasses = []): ClassGenerationResult
    {
        $decision = $this->classPolicy->decideClassName($className);
        if (!$decision->accepted) {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                $decision->reasonCode ?? 'class_filtered',
                $decision->reasonMessage ?? 'Class is filtered.',
            );
        }

        $inspector = new QtClassInspector(new ClangArgumentBuilder($includePaths));
        if ($this->isTemplateClassDeclaration($headerPath, $className)) {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                'template_class',
                'Template classes are skipped in the current build mode.',
            );
        }

        $classData = $inspector->inspect($headerPath, $className);
        if ($classData === null) {
            return ClassGenerationResult::skipped($className, $headerPath, 'class_not_found', 'Class definition was not found in the parsed header.');
        }
        $classData['is_copy_constructible'] = $this->detectCopyConstructible($headerPath, $className);
        $classData['flag_aliases'] = $this->discoverFlagAliases($headerPath);
        $classData['enum_names'] = $this->discoverEnumNames($headerPath);

        if (($classData['is_abstract'] ?? false) === true) {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                'abstract_class',
                'Abstract classes are skipped in the current build mode.',
            );
        }

        $parentClass = is_string($classData['bases'][0] ?? null) ? $classData['bases'][0] : null;
        if ($parentClass !== null && !in_array($parentClass, $allowedClasses, true)) {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                'unsupported_parent_class',
                sprintf('Parent class %s is not available for generation.', $parentClass),
            );
        }

        $filtered = $this->methodPolicy->filter($classData, $allowedClasses);
        $classData['methods'] = $filtered['selected_methods'];

        $phpClass = $this->builder->build($classData);
        if (count($phpClass->methods) === 0) {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                'no_supported_methods',
                'No supported methods remained after filtering.',
                $filtered['skipped_methods'],
            );
        }

        return ClassGenerationResult::ok($className, $headerPath, $phpClass, $filtered['skipped_methods']);
    }

    private function detectCopyConstructible(string $headerPath, string $className): bool
    {
        $contents = @file_get_contents($headerPath);
        if (!is_string($contents) || $contents === '') {
            return true;
        }

        $classPattern = preg_quote($className, '/');

        $patterns = [
            '/Q_DISABLE_COPY(?:_MOVE)?\(\s*' . $classPattern . '\s*\)/',
            '/' . $classPattern . '\s*\(\s*const\s+' . $classPattern . '\s*&\s*\)\s*=\s*delete\s*;/',
            '/' . $classPattern . '\s*\(\s*' . $classPattern . '\s*&&\s*\)\s*=\s*delete\s*;/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                return false;
            }
        }

        return true;
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
    private function discoverFlagAliases(string $headerPath): array
    {
        $contents = @file_get_contents($headerPath);
        if (!is_string($contents) || $contents === '') {
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
    private function discoverEnumNames(string $headerPath): array
    {
        $contents = @file_get_contents($headerPath);
        if (!is_string($contents) || $contents === '') {
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
}
