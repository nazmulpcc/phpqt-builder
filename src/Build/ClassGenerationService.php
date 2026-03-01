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
        $classData = $inspector->inspect($headerPath, $className);
        if ($classData === null) {
            return ClassGenerationResult::skipped($className, $headerPath, 'class_not_found', 'Class definition was not found in the parsed header.');
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
}
