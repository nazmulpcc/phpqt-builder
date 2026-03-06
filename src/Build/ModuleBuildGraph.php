<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class ModuleBuildGraph
{
    /**
     * @param array<string, string> $classOwnership
     * @param array<string, list<string>> $dependencies
     * @param list<string> $buildOrder
     */
    public function __construct(
        public array $classOwnership,
        public array $dependencies,
        public array $buildOrder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'class_ownership' => $this->classOwnership,
            'dependencies' => $this->dependencies,
            'build_order' => $this->buildOrder,
        ];
    }
}
