<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;
use QtBuilder\Scanning\HeaderCandidate;

class SupplementalClassCandidateResolver
{
    /** @var array<string, list<string>> */
    private array $includeGraphCache = [];

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
            ...$this->transitiveIncludes($headerPath, $includePaths),
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

    /**
     * @param list<string> $includePaths
     * @return list<string>
     */
    private function transitiveIncludes(string $headerPath, array $includePaths): array
    {
        if (isset($this->includeGraphCache[$headerPath])) {
            return $this->includeGraphCache[$headerPath];
        }

        /** @var array<string, bool> $visited */
        $visited = [];
        /** @var array<string, bool> $resolved */
        $resolved = [];
        /** @var list<string> $queue */
        $queue = [$headerPath];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_string($current) || $current === '' || isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;
            if (!is_file($current)) {
                continue;
            }

            $contents = (string) file_get_contents($current);
            if (preg_match_all('/^\s*#\s*include\s*[<"]([^">]+)[">]/m', $contents, $matches) !== 1) {
                continue;
            }

            foreach ($matches[1] as $include) {
                if (!is_string($include)) {
                    continue;
                }

                $resolvedPath = $this->resolveIncludePath($include, $current, $includePaths);
                if ($resolvedPath === null) {
                    continue;
                }

                $resolved[$resolvedPath] = true;
                if (!isset($visited[$resolvedPath])) {
                    $queue[] = $resolvedPath;
                }
            }
        }

        $headers = array_keys($resolved);
        sort($headers);
        $this->includeGraphCache[$headerPath] = $headers;

        return $headers;
    }

    /**
     * @param list<string> $includePaths
     */
    private function resolveIncludePath(string $include, string $sourceHeader, array $includePaths): ?string
    {
        $candidates = [dirname($sourceHeader) . '/' . $include];
        foreach ($includePaths as $includePath) {
            if (!is_string($includePath) || $includePath === '' || str_starts_with($includePath, '-')) {
                continue;
            }

            $candidates[] = rtrim($includePath, '/') . '/' . ltrim($include, '/');
            $candidates[] = rtrim(dirname($includePath), '/') . '/' . ltrim($include, '/');
        }

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }

    private function moduleForHeaderPath(string $headerPath, string $fallbackModule): string
    {
        if (preg_match('/\/(Qt[A-Za-z0-9_]+)(?:\.framework(?:\/Versions\/[^\/]+)?\/Headers|\/)/', $headerPath, $matches) === 1) {
            return $matches[1];
        }

        return $fallbackModule;
    }
}
