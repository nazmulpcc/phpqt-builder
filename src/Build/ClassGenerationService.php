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

        if (($classData['is_abstract'] ?? false) === true) {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                'abstract_class',
                'Abstract classes are skipped in the current build mode.',
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
}
