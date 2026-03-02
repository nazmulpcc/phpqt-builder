<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
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
     * @param array<string, string> $classHeaders
     */
    public function generate(
        string $headerPath,
        string $className,
        array $includePaths,
        array $allowedClasses = [],
        array $classHeaders = [],
    ): ClassGenerationResult
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
        $lifecycle = $this->analyzeLifecycleCapabilities($headerPath, $className, (bool) ($classData['is_struct'] ?? false));
        $classData['is_copy_constructible'] = $lifecycle['is_copy_constructible'];
        $classData['has_public_constructor'] = $lifecycle['has_public_constructor'];
        $classData['has_public_default_constructor'] = $lifecycle['has_public_default_constructor'];
        $classData['has_public_destructor'] = $lifecycle['has_public_destructor'];
        $classData['flag_aliases'] = $this->discoverFlagAliases($headerPath, $className);
        $classData['enum_names'] = $this->discoverEnumNames($headerPath, $className);
        $classData['methods'] = $this->annotateConstructorVariants(
            is_array($classData['methods'] ?? null) ? $classData['methods'] : [],
            $headerPath,
            $className,
            (bool) ($classData['is_struct'] ?? false),
        );

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
        $inheritanceFiltered = $this->filterConflictingInheritedMethods(
            $phpClass,
            $headerPath,
            $includePaths,
            $allowedClasses,
            $classHeaders,
        );
        $phpClass = $inheritanceFiltered['class'];
        $skippedMethods = [...$filtered['skipped_methods'], ...$inheritanceFiltered['skipped_methods']];

        if (count($phpClass->methods) === 0) {
            return ClassGenerationResult::skipped(
                $className,
                $headerPath,
                'no_supported_methods',
                'No supported methods remained after filtering.',
                $skippedMethods,
            );
        }

        return ClassGenerationResult::ok($className, $headerPath, $phpClass, $skippedMethods);
    }

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     * @param array<string, string> $classHeaders
     * @return array{class: PhpClass, skipped_methods: list<array<string, string>>}
     */
    private function filterConflictingInheritedMethods(
        PhpClass $phpClass,
        string $headerPath,
        array $includePaths,
        array $allowedClasses,
        array $classHeaders,
    ): array {
        $parentClass = $phpClass->parent;
        if ($parentClass === null || $parentClass === '' || $parentClass === $phpClass->name) {
            return ['class' => $phpClass, 'skipped_methods' => []];
        }

        $parentMethods = $this->collectInheritedMethods(
            $parentClass,
            $headerPath,
            $includePaths,
            $allowedClasses,
            $classHeaders,
        );
        if ($parentMethods === []) {
            return ['class' => $phpClass, 'skipped_methods' => []];
        }

        $methods = [];
        $skippedMethods = [];

        foreach ($phpClass->methods as $method) {
            $parentMethod = $parentMethods[$method->name] ?? null;
            if ($parentMethod === null || $this->isCompatibleInheritedMethod($method, $parentMethod)) {
                $methods[] = $method;
                continue;
            }

            $skippedMethods[] = [
                'name' => $method->name,
                'reason_code' => 'incompatible_inherited_method',
                'reason_message' => sprintf(
                    'Method %s is skipped because its PHP signature is incompatible with an inherited %s() method.',
                    $method->name,
                    $parentMethod->name,
                ),
            ];
        }

        return [
            'class' => new PhpClass(
                name: $phpClass->name,
                parent: $phpClass->parent,
                isAbstract: $phpClass->isAbstract,
                isCopyConstructible: $phpClass->isCopyConstructible,
                hasPublicDestructor: $phpClass->hasPublicDestructor,
                properties: $phpClass->properties,
                methods: $methods,
            ),
            'skipped_methods' => $skippedMethods,
        ];
    }

    /**
     * @param list<string> $includePaths
     * @param list<string> $allowedClasses
     * @param array<string, string> $classHeaders
     * @param array<string, bool> $visited
     * @return array<string, PhpMethod>
     */
    private function collectInheritedMethods(
        string $className,
        string $fallbackHeaderPath,
        array $includePaths,
        array $allowedClasses,
        array $classHeaders,
        array &$visited = [],
    ): array {
        if (isset($visited[$className])) {
            return [];
        }

        $visited[$className] = true;
        $headerPath = $classHeaders[$className] ?? $fallbackHeaderPath;
        $result = $this->generate($headerPath, $className, $includePaths, $allowedClasses, $classHeaders);
        $phpClass = $result->phpClass;
        if ($result->status !== 'ok' || $phpClass === null) {
            return [];
        }

        $methods = [];
        if ($phpClass->parent !== null && $phpClass->parent !== '' && $phpClass->parent !== $className) {
            $methods = $this->collectInheritedMethods(
                $phpClass->parent,
                $headerPath,
                $includePaths,
                $allowedClasses,
                $classHeaders,
                $visited,
            );
        }

        foreach ($phpClass->methods as $method) {
            if ($method->name === '__construct') {
                continue;
            }

            $methods[$method->name] = $method;
        }

        return $methods;
    }

    private function isCompatibleInheritedMethod(PhpMethod $child, PhpMethod $parent): bool
    {
        if ($child->isStatic !== $parent->isStatic) {
            return false;
        }

        if ($parent->access === 'public' && $child->access !== 'public') {
            return false;
        }

        if (count($child->parameters) < count($parent->parameters)) {
            return false;
        }

        if ($this->requiredParameterCount($child) > $this->requiredParameterCount($parent)) {
            return false;
        }

        foreach ($parent->parameters as $index => $parentParameter) {
            $childParameter = $child->parameters[$index] ?? null;
            if ($childParameter === null) {
                return false;
            }

            if ($childParameter->phpType !== $parentParameter->phpType) {
                return false;
            }

            if ($parentParameter->hasDefault && !$childParameter->hasDefault) {
                return false;
            }
        }

        for ($index = count($parent->parameters); $index < count($child->parameters); $index++) {
            if (!($child->parameters[$index]->hasDefault ?? false)) {
                return false;
            }
        }

        if ($child->returnType !== $parent->returnType) {
            return false;
        }

        return true;
    }

    private function requiredParameterCount(PhpMethod $method): int
    {
        $count = 0;

        foreach ($method->parameters as $parameter) {
            if ($parameter->hasDefault) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return array{
     *   is_copy_constructible: bool,
     *   has_public_constructor: bool,
     *   has_public_default_constructor: bool,
     *   has_public_destructor: bool
     * }
     */
    private function analyzeLifecycleCapabilities(string $headerPath, string $className, bool $isStruct): array
    {
        $resolved = $this->resolveClassDefinitionSource($headerPath, $className);
        if ($resolved === null) {
            return [
                'is_copy_constructible' => true,
                'has_public_constructor' => true,
                'has_public_default_constructor' => true,
                'has_public_destructor' => true,
            ];
        }

        $sourceContents = $resolved['contents'];
        $classBody = is_array($resolved['body'] ?? null) && is_string($resolved['body']['body'] ?? null)
            ? $resolved['body']['body']
            : null;
        $defaultAccess = $resolved['body'] !== null && $resolved['body']['kind'] === 'struct'
            ? 'public'
            : ($isStruct ? 'public' : 'private');
        $lifecycleSegments = $classBody !== null
            ? $this->topLevelClassSegments($classBody, $defaultAccess)
            : $this->lifecycleAccessBlocks($sourceContents);

        $hasExplicitConstructor = false;
        $hasPublicConstructor = false;
        $hasPublicDefaultConstructor = false;
        $hasExplicitDestructor = false;
        $hasPublicDestructor = true;
        $isCopyConstructible = !$this->containsCopyDisablingMacro($classBody ?? $sourceContents, $className);

        foreach ($lifecycleSegments as $segmentInfo) {
            $access = $segmentInfo['access'];
            $segment = $segmentInfo['segment'];

            foreach ($this->constructorSignatures($segment, $className) as $constructorSignature) {
                $hasExplicitConstructor = true;

                if ($this->isCopyConstructorSignature($constructorSignature, $className)) {
                    if ($access !== 'public') {
                        $isCopyConstructible = false;
                    }
                    continue;
                }

                if ($this->isMoveConstructorSignature($constructorSignature, $className)) {
                    continue;
                }

                if ($access === 'public') {
                    $hasPublicConstructor = true;
                    if ($this->isDefaultConstructorSignature($constructorSignature)) {
                        $hasPublicDefaultConstructor = true;
                    }
                }
            }

            if ($this->containsDestructorSignature($segment, $className)) {
                $hasExplicitDestructor = true;
                if ($access !== 'public') {
                    $hasPublicDestructor = false;
                }
            }
        }

        if (!$hasExplicitConstructor) {
            $hasPublicConstructor = $defaultAccess === 'public';
            $hasPublicDefaultConstructor = $defaultAccess === 'public';
        }

        if (!$hasExplicitDestructor) {
            $hasPublicDestructor = true;
        }

        return [
            'is_copy_constructible' => $isCopyConstructible,
            'has_public_constructor' => $hasPublicConstructor,
            'has_public_default_constructor' => $hasPublicDefaultConstructor,
            'has_public_destructor' => $hasPublicDestructor,
        ];
    }

    /**
     * @param list<array<string, mixed>> $methods
     * @return list<array<string, mixed>>
     */
    private function annotateConstructorVariants(array $methods, string $headerPath, string $className, bool $isStruct): array
    {
        $resolved = $this->resolveClassDefinitionSource($headerPath, $className);
        if ($resolved === null) {
            return $methods;
        }

        $classBody = is_array($resolved['body'] ?? null) && is_string($resolved['body']['body'] ?? null)
            ? $resolved['body']['body']
            : null;
        $defaultAccess = $resolved['body'] !== null && $resolved['body']['kind'] === 'struct'
            ? 'public'
            : ($isStruct ? 'public' : 'private');
        $segments = $classBody !== null
            ? $this->topLevelClassSegments($classBody, $defaultAccess)
            : $this->lifecycleAccessBlocks($resolved['contents']);
        $metadata = $this->constructorVariantMetadata($segments, $className);
        if ($metadata === []) {
            return $methods;
        }

        foreach ($methods as &$method) {
            if (($method['name'] ?? null) !== $className) {
                continue;
            }

            $key = $this->methodVariantKey($method);
            $variantMetadata = $metadata[$key] ?? null;
            if ($variantMetadata === null) {
                continue;
            }

            $method['access'] = $variantMetadata['access'];
            $method['is_deleted'] = $variantMetadata['is_deleted'];
        }
        unset($method);

        return $methods;
    }

    /**
     * @return array{path: string, contents: string, body: array{kind: string, body: string}|null}|null
     */
    private function resolveClassDefinitionSource(string $headerPath, string $className): ?array
    {
        $queue = [$headerPath];
        $visited = [];
        $fallback = null;

        while ($queue !== []) {
            $path = array_shift($queue);
            if (!is_string($path) || $path === '' || isset($visited[$path])) {
                continue;
            }
            $visited[$path] = true;

            $contents = @file_get_contents($path);
            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $body = $this->extractClassBody($contents, $className);
            if ($body !== null) {
                return [
                    'path' => $path,
                    'contents' => $contents,
                    'body' => $body,
                ];
            }

            if (preg_match('/\b' . preg_quote($className, '/') . '\b/', $contents) === 1 && $fallback === null) {
                $fallback = [
                    'path' => $path,
                    'contents' => $contents,
                    'body' => null,
                ];
            }

            foreach ($this->resolveIncludedHeaders($path, $contents) as $includePath) {
                if (!isset($visited[$includePath])) {
                    $queue[] = $includePath;
                }
            }
        }

        return $fallback;
    }

    /**
     * @return list<string>
     */
    private function resolveIncludedHeaders(string $headerPath, string $contents): array
    {
        $matchCount = preg_match_all('/^\s*#\s*include\s*[<"]([^">]+)[">]/m', $contents, $matches);
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        $candidates = [];
        foreach ($matches[1] as $include) {
            if (!is_string($include) || $include === '') {
                continue;
            }

            foreach ($this->candidateIncludePaths($headerPath, $include) as $candidate) {
                $real = realpath($candidate);
                if ($real !== false && is_file($real)) {
                    $candidates[] = $real;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return list<string>
     */
    private function candidateIncludePaths(string $headerPath, string $include): array
    {
        $paths = [];
        $headerDir = dirname($headerPath);
        $basename = basename($include);

        $paths[] = $headerDir . '/' . $include;
        $paths[] = $headerDir . '/' . $basename;

        if (preg_match('~^(.*?/QtCore\\.framework/Headers)(?:/.*)?$~', $headerPath, $matches) === 1) {
            $frameworkRoot = $matches[1];
            $paths[] = $frameworkRoot . '/' . $include;
            $paths[] = $frameworkRoot . '/' . $basename;

            if (str_starts_with($include, 'QtCore/')) {
                $paths[] = $frameworkRoot . '/' . substr($include, strlen('QtCore/'));
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array{kind: string, body: string}|null
     */
    private function extractClassBody(string $contents, string $className): ?array
    {
        $pattern = sprintf(
            '/(?:^|\n)\s*(class|struct)\s+(?:[A-Za-z_][A-Za-z0-9_]*\s+)*%s\b(?:\s*:[^{]+)?\s*\{/s',
            preg_quote($className, '/'),
        );

        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $kind = is_string($matches[1][0] ?? null) ? $matches[1][0] : 'class';
        $matchText = is_string($matches[0][0] ?? null) ? $matches[0][0] : null;
        $matchOffset = is_int($matches[0][1] ?? null) ? $matches[0][1] : null;
        if ($matchText === null || $matchOffset === null) {
            return null;
        }

        $braceOffset = strpos($matchText, '{');
        if ($braceOffset === false) {
            return null;
        }

        $bodyStart = $matchOffset + $braceOffset + 1;
        $depth = 1;
        $length = strlen($contents);

        for ($index = $bodyStart; $index < $length; $index++) {
            $char = $contents[$index];

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return [
                        'kind' => $kind,
                        'body' => substr($contents, $bodyStart, $index - $bodyStart),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{access: string, segment: string}>
     */
    private function topLevelClassSegments(string $body, string $defaultAccess): array
    {
        $segments = [];
        $access = $defaultAccess;
        $buffer = '';
        $braceDepth = 0;

        foreach (preg_split("/(\r?\n)/", $body) ?: [] as $line) {
            if ($braceDepth === 0 && preg_match('/^\s*(public|protected|private)\s*:\s*(.*)$/', $line, $matches) === 1) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $segments[] = ['access' => $access, 'segment' => $trimmed];
                    $buffer = '';
                }

                $access = $matches[1];
                $remainder = trim((string) ($matches[2] ?? ''));
                if ($remainder !== '') {
                    $buffer .= $remainder . "\n";
                }
                continue;
            }

            $buffer .= $line . "\n";
            $braceDepth += substr_count($line, '{');
            $braceDepth -= substr_count($line, '}');

            if ($braceDepth === 0) {
                $trimmed = trim($buffer);
                if ($trimmed !== '' && (str_contains($trimmed, ';') || str_ends_with($trimmed, '}'))) {
                    $segments[] = ['access' => $access, 'segment' => $trimmed];
                    $buffer = '';
                }
            }
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $segments[] = ['access' => $access, 'segment' => $trimmed];
        }

        return $segments;
    }

    private function containsCopyDisablingMacro(string $segment, string $className): bool
    {
        return preg_match(
            '/Q(?:_EVENT)?_DISABLE_COPY(?:_MOVE)?\(\s*' . preg_quote($className, '/') . '\s*\)/',
            $segment,
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function constructorSignatures(string $segment, string $className): array
    {
        $matchCount = preg_match_all(
            '/(?<!~)\b' . preg_quote($className, '/') . '\s*\((.*?)\)\s*(?:noexcept\b[^;{]*)?(?:=\s*(?:default|delete)\s*)?(?:;|\{)/s',
            $segment,
            $matches,
        );
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $value): string => is_string($value) ? trim($value) : '',
            $matches[0],
        ), static fn(string $value): bool => $value !== ''));
    }

    /**
     * @param list<array{access: string, segment: string}> $segments
     * @return array<string, array{access: string, is_deleted: bool}>
     */
    private function constructorVariantMetadata(array $segments, string $className): array
    {
        $metadata = [];

        foreach ($segments as $segmentInfo) {
            $access = $segmentInfo['access'];
            $segment = $segmentInfo['segment'];

            foreach ($this->constructorDeclarations($segment, $className) as $declaration) {
                $key = $this->parameterSignatureKey($declaration['parameters']);
                if ($key === null || isset($metadata[$key])) {
                    continue;
                }

                $metadata[$key] = [
                    'access' => $access,
                    'is_deleted' => $declaration['is_deleted'],
                ];
            }
        }

        return $metadata;
    }

    /**
     * @return list<array{parameters: list<string>, is_deleted: bool}>
     */
    private function constructorDeclarations(string $segment, string $className): array
    {
        $matchCount = preg_match_all(
            '/(?<!~)\b' . preg_quote($className, '/') . '\s*\((.*?)\)\s*([^;{]*)\s*(?:;|\{)/s',
            $segment,
            $matches,
            PREG_SET_ORDER,
        );
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        $declarations = [];
        foreach ($matches as $match) {
            $parameterList = is_string($match[1] ?? null) ? $match[1] : '';
            $suffix = is_string($match[2] ?? null) ? $match[2] : '';

            $declarations[] = [
                'parameters' => $this->normalizeParameterDeclarations($parameterList),
                'is_deleted' => str_contains($suffix, '= delete') || str_contains($suffix, 'Q_DECL_EQ_DELETE'),
            ];
        }

        return $declarations;
    }

    /**
     * @param array<string, mixed> $method
     */
    private function methodVariantKey(array $method): ?string
    {
        $parameters = is_array($method['parameters'] ?? null) ? $method['parameters'] : [];
        $types = [];

        foreach ($parameters as $parameter) {
            $type = is_string($parameter['type'] ?? null) ? $parameter['type'] : '';
            if ($type === '') {
                return null;
            }

            $types[] = $this->normalizeSignatureType($type);
        }

        return $this->parameterSignatureKey($types);
    }

    /**
     * @return list<string>
     */
    private function normalizeParameterDeclarations(string $parameterList): array
    {
        $parameterList = trim($parameterList);
        if ($parameterList === '') {
            return [];
        }

        $parameters = [];
        foreach ($this->splitTopLevelParameterList($parameterList) as $parameter) {
            $parameter = $this->stripTopLevelDefaultValue($parameter);
            $parameter = $this->stripParameterName($parameter);
            if ($parameter === '') {
                continue;
            }

            $parameters[] = $this->normalizeSignatureType($parameter);
        }

        return $parameters;
    }

    /**
     * @param list<string> $types
     */
    private function parameterSignatureKey(array $types): ?string
    {
        return implode('|', $types);
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelParameterList(string $parameterList): array
    {
        $parts = [];
        $buffer = '';
        $angleDepth = 0;
        $parenDepth = 0;
        $braceDepth = 0;
        $bracketDepth = 0;
        $length = strlen($parameterList);

        for ($index = 0; $index < $length; $index++) {
            $char = $parameterList[$index];

            switch ($char) {
                case '<':
                    $angleDepth++;
                    break;
                case '>':
                    $angleDepth = max(0, $angleDepth - 1);
                    break;
                case '(':
                    $parenDepth++;
                    break;
                case ')':
                    $parenDepth = max(0, $parenDepth - 1);
                    break;
                case '{':
                    $braceDepth++;
                    break;
                case '}':
                    $braceDepth = max(0, $braceDepth - 1);
                    break;
                case '[':
                    $bracketDepth++;
                    break;
                case ']':
                    $bracketDepth = max(0, $bracketDepth - 1);
                    break;
                case ',':
                    if ($angleDepth === 0 && $parenDepth === 0 && $braceDepth === 0 && $bracketDepth === 0) {
                        $trimmed = trim($buffer);
                        if ($trimmed !== '') {
                            $parts[] = $trimmed;
                        }
                        $buffer = '';
                        continue 2;
                    }
                    break;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $parts[] = $trimmed;
        }

        return $parts;
    }

    private function stripTopLevelDefaultValue(string $parameter): string
    {
        $angleDepth = 0;
        $parenDepth = 0;
        $braceDepth = 0;
        $bracketDepth = 0;
        $length = strlen($parameter);

        for ($index = 0; $index < $length; $index++) {
            $char = $parameter[$index];

            switch ($char) {
                case '<':
                    $angleDepth++;
                    break;
                case '>':
                    $angleDepth = max(0, $angleDepth - 1);
                    break;
                case '(':
                    $parenDepth++;
                    break;
                case ')':
                    $parenDepth = max(0, $parenDepth - 1);
                    break;
                case '{':
                    $braceDepth++;
                    break;
                case '}':
                    $braceDepth = max(0, $braceDepth - 1);
                    break;
                case '[':
                    $bracketDepth++;
                    break;
                case ']':
                    $bracketDepth = max(0, $bracketDepth - 1);
                    break;
                case '=':
                    if ($angleDepth === 0 && $parenDepth === 0 && $braceDepth === 0 && $bracketDepth === 0) {
                        return trim(substr($parameter, 0, $index));
                    }
                    break;
            }
        }

        return trim($parameter);
    }

    private function stripParameterName(string $parameter): string
    {
        if (preg_match('/^(.*(?:\*|&|&&))([A-Za-z_][A-Za-z0-9_]*)$/', $parameter, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        if (preg_match('/^(.*\S)\s+([A-Za-z_][A-Za-z0-9_]*)$/', $parameter, $matches) !== 1) {
            return trim($parameter);
        }

        return trim((string) $matches[1]);
    }

    private function normalizeSignatureType(string $type): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', trim($type)) ?? trim($type));
        $normalized = preg_replace('/\s*([*&])\s*/', '$1', $normalized) ?? $normalized;

        return $normalized;
    }

    private function containsDestructorSignature(string $segment, string $className): bool
    {
        return preg_match('/~\s*' . preg_quote($className, '/') . '\s*\(/', $segment) === 1;
    }

    private function isCopyConstructorSignature(string $signature, string $className): bool
    {
        return preg_match(
            '/\b' . preg_quote($className, '/') . '\s*\(\s*const\s+' . preg_quote($className, '/') . '\s*&(?:\s+[A-Za-z_][A-Za-z0-9_]*)?\s*\)/',
            $signature,
        ) === 1;
    }

    private function isMoveConstructorSignature(string $signature, string $className): bool
    {
        return preg_match(
            '/\b' . preg_quote($className, '/') . '\s*\(\s*' . preg_quote($className, '/') . '\s*&&(?:\s+[A-Za-z_][A-Za-z0-9_]*)?\s*\)/',
            $signature,
        ) === 1;
    }

    private function isDefaultConstructorSignature(string $signature): bool
    {
        return preg_match('/\(\s*\)/', $signature) === 1;
    }

    /**
     * @return list<array{access: string, segment: string}>
     */
    private function lifecycleAccessBlocks(string $contents): array
    {
        $matchCount = preg_match_all(
            '/\b(public|protected|private)\s*:\s*(.*?)(?=\b(?:public|protected|private)\s*:|\z)/s',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        $blocks = [];
        foreach ($matches as $match) {
            $access = is_string($match[1] ?? null) ? trim($match[1]) : '';
            $segment = is_string($match[2] ?? null) ? trim($match[2]) : '';
            if ($access === '' || $segment === '') {
                continue;
            }
            $blocks[] = [
                'access' => $access,
                'segment' => $segment,
            ];
        }

        return $blocks;
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
    private function discoverFlagAliases(string $headerPath, ?string $className = null): array
    {
        $contents = $this->introspectionContents($headerPath, $className);
        if ($contents === '') {
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
    private function discoverEnumNames(string $headerPath, ?string $className = null): array
    {
        $contents = $this->introspectionContents($headerPath, $className);
        if ($contents === '') {
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

    private function introspectionContents(string $headerPath, ?string $className = null): string
    {
        if ($className !== null && $className !== '') {
            $resolved = $this->resolveClassDefinitionSource($headerPath, $className);
            if (is_array($resolved) && is_array($resolved['body'] ?? null) && is_string($resolved['body']['body'] ?? null)) {
                return $resolved['body']['body'];
            }
        }

        $contents = @file_get_contents($headerPath);
        if (!is_string($contents) || $contents === '') {
            return '';
        }

        return $contents;
    }
}
