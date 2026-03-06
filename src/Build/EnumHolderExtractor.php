<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use CParser\ClassCursor;
use CParser\EnumCursor;
use CParser\NamespaceCursor;
use CParser\TranslationUnit;
use CParser\TranslationUnitFlags;
use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Scanning\HeaderCandidate;

class EnumHolderExtractor
{
    /**
     * @param list<string> $includePaths
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $skippedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, string> $classNamespaces
     */
    public function extract(
        array $includePaths,
        array $acceptedCandidates,
        array $skippedClasses,
        array $preparedClassDataByClass,
        array $classNamespaces,
    ): EnumHolderRegistry {
        /** @var array<string, EnumHolderDefinition> $holders */
        $holders = [];
        /** @var array<string, string> $classModules */
        $classModules = [];
        /** @var array<string, bool> $knownClassNames */
        $knownClassNames = [];
        foreach ($acceptedCandidates as $candidate) {
            $classModules[$candidate->className] = $candidate->module;
            $knownClassNames[$candidate->className] = true;
        }

        foreach ($preparedClassDataByClass as $className => $classData) {
            $module = $classModules[$className] ?? 'QtCore';
            $namespace = $classNamespaces[$className] ?? 'Qt\\Core';
            foreach ($this->extractClassOwnedHolders($classData, $module, $namespace) as $holder) {
                $holders[$holder->cppType] ??= $holder;
            }
        }

        /** @var array<string, string> $headerModules */
        $headerModules = [];
        foreach ($acceptedCandidates as $candidate) {
            $headerModules[$candidate->parseHeader] ??= $candidate->module;
        }
        foreach ($skippedClasses as $entry) {
            $header = is_string($entry['header'] ?? null) ? $entry['header'] : null;
            $module = is_string($entry['module'] ?? null) ? $entry['module'] : null;
            if ($header === null || $header === '' || $module === null || $module === '') {
                continue;
            }

            $headerModules[$header] ??= $module;
        }

        $builder = new ClangArgumentBuilder($includePaths);
        foreach ($headerModules as $headerPath => $fallbackModule) {
            foreach ($this->extractNamespaceOwnedHolders($builder, $headerPath, $fallbackModule, $knownClassNames) as $holder) {
                $holders[$holder->cppType] ??= $holder;
            }
        }

        ksort($holders);

        return new EnumHolderRegistry($holders);
    }

    /**
     * @param array<string, mixed> $classData
     * @return list<EnumHolderDefinition>
     */
    private function extractClassOwnedHolders(array $classData, string $module, string $namespace): array
    {
        $className = is_string($classData['name'] ?? null) ? $classData['name'] : '';
        if ($className === '') {
            return [];
        }

        /** @var array<string, list<EnumHolderConstant>> $constantsByEnum */
        $constantsByEnum = [];
        foreach ((array) ($classData['enum_constants'] ?? []) as $constant) {
            if (!is_array($constant)) {
                continue;
            }

            $enumName = is_string($constant['enum_name'] ?? null) ? trim($constant['enum_name']) : '';
            $name = is_string($constant['name'] ?? null) ? trim($constant['name']) : '';
            $value = $constant['value'] ?? null;
            if (
                !$this->isValidPhpHolderName($enumName)
                || $name === ''
                || (!is_int($value) && !is_float($value) && !is_string($value))
            ) {
                continue;
            }

            $constantsByEnum[$enumName][] = new EnumHolderConstant($name, $value);
        }

        $holders = [];
        $holderNamespace = $namespace . '\\' . $className;
        foreach ($constantsByEnum as $enumName => $constants) {
            $cppType = $className . '::' . $enumName;
            $holders[] = new EnumHolderDefinition(
                module: $module,
                cppType: $cppType,
                phpNamespace: $holderNamespace,
                phpClassName: $enumName,
                constants: $constants,
                headerPath: '',
            );
        }

        $flagAliases = is_array($classData['flag_aliases'] ?? null) ? $classData['flag_aliases'] : [];
        foreach ($flagAliases as $alias => $sourceEnum) {
            if (
                !is_string($alias)
                || !$this->isValidPhpHolderName($alias)
                || !is_string($sourceEnum)
                || !$this->isValidPhpHolderName($sourceEnum)
            ) {
                continue;
            }

            $constants = $constantsByEnum[$sourceEnum] ?? [];
            if ($constants === []) {
                continue;
            }

            $holders[] = new EnumHolderDefinition(
                module: $module,
                cppType: $className . '::' . $alias,
                phpNamespace: $holderNamespace,
                phpClassName: $alias,
                constants: $constants,
                isFlagAlias: true,
                sourceCppType: $className . '::' . $sourceEnum,
                headerPath: '',
            );
        }

        return $holders;
    }

    /**
     * @return list<EnumHolderDefinition>
     */
    private function extractNamespaceOwnedHolders(
        ClangArgumentBuilder $builder,
        string $headerPath,
        string $fallbackModule,
        array $knownClassNames,
    ): array
    {
        if (!is_file($headerPath)) {
            return [];
        }

        $tu = TranslationUnit::fromFile(
            $headerPath,
            $builder->build(),
            TranslationUnitFlags::SkipFunctionBodies | TranslationUnitFlags::KeepGoing,
        );

        /** @var array<string, EnumHolderDefinition> $holders */
        $holders = [];
        /** @var array<string, bool> $aliasHeaderFiles */
        $aliasHeaderFiles = [$headerPath => true];

        foreach ($tu->cursors() as $cursor) {
            if (!$cursor instanceof EnumCursor) {
                continue;
            }

            $owner = $this->enumOwner($cursor);
            if ($owner === null) {
                continue;
            }

            if ($owner['kind'] !== 'namespace') {
                continue;
            }

            if (!$this->isSupportedNamespaceOwner($owner['cpp_prefix'], $knownClassNames)) {
                continue;
            }

            $enumName = trim($cursor->getSpelling());
            if (!$this->isValidPhpHolderName($enumName)) {
                continue;
            }

            $constants = [];
            foreach ($cursor->getConstants() as $constant) {
                $name = trim($constant->getSpelling());
                $value = $constant->getValue();
                if ($name === '' || (!is_int($value) && !is_float($value) && !is_string($value))) {
                    continue;
                }

                $constants[] = new EnumHolderConstant($name, $value);
            }

            if ($constants === []) {
                continue;
            }

            $module = $this->moduleForHeaderPath((string) ($cursor->getLocation()['file'] ?? $headerPath), $fallbackModule);
            $enumHeaderPath = (string) ($cursor->getLocation()['file'] ?? $headerPath);
            if ($enumHeaderPath !== '') {
                $aliasHeaderFiles[$enumHeaderPath] = true;
            }
            $cppType = $owner['cpp_prefix'] . '::' . $enumName;
            $phpNamespace = $owner['cpp_prefix'] === 'Qt'
                ? 'Qt'
                : $this->moduleNamespaceFor($module) . '\\' . str_replace('::', '\\', $owner['cpp_prefix']);

            $holders[$cppType] ??= new EnumHolderDefinition(
                module: $module,
                cppType: $cppType,
                phpNamespace: $phpNamespace,
                phpClassName: $enumName,
                constants: $constants,
                headerPath: $headerPath,
            );
        }

        foreach ($this->discoverNamespaceFlagAliasesFromFiles(array_keys($aliasHeaderFiles)) as $aliasDefinition) {
            $ownerCppPrefix = $aliasDefinition['owner'];
            $module = $this->moduleForHeaderPath($aliasDefinition['header'], $fallbackModule);
            $ownerPhpNamespace = $ownerCppPrefix === 'Qt'
                ? 'Qt'
                : $this->moduleNamespaceFor($module) . '\\' . str_replace('::', '\\', $ownerCppPrefix);

            $sourceCppType = str_contains($aliasDefinition['source'], '::')
                ? $aliasDefinition['source']
                : $ownerCppPrefix . '::' . $aliasDefinition['source'];
            $sourceHolder = $holders[$sourceCppType] ?? null;
            if (!$sourceHolder instanceof EnumHolderDefinition) {
                continue;
            }

            $cppType = $ownerCppPrefix . '::' . $aliasDefinition['alias'];
            $holders[$cppType] ??= new EnumHolderDefinition(
                module: $module,
                cppType: $cppType,
                phpNamespace: $ownerPhpNamespace,
                phpClassName: $aliasDefinition['alias'],
                constants: $sourceHolder->constants,
                isFlagAlias: true,
                sourceCppType: $sourceCppType,
                headerPath: $aliasDefinition['header'],
            );
        }

        return array_values($holders);
    }

    /**
     * @return array{kind: string, cpp_prefix: string}|null
     */
    private function enumOwner(EnumCursor $cursor): ?array
    {
        $parent = $cursor->getParent();
        if ($parent instanceof NamespaceCursor) {
            $names = [];
            $current = $parent;
            while ($current instanceof NamespaceCursor) {
                array_unshift($names, $current->getSpelling());
                $next = $current->getParent();
                $current = $next instanceof NamespaceCursor ? $next : null;
            }

            $cppPrefix = implode('::', array_values(array_filter($names, static fn(string $value): bool => $value !== '')));
            if ($cppPrefix === '') {
                return null;
            }

            if ($cppPrefix === 'Qt') {
                return [
                    'kind' => 'namespace',
                    'cpp_prefix' => $cppPrefix,
                ];
            }

            return [
                'kind' => 'namespace',
                'cpp_prefix' => $cppPrefix,
            ];
        }

        if ($parent instanceof ClassCursor) {
            return [
                'kind' => 'class',
                'cpp_prefix' => $parent->getSpelling(),
            ];
        }

        return null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function discoverNamespaceFlagAliases(string $contents): array
    {
        $stripped = preg_replace('!/\*.*?\*/!s', '', $contents) ?? $contents;
        $stripped = preg_replace('/\/\/.*$/m', '', $stripped) ?? $stripped;
        $tokenPattern = '/namespace\s+([A-Za-z_][A-Za-z0-9_]*)|class\s+([A-Za-z_][A-Za-z0-9_]*)|struct\s+([A-Za-z_][A-Za-z0-9_]*)|Q_DECLARE_FLAGS\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*,\s*([A-Za-z_:][A-Za-z0-9_:]*)\s*\)|\{|\}/';
        preg_match_all($tokenPattern, $stripped, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $scopeNames = [];
        $scopeKinds = [];
        $blockStack = [];
        $pendingScope = null;
        $aliases = [];

        foreach ($matches as $match) {
            $token = $match[0][0];
            if (str_starts_with($token, 'namespace ')) {
                $pendingScope = ['kind' => 'namespace', 'name' => $match[1][0]];
                continue;
            }

            if (str_starts_with($token, 'class ') || str_starts_with($token, 'struct ')) {
                $pendingScope = ['kind' => 'class', 'name' => $match[2][0] !== '' ? $match[2][0] : $match[3][0]];
                continue;
            }

            if ($token === '{') {
                if (is_array($pendingScope)) {
                    $scopeNames[] = $pendingScope['name'];
                    $scopeKinds[] = $pendingScope['kind'];
                    $blockStack[] = true;
                    $pendingScope = null;
                } else {
                    $blockStack[] = false;
                }

                continue;
            }

            if ($token === '}') {
                $owned = array_pop($blockStack);
                if ($owned === true) {
                    array_pop($scopeNames);
                    array_pop($scopeKinds);
                }

                continue;
            }

            $alias = $match[4][0] ?? '';
            $source = $match[5][0] ?? '';
            if ($alias === '' || $source === '') {
                continue;
            }

            $namespaceParts = [];
            foreach ($scopeKinds as $index => $kind) {
                if ($kind !== 'namespace') {
                    break;
                }

                $namespaceParts[] = $scopeNames[$index];
            }

            if ($namespaceParts === []) {
                continue;
            }

            $owner = implode('::', $namespaceParts);
            $aliases[$owner][$alias] = $source;
        }

        return $aliases;
    }

    /**
     * @param list<string> $headerPaths
     * @return list<array{header: string, owner: string, alias: string, source: string}>
     */
    private function discoverNamespaceFlagAliasesFromFiles(array $headerPaths): array
    {
        $definitions = [];

        foreach (array_values(array_unique($headerPaths)) as $candidatePath) {
            if (!is_file($candidatePath)) {
                continue;
            }

            foreach ($this->discoverNamespaceFlagAliases((string) file_get_contents($candidatePath)) as $owner => $aliases) {
                foreach ($aliases as $alias => $source) {
                    if (!$this->isValidPhpHolderName($alias) || trim($source) === '') {
                        continue;
                    }

                    $definitions[] = [
                        'header' => $candidatePath,
                        'owner' => $owner,
                        'alias' => $alias,
                        'source' => trim($source),
                    ];
                }
            }
        }

        return $definitions;
    }

    private function isValidPhpHolderName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', trim($name)) === 1;
    }

    /**
     * @param array<string, bool> $knownClassNames
     */
    private function isSupportedNamespaceOwner(string $ownerCppPrefix, array $knownClassNames): bool
    {
        $topLevel = explode('::', $ownerCppPrefix)[0] ?? '';
        if ($topLevel === '') {
            return false;
        }

        if ($topLevel !== 'Qt' && preg_match('/^Q[A-Z][A-Za-z0-9_]*$/', $topLevel) !== 1) {
            return false;
        }

        if ($topLevel !== 'Qt' && isset($knownClassNames[$topLevel])) {
            return false;
        }

        return true;
    }

    private function moduleForHeaderPath(string $headerPath, string $fallbackModule): string
    {
        if (preg_match('/\/(Qt[A-Za-z0-9_]+)(?:\.framework(?:\/Versions\/[^\/]+)?\/Headers|\/)/', $headerPath, $matches) === 1) {
            return $matches[1];
        }

        return $fallbackModule;
    }

    private function moduleNamespaceFor(string $module): string
    {
        return 'Qt\\' . preg_replace('/^Qt/', '', $module);
    }
}
