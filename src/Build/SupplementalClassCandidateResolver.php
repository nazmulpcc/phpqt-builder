<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;
use QtBuilder\Scanning\HeaderCandidate;

class SupplementalClassCandidateResolver
{
    public function __construct(
        private readonly IncludeGraphResolver $includeGraphResolver = new IncludeGraphResolver(),
    ) {}

    /**
     * @param list<string> $requestedModules
     * @param list<string> $includePaths
     * @param array<string, bool> $knownClasses
     */
    public function resolve(
        string $missingClass,
        HeaderCandidate $fromCandidate,
        array $requestedModules,
        array $includePaths,
        array $knownClasses,
        string $triggerReason,
    ): ?SupplementalClassCandidate {
        $missingClass = trim($missingClass);
        if (preg_match('/^Q[A-Z][A-Za-z0-9_]*$/', $missingClass) !== 1) {
            return null;
        }

        if (isset($knownClasses[$missingClass])) {
            return null;
        }

        $requestedSet = array_fill_keys($requestedModules, true);

        foreach ($this->candidateHeaders($fromCandidate->parseHeader, $includePaths) as $headerPath) {
            $definitionHeader = $this->locateClassDefinitionHeader($headerPath, $missingClass, $includePaths);
            if ($definitionHeader === null) {
                continue;
            }

            $module = $this->moduleForHeaderPath($definitionHeader, $fromCandidate->module);
            if (!isset($requestedSet[$module])) {
                continue;
            }

            return new SupplementalClassCandidate(
                candidate: new HeaderCandidate(
                    module: $module,
                    className: $missingClass,
                    publicHeader: $definitionHeader,
                    parseHeader: $definitionHeader,
                ),
                discoveredFromClass: $fromCandidate->className,
                discoveredFromHeader: $fromCandidate->parseHeader,
                triggerReason: $triggerReason,
            );
        }

        return null;
    }

    /**
     * @param list<string> $includePaths
     * @return list<string>
     */
    private function candidateHeaders(string $headerPath, array $includePaths): array
    {
        return array_values(array_unique([
            $headerPath,
            ...$this->includeGraphResolver->transitiveIncludes($headerPath, $includePaths),
        ]));
    }

    /**
     * @param list<string> $includePaths
     */
    private function locateClassDefinitionHeader(string $headerPath, string $className, array $includePaths): ?string
    {
        $inspector = new QtClassInspector(new ClangArgumentBuilder($includePaths));

        return $inspector->locateClassHeader($headerPath, $className);
    }

    private function moduleForHeaderPath(string $headerPath, string $fallbackModule): string
    {
        if (preg_match('/\/(Qt[A-Za-z0-9_]+)(?:\.framework(?:\/Versions\/[^\/]+)?\/Headers|\/)/', $headerPath, $matches) === 1) {
            return $matches[1];
        }

        return $fallbackModule;
    }
}
