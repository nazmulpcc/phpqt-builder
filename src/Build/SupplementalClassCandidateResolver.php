<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;
use QtBuilder\Scanning\HeaderCandidate;
use QtBuilder\Support\CppName;

class SupplementalClassCandidateResolver
{
    /** @var array<string, list<string>> */
    private array $includeGraphCache = [];
    /** @var array<string, QtClassInspector> */
    private array $inspectorsByIncludeSignature = [];
    /** @var array<string, string|null> */
    private array $classHeaderLookupCache = [];
    /** @var array<string, string|null> */
    private array $directHeaderLookupCache = [];

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
        if (!$this->looksLikeClassName($missingClass)) {
            return null;
        }
        if (str_starts_with($missingClass, 'QtPrivate::')) {
            return null;
        }

        $shortName = CppName::unqualify($missingClass);
        if (isset($knownClasses[$missingClass]) || isset($knownClasses[$shortName])) {
            return null;
        }

        $requestedSet = array_fill_keys($requestedModules, true);

        $directHeader = $this->locateDirectClassHeader($shortName, $includePaths);
        if ($directHeader !== null) {
            $module = $this->moduleForHeaderPath($directHeader, $fromCandidate->module);
            if (isset($requestedSet[$module])) {
                return new SupplementalClassCandidate(
                    candidate: new HeaderCandidate(
                        module: $module,
                        className: $shortName,
                        publicHeader: $directHeader,
                        parseHeader: $directHeader,
                        qualifiedClassName: str_contains($missingClass, '::') ? $missingClass : null,
                    ),
                    discoveredFromClass: $fromCandidate->className,
                    discoveredFromHeader: $fromCandidate->parseHeader,
                    triggerReason: $triggerReason,
                );
            }
        }

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
                    className: $shortName,
                    publicHeader: $definitionHeader,
                    parseHeader: $definitionHeader,
                    qualifiedClassName: str_contains($missingClass, '::') ? $missingClass : null,
                ),
                discoveredFromClass: $fromCandidate->className,
                discoveredFromHeader: $fromCandidate->parseHeader,
                triggerReason: $triggerReason,
            );
        }

        return null;
    }

    private function looksLikeClassName(string $name): bool
    {
        return preg_match('/^(?:::)?(?:[A-Za-z_][A-Za-z0-9_]*::)*[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
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
        $includeSignature = $this->includePathSignature($includePaths);
        $cacheKey = $includeSignature . '|' . $headerPath . '|' . $className;
        if (array_key_exists($cacheKey, $this->classHeaderLookupCache)) {
            return $this->classHeaderLookupCache[$cacheKey];
        }

        $inspector = $this->inspectorForIncludePaths($includePaths);
        $resolved = $inspector->locateClassHeader($headerPath, $className);
        $this->classHeaderLookupCache[$cacheKey] = $resolved;

        return $resolved;
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

    /**
     * @param list<string> $includePaths
     */
    private function inspectorForIncludePaths(array $includePaths): QtClassInspector
    {
        $signature = $this->includePathSignature($includePaths);
        if (!isset($this->inspectorsByIncludeSignature[$signature])) {
            $this->inspectorsByIncludeSignature[$signature] = new QtClassInspector(new ClangArgumentBuilder($includePaths));
        }

        return $this->inspectorsByIncludeSignature[$signature];
    }

    /**
     * @param list<string> $includePaths
     */
    private function includePathSignature(array $includePaths): string
    {
        return sha1(json_encode(array_values($includePaths), JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param list<string> $includePaths
     */
    private function locateDirectClassHeader(string $shortName, array $includePaths): ?string
    {
        $includeSignature = $this->includePathSignature($includePaths);
        $cacheKey = $includeSignature . '|' . $shortName;
        if (array_key_exists($cacheKey, $this->directHeaderLookupCache)) {
            return $this->directHeaderLookupCache[$cacheKey];
        }

        foreach ($includePaths as $includePath) {
            if (!is_string($includePath) || $includePath === '' || str_starts_with($includePath, '-')) {
                continue;
            }

            $candidates = [
                rtrim($includePath, '/') . '/' . $shortName,
                rtrim(dirname($includePath), '/') . '/' . $shortName,
            ];

            foreach ($candidates as $candidate) {
                $real = realpath($candidate);
                if ($real !== false && is_file($real)) {
                    return $this->directHeaderLookupCache[$cacheKey] = $real;
                }
            }
        }

        return $this->directHeaderLookupCache[$cacheKey] = null;
    }
}
