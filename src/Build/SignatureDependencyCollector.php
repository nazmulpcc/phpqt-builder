<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Parsing\ContainerTypeParser;
use QtBuilder\Support\CppName;

final class SignatureDependencyCollector
{
    private ContainerTypeParser $parser;

    /**
     * @var array<string, list<string>>
     */
    private array $dependencyCache = [];

    public function __construct(?ContainerTypeParser $parser = null)
    {
        $this->parser = $parser ?? new ContainerTypeParser();
    }

    /**
     * @param array<string, mixed> $classData
     * @return list<string>
     */
    public function collectFromClassData(array $classData, string $ownerClass): array
    {
        $types = [];
        foreach (['methods', 'signals'] as $bucket) {
            $methods = $classData[$bucket] ?? [];
            if (!is_array($methods)) {
                continue;
            }

            foreach ($methods as $method) {
                if (!is_array($method)) {
                    continue;
                }

                if (is_string($method['return_type'] ?? null)) {
                    foreach ($this->extractTypeDependencies((string) $method['return_type'], $ownerClass) as $dependency) {
                        $types[$dependency] = true;
                    }
                }

                $parameters = $method['parameters'] ?? [];
                if (!is_array($parameters)) {
                    continue;
                }

                foreach ($parameters as $parameter) {
                    if (!is_array($parameter) || !is_string($parameter['type'] ?? null)) {
                        continue;
                    }

                    foreach ($this->extractTypeDependencies((string) $parameter['type'], $ownerClass) as $dependency) {
                        $types[$dependency] = true;
                    }
                }
            }
        }

        return array_keys($types);
    }

    /**
     * @return list<string>
     */
    private function extractTypeDependencies(string $cppType, string $ownerClass): array
    {
        $normalized = $this->normalizeDependencyType($cppType);
        if ($normalized === '') {
            return [];
        }

        $cacheKey = $ownerClass . '|' . $normalized;
        if (array_key_exists($cacheKey, $this->dependencyCache)) {
            return $this->dependencyCache[$cacheKey];
        }

        $container = $this->parser->parse($normalized);
        if ($container !== null) {
            $deps = [];
            foreach (array_filter([$container->elementType, $container->keyType, $container->valueType]) as $memberType) {
                foreach ($this->extractTypeDependencies((string) $memberType, $ownerClass) as $dependency) {
                    $deps[$dependency] = true;
                }
            }

            return $this->dependencyCache[$cacheKey] = array_keys($deps);
        }

        if (str_contains($normalized, '<') || str_starts_with($normalized, 'std::') || str_starts_with($normalized, 'QFlags<')) {
            return $this->dependencyCache[$cacheKey] = [];
        }

        // Supplemental discovery should stay focused on Qt class-like identifiers.
        // Non-Qt types (including std::* and scalar aliases) are intentionally ignored.
        if (preg_match('/(^|::)Q[A-Za-z_][A-Za-z0-9_]*(::[A-Za-z_][A-Za-z0-9_]*)?$/', $normalized) !== 1) {
            return $this->dependencyCache[$cacheKey] = [];
        }

        if (str_starts_with($normalized, 'QtPrivate::')) {
            return $this->dependencyCache[$cacheKey] = [];
        }

        $segments = explode('::', $normalized);
        $terminal = (string) end($segments);
        if ($terminal === '') {
            return $this->dependencyCache[$cacheKey] = [];
        }

        // Ignore enum-like nested identifiers and private/internal helper types.
        if (!str_starts_with($terminal, 'Q') || str_ends_with($terminal, 'Private') || $terminal === 'QPrivateSignal') {
            return $this->dependencyCache[$cacheKey] = [];
        }

        // Owner-qualified fallback (`Owner::Type`) was causing a massive
        // synthetic type explosion and expensive unresolved lookups.
        // Keep canonical type-only dependencies here.
        return $this->dependencyCache[$cacheKey] = [$normalized];
    }

    private function normalizeDependencyType(string $cppType): string
    {
        $type = trim($cppType);
        if ($type === '') {
            return '';
        }

        $type = preg_replace('/\bconst\b/', '', $type) ?? $type;
        $type = trim(preg_replace('/\s+/', ' ', $type) ?? $type);
        $type = rtrim($type, '& ');
        if (!str_contains($type, '<')) {
            while (str_ends_with($type, '*')) {
                $type = rtrim(substr($type, 0, -1));
            }
        }

        return trim($type);
    }
}
