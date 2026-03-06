<?php

declare(strict_types=1);

namespace QtBuilder\Build\Dependencies;

interface ModuleDependencyResolver
{
    /**
     * @param list<string> $requestedModules
     */
    public function resolve(array $requestedModules): ResolvedModuleGraph;

    /**
     * @return list<string>
     */
    public function supportedModules(): array;
}
