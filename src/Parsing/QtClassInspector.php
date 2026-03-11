<?php

declare(strict_types=1);

namespace QtBuilder\Parsing;

use CParser\Access;
use CParser\ClassCursor;
use CParser\Cursor;
use CParser\CursorKind;
use CParser\FieldCursor;
use CParser\MethodCursor;
use CParser\NamespaceCursor;
use CParser\ParameterCursor;
use CParser\TypeAliasCursor;
use CParser\TranslationUnit;
use CParser\TranslationUnitFlags;

/**
 * Parses a C++ header and extracts structured metadata for a given class.
 *
 * This is the reusable core that any command, service, or code-generator
 * can depend on -- it has no dependency on Symfony Console or any I/O layer.
 */
class QtClassInspector
{
    private TranslationUnit $tu;
    private readonly bool $supportsAnnotations;
    private readonly bool $supportsBaseSpecifiers;
    private readonly bool $supportsAliases;
    private readonly bool $supportsConstructorSemantics;

    public function __construct(
        private readonly ClangArgumentBuilder $argBuilder,
    ) {
        $this->supportsAnnotations = method_exists(Cursor::class, 'getAnnotations');
        $this->supportsBaseSpecifiers = method_exists(ClassCursor::class, 'getBaseSpecifiers');
        $this->supportsAliases = method_exists(TranslationUnit::class, 'aliases');
        $this->supportsConstructorSemantics = method_exists(MethodCursor::class, 'isDeleted')
            && method_exists(MethodCursor::class, 'isDefaulted')
            && method_exists(MethodCursor::class, 'isExplicit')
            && method_exists(MethodCursor::class, 'isCopyConstructor')
            && method_exists(MethodCursor::class, 'isMoveConstructor')
            && method_exists(MethodCursor::class, 'isDefaultConstructor');
    }

    /**
     * Parse the given header file into a translation unit.
     *
     * Must be called before {@see inspect()} or {@see findClass()}.
     */
    public function parse(string $headerPath): void
    {
        $flags = TranslationUnitFlags::SkipFunctionBodies | TranslationUnitFlags::KeepGoing;
        if ($this->supportsAnnotations) {
            // Only request implicit attributes when the extension can surface them.
            $flags |= TranslationUnitFlags::VisitImplicitAttributes;
        }

        $this->tu = TranslationUnit::fromFile(
            $headerPath,
            $this->argBuilder->build(),
            $flags,
        );
    }

    /**
     * Locate a class by name in the parsed translation unit.
     *
     * Prefers the class definition over a forward declaration when both exist.
     */
    public function findClass(string $className, ?string $preferredHeaderPath = null): ?ClassCursor
    {
        $preferredHeader = $this->normalizePath($preferredHeaderPath);
        $candidate = null;
        $candidateScore = PHP_INT_MIN;

        foreach ($this->tu->cursors() as $cursor) {
            if (!$cursor instanceof ClassCursor || !$this->classMatchesLookup($cursor, $className)) {
                continue;
            }

            $score = $this->classMatchScore($cursor, $className, $preferredHeader);
            if ($candidate !== null && $score <= $candidateScore) {
                continue;
            }

            $candidate = $cursor;
            $candidateScore = $score;
        }

        return $candidate;
    }

    /**
     * Parse a header and return structured data for the requested class.
     *
     * Convenience method that combines {@see parse()}, {@see findClass()},
     * and {@see extractClassData()} in one call.
     *
     * @return array{
     *   name: string,
     *   is_abstract: bool,
     *   is_struct: bool,
     *   bases: list<string>,
     *   base_specifiers?: list<array{type: string, access: string, is_virtual: bool}>,
     *   properties: list<array<string, mixed>>,
     *   methods: list<array<string, mixed>>,
     *   enum_constants: list<array<string, mixed>>,
     *   enum_names?: list<string>,
     *   flag_aliases?: array<string, string>
     * }|null
     */
    public function inspect(string $headerPath, string $className): ?array
    {
        $this->parse($headerPath);

        $classCursor = $this->findClass($className, $headerPath);

        if ($classCursor === null) {
            return null;
        }

        if ($this->hasDirectMembers($classCursor)) {
            return $this->extractClassData($classCursor);
        }

        return $this->extractClassDataFromTranslationUnit($classCursor);
    }

    /**
     * Parse a header and return both owner class data plus referenced nested class data
     * discovered from the same owner cursor in a single translation-unit parse.
     *
     * @return array{
     *   class_data: array{
     *     name: string,
     *     qualified_name: string,
     *     is_abstract: bool,
     *     is_struct: bool,
     *     bases: list<string>,
     *     base_specifiers?: list<array{type: string, access: string, is_virtual: bool}>,
     *     properties: list<array<string, mixed>>,
     *     methods: list<array<string, mixed>>,
     *     enum_constants: list<array<string, mixed>>,
     *     enum_names?: list<string>,
     *     flag_aliases?: array<string, string>
     *   },
     *   referenced_nested_class_data: list<array{
     *     name: string,
     *     qualified_name: string,
     *     is_abstract: bool,
     *     is_struct: bool,
     *     bases: list<string>,
     *     base_specifiers?: list<array{type: string, access: string, is_virtual: bool}>,
     *     properties: list<array<string, mixed>>,
     *     methods: list<array<string, mixed>>,
     *     enum_constants: list<array<string, mixed>>,
     *     enum_names?: list<string>,
     *     flag_aliases?: array<string, string>
     *   }>
     * }|null
     */
    public function inspectWithReferencedNestedClasses(string $headerPath, string $className): ?array
    {
        $this->parse($headerPath);

        $classCursor = $this->findClass($className, $headerPath);
        if ($classCursor === null) {
            return null;
        }

        $classData = $this->hasDirectMembers($classCursor)
            ? $this->extractClassData($classCursor)
            : $this->extractClassDataFromTranslationUnit($classCursor);

        return [
            'class_data' => $classData,
            'referenced_nested_class_data' => $this->extractReferencedNestedClassData($classCursor, $classData),
        ];
    }

    public function locateClassHeader(string $headerPath, string $className): ?string
    {
        $this->parse($headerPath);
        $classCursor = $this->findClass($className, $headerPath);
        if ($classCursor === null) {
            return null;
        }

        $locationFile = $classCursor->getLocation()['file'] ?? null;
        if (is_string($locationFile) && $locationFile !== '') {
            $real = realpath($locationFile);

            return $real !== false ? $real : $locationFile;
        }

        $real = realpath($headerPath);

        return $real !== false ? $real : $headerPath;
    }

    private function hasDirectMembers(ClassCursor $class): bool
    {
        foreach ($class->getFields() as $_) {
            return true;
        }

        foreach ($class->getMethods() as $_) {
            return true;
        }

        foreach ($class->getChildren(CursorKind::CXXConstructor) as $_) {
            return true;
        }

        return false;
    }

    /**
     * Some Qt classes are exposed by ext-cparser as a forward-declaration ClassCursor plus
     * member nodes in the translation unit. In that case recover members by scanning the TU.
     *
     * @return array{
     *   name: string,
     *   qualified_name: string,
     *   is_abstract: bool,
     *   is_struct: bool,
     *   bases: list<string>,
     *   base_specifiers?: list<array{type: string, access: string, is_virtual: bool}>,
     *   properties: list<array<string, mixed>>,
     *   methods: list<array<string, mixed>>,
     *   enum_constants: list<array<string, mixed>>,
     *   enum_names?: list<string>,
     *   flag_aliases?: array<string, string>
     * }
     */
    private function extractClassDataFromTranslationUnit(ClassCursor $classCursor): array
    {
        $className = $classCursor->getSpelling();
        $qualifiedName = $this->qualifiedCursorName($classCursor);
        $properties = [];
        $propertySignatures = [];
        foreach ($this->tu->cursors(CursorKind::FieldDecl) as $field) {
            if (!$field instanceof FieldCursor || !$this->belongsToClass($field, $classCursor)) {
                continue;
            }

            $signature = $field->getSpelling() . '|' . ($field->getType()?->toString() ?? 'unknown');
            if (isset($propertySignatures[$signature])) {
                continue;
            }
            $propertySignatures[$signature] = true;

            $properties[] = $this->extractField($field);
        }

        $methods = [];
        $methodSignatures = [];

        foreach ($this->tu->cursors(CursorKind::CXXConstructor) as $ctor) {
            if (!$this->belongsToClass($ctor, $classCursor)) {
                continue;
            }

            $extracted = $this->extractConstructor($ctor, $classCursor);
            $signature = $extracted['name']
                . '|' . $extracted['return_type']
                . '|' . json_encode($extracted['parameters'])
                . '|' . ($extracted['is_const'] ? '1' : '0')
                . '|' . ($extracted['is_static'] ? '1' : '0')
                . '|' . $extracted['access'];
            if (isset($methodSignatures[$signature])) {
                continue;
            }
            $methodSignatures[$signature] = true;

            $methods[] = $extracted;
        }

        foreach ($this->tu->cursors(CursorKind::CXXMethod) as $method) {
            if (!$method instanceof MethodCursor || !$this->belongsToClass($method, $classCursor)) {
                continue;
            }

            $extracted = $this->extractMethod($method);
            $signature = $extracted['name']
                . '|' . $extracted['return_type']
                . '|' . json_encode($extracted['parameters'])
                . '|' . ($extracted['is_const'] ? '1' : '0')
                . '|' . ($extracted['is_static'] ? '1' : '0');

            if (isset($methodSignatures[$signature])) {
                continue;
            }
            $methodSignatures[$signature] = true;

            $methods[] = $extracted;
        }

        return [
            'name' => $className,
            'qualified_name' => $qualifiedName,
            'is_abstract' => $classCursor->isAbstract(),
            'is_struct' => $classCursor->isStruct(),
            'bases' => array_values(array_map(
                static fn(ClassCursor $base): string => $base->getSpelling(),
                iterator_to_array($classCursor->getBases(), false),
            )),
            'base_specifiers' => $this->extractBaseSpecifiers($classCursor),
            'properties' => $properties,
            'methods' => $methods,
            'enum_constants' => $this->extractEnumConstants($classCursor),
            'enum_names' => $this->extractEnumNames($classCursor),
            'flag_aliases' => $this->extractClassFlagAliases($classCursor),
        ];
    }

    private function belongsToClass(Cursor $cursor, ClassCursor $classCursor): bool
    {
        $parent = $cursor->getParent();
        if ($parent === null) {
            return false;
        }

        $targetName = $this->qualifiedCursorName($classCursor);
        if ($targetName !== '' && $targetName === $this->qualifiedCursorName($parent)) {
            return true;
        }

        return $parent->getSpelling() === $classCursor->getSpelling()
            && $this->cursorLocationFile($parent) === $this->cursorLocationFile($classCursor);
    }

    private function classMatchesLookup(ClassCursor $class, string $className): bool
    {
        if (str_contains($className, '::')) {
            return $this->qualifiedCursorName($class) === $className;
        }

        return $class->getSpelling() === $className;
    }

    private function classMatchScore(ClassCursor $class, string $className, ?string $preferredHeader): int
    {
        $score = 0;
        if (str_contains($className, '::') && $this->qualifiedCursorName($class) === $className) {
            $score += 100;
        }

        if ($preferredHeader !== null && $this->cursorLocationFile($class) === $preferredHeader) {
            $score += 50;
        }

        if ($class->getParent() instanceof NamespaceCursor || $class->getParent() instanceof ClassCursor) {
            $score += 5;
        }

        if ($class->isDefinition()) {
            $score += 10;
        }

        return $score;
    }

    private function qualifiedCursorName(Cursor $cursor): string
    {
        $name = trim($cursor->getSpelling());
        if ($name === '') {
            return '';
        }

        $owners = [];
        $current = $cursor->getParent();
        while ($current instanceof NamespaceCursor || $current instanceof ClassCursor) {
            $owner = trim($current->getSpelling());
            if ($owner !== '') {
                array_unshift($owners, $owner);
            }

            $current = $current->getParent();
        }

        if ($owners === []) {
            return $name;
        }

        return implode('::', [...$owners, $name]);
    }

    private function cursorLocationFile(Cursor $cursor): ?string
    {
        $file = $cursor->getLocation()['file'] ?? null;
        if (!is_string($file) || $file === '') {
            return null;
        }

        return $this->normalizePath($file);
    }

    private function normalizePath(?string $path): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }

        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    /**
     * @param array{
     *   name: string,
     *   qualified_name?: string,
     *   properties?: list<array<string, mixed>>,
     *   methods?: list<array<string, mixed>>
     * } $ownerClassData
     * @return list<array{
     *   name: string,
     *   qualified_name: string,
     *   is_abstract: bool,
     *   is_struct: bool,
     *   bases: list<string>,
     *   base_specifiers?: list<array{type: string, access: string, is_virtual: bool}>,
     *   properties: list<array<string, mixed>>,
     *   methods: list<array<string, mixed>>,
     *   enum_constants: list<array<string, mixed>>,
     *   enum_names?: list<string>,
     *   flag_aliases?: array<string, string>
     * }>
     */
    private function extractReferencedNestedClassData(ClassCursor $ownerCursor, array $ownerClassData): array
    {
        /** @var array<string, true> $referencedNestedNames */
        $referencedNestedNames = $this->referencedNestedMemberNames($ownerClassData);
        if ($referencedNestedNames === []) {
            return [];
        }

        $nestedClassData = [];
        /** @var array<string, true> $seenQualified */
        $seenQualified = [];

        foreach ($ownerCursor->getChildren() as $child) {
            if (!$child instanceof ClassCursor || !$this->belongsToClass($child, $ownerCursor)) {
                continue;
            }

            $nestedName = trim($child->getSpelling());
            if ($nestedName === '' || !isset($referencedNestedNames[$nestedName])) {
                continue;
            }

            $data = $this->hasDirectMembers($child)
                ? $this->extractClassData($child)
                : $this->extractClassDataFromTranslationUnit($child);
            $qualifiedName = is_string($data['qualified_name'] ?? null)
                ? trim((string) $data['qualified_name'])
                : '';
            if ($qualifiedName === '' || isset($seenQualified[$qualifiedName])) {
                continue;
            }

            $seenQualified[$qualifiedName] = true;
            $nestedClassData[] = $data;
        }

        return $nestedClassData;
    }

    /**
     * @param array{
     *   name: string,
     *   qualified_name?: string,
     *   properties?: list<array<string, mixed>>,
     *   methods?: list<array<string, mixed>>
     * } $ownerClassData
     * @return array<string, true>
     */
    private function referencedNestedMemberNames(array $ownerClassData): array
    {
        $ownerName = trim((string) ($ownerClassData['name'] ?? ''));
        $ownerQualifiedName = trim((string) ($ownerClassData['qualified_name'] ?? ''));
        if ($ownerName === '') {
            return [];
        }

        $ownerLookup = [$ownerName => true];
        if ($ownerQualifiedName !== '') {
            $ownerLookup[ltrim($ownerQualifiedName, ':')] = true;
        }

        /** @var array<string, true> $referenced */
        $referenced = [];
        foreach ($this->classTypeStrings($ownerClassData) as $type) {
            $trimmedType = trim($type);
            if ($trimmedType === '') {
                continue;
            }

            if (preg_match_all(
                '/(?<owner>(?:::)?(?:[A-Za-z_][A-Za-z0-9_]*::)*[A-Za-z_][A-Za-z0-9_]*)::(?<member>[A-Za-z_][A-Za-z0-9_]*)/',
                $trimmedType,
                $matches,
                PREG_SET_ORDER,
            ) === 1) {
                foreach ($matches as $match) {
                    $owner = ltrim(trim((string) ($match['owner'] ?? '')), ':');
                    $member = trim((string) ($match['member'] ?? ''));
                    if ($owner === '' || $member === '' || !isset($ownerLookup[$owner])) {
                        continue;
                    }
                    $referenced[$member] = true;
                }
            }

            $base = trim(preg_replace('/\bconst\b/', '', $trimmedType) ?? $trimmedType);
            $base = trim(preg_replace('/\s+/', ' ', $base) ?? $base);
            $base = rtrim($base, '&* ');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $base) === 1) {
                $referenced[$base] = true;
            }
        }

        return $referenced;
    }

    /**
     * @param array{
     *   properties?: list<array<string, mixed>>,
     *   methods?: list<array<string, mixed>>
     * } $ownerClassData
     * @return list<string>
     */
    private function classTypeStrings(array $ownerClassData): array
    {
        $types = [];
        foreach ((array) ($ownerClassData['properties'] ?? []) as $property) {
            $type = is_string($property['type'] ?? null) ? trim((string) $property['type']) : '';
            if ($type !== '') {
                $types[] = $type;
            }
        }

        foreach ((array) ($ownerClassData['methods'] ?? []) as $method) {
            $returnType = is_string($method['return_type'] ?? null) ? trim((string) $method['return_type']) : '';
            if ($returnType !== '') {
                $types[] = $returnType;
            }

            foreach ((array) ($method['parameters'] ?? []) as $parameter) {
                $type = is_string($parameter['type'] ?? null) ? trim((string) $parameter['type']) : '';
                if ($type !== '') {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * Extract structured metadata from a ClassCursor.
     *
     * @return array{
     *   name: string,
     *   qualified_name: string,
     *   is_abstract: bool,
     *   is_struct: bool,
     *   bases: list<string>,
     *   base_specifiers?: list<array{type: string, access: string, is_virtual: bool}>,
     *   properties: list<array<string, mixed>>,
     *   methods: list<array<string, mixed>>,
     *   enum_constants: list<array<string, mixed>>,
     *   enum_names?: list<string>,
     *   flag_aliases?: array<string, string>
     * }
     */
    public function extractClassData(ClassCursor $class): array
    {
        $bases = [];
        foreach ($class->getBases() as $base) {
            $bases[] = $base->getSpelling();
        }

        $properties = [];
        foreach ($class->getFields() as $field) {
            $properties[] = $this->extractField($field);
        }

        $methods = [];

        // Extract constructors (CXXConstructor kind = 24, not returned by getMethods()).
        $constructors = $this->extractConstructors($class);
        foreach ($constructors as $ctor) {
            $methods[] = $ctor;
        }

        foreach ($class->getMethods() as $method) {
            $methods[] = $this->extractMethod($method);
        }

        return [
            'name' => $class->getSpelling(),
            'qualified_name' => $this->qualifiedCursorName($class),
            'is_abstract' => $class->isAbstract(),
            'is_struct' => $class->isStruct(),
            'bases' => $bases,
            'base_specifiers' => $this->extractBaseSpecifiers($class),
            'properties' => $properties,
            'methods' => $methods,
            'enum_constants' => $this->extractEnumConstants($class),
            'enum_names' => $this->extractEnumNames($class),
            'flag_aliases' => $this->extractClassFlagAliases($class),
        ];
    }

    /**
     * @return list<array{type: string, access: string, is_virtual: bool}>
     */
    private function extractBaseSpecifiers(ClassCursor $class): array
    {
        $specifiers = [];

        if (!$this->supportsBaseSpecifiers) {
            foreach ($class->getBases() as $base) {
                $type = trim($base->getSpelling());
                if ($type === '') {
                    continue;
                }

                $specifiers[] = [
                    'type' => $type,
                    'access' => 'unknown',
                    'is_virtual' => false,
                ];
            }

            return $specifiers;
        }

        foreach ($class->getBaseSpecifiers() as $base) {
            $type = trim((string) ($base->getType()?->toString() ?? ''));
            if ($type === '') {
                $type = trim((string) ($base->getReferenced()?->getSpelling() ?? ''));
            }
            if ($type === '') {
                continue;
            }

            $specifiers[] = [
                'type' => $type,
                'access' => self::accessLabel($base->getAccessSpecifier()),
                'is_virtual' => $base->isVirtual(),
            ];
        }

        return $specifiers;
    }

    /**
     * @return list<array{name: string, enum_name: string, value: int|float|string}>
     */
    private function extractEnumConstants(ClassCursor $class): array
    {
        $constants = [];

        foreach ($class->getEnums() as $enum) {
            $enumName = trim($enum->getSpelling());
            foreach ($enum->getConstants() as $constant) {
                $name = trim($constant->getSpelling());
                if ($name === '') {
                    continue;
                }

                $value = $constant->getValue();
                if (!is_int($value) && !is_float($value) && !is_string($value)) {
                    continue;
                }

                $constants[] = [
                    'name' => $name,
                    'enum_name' => $enumName,
                    'value' => $value,
                ];
            }
        }

        return $constants;
    }

    /**
     * @return list<string>
     */
    private function extractEnumNames(ClassCursor $class): array
    {
        $names = [];

        foreach ($class->getEnums() as $enum) {
            $name = trim($enum->getSpelling());
            if ($name === '') {
                continue;
            }

            $names[] = $name;
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function extractClassFlagAliases(ClassCursor $class): array
    {
        if (!$this->supportsAliases) {
            return [];
        }

        /** @var array<string, string> $aliases */
        $aliases = [];
        foreach ($this->tu->aliases() as $cursor) {
            if (!$cursor instanceof TypeAliasCursor || !$this->belongsToClass($cursor, $class)) {
                continue;
            }

            $aliasName = trim($cursor->getSpelling());
            if ($aliasName === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $aliasName) !== 1) {
                continue;
            }

            $sourceEnum = $this->extractQFlagsInnerType((string) $cursor->getUnderlyingType()->toString());
            if ($sourceEnum === null || $sourceEnum === '') {
                continue;
            }

            $sourceEnum = trim($sourceEnum);
            if (str_contains($sourceEnum, '::')) {
                $parts = explode('::', $sourceEnum);
                $sourceEnum = trim((string) end($parts));
            }

            if ($sourceEnum === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sourceEnum) !== 1) {
                continue;
            }

            $aliases[$aliasName] = $sourceEnum;
        }

        ksort($aliases);

        return $aliases;
    }

    private function extractQFlagsInnerType(string $type): ?string
    {
        if (preg_match('/^QFlags\s*<\s*(.+)\s*>$/', trim($type), $matches) !== 1) {
            return null;
        }

        return is_string($matches[1] ?? null) ? trim($matches[1]) : null;
    }

    /**
     * @return array{name: string, type: string, access: string, is_static: bool}
     */
    public function extractField(FieldCursor $field): array
    {
        return [
            'name' => $field->getSpelling(),
            'type' => $field->getType()?->toString() ?? 'unknown',
            'access' => self::accessLabel($field->getAccessSpecifier()),
            'is_static' => $field->isStatic(),
        ];
    }

    /**
     * @return array{name: string, declaring_class: string, return_type: string, access: string, parameters: list<array<string, mixed>>, is_static: bool, is_const: bool, is_virtual: bool, is_pure_virtual: bool, is_override: bool, is_final: bool, is_signal: bool, is_slot: bool}
     */
    public function extractMethod(MethodCursor $method): array
    {
        $parameters = [];
        foreach ($method->getParameters() as $param) {
            $parameters[] = $this->extractParameter($param);
        }

        $annotations = $this->methodAnnotations($method);

        return [
            'name' => $method->getSpelling(),
            'declaring_class' => $method->getParent() instanceof Cursor
                ? $this->qualifiedCursorName($method->getParent())
                : '',
            'return_type' => $method->getReturnType()->toString(),
            'access' => self::accessLabel($method->getAccessSpecifier()),
            'parameters' => $parameters,
            'is_static' => $method->isStatic(),
            'is_const' => $method->isConst(),
            'is_virtual' => $method->isVirtual(),
            'is_pure_virtual' => $method->isPureVirtual(),
            'is_override' => $method->isOverride(),
            'is_final' => $this->methodHasFinalAttr($method),
            'is_signal' => \in_array('qt_signal', $annotations, true),
            'is_slot' => \in_array('qt_slot', $annotations, true),
        ];
    }

    /**
     * @return array{name: string, type: string, has_default: bool}
     */
    public function extractParameter(ParameterCursor $param): array
    {
        return [
            'name' => $param->getSpelling(),
            'type' => $param->getType()?->toString() ?? 'unknown',
            'has_default' => $param->hasDefaultValue(),
        ];
    }

    /**
     * Extract constructors from a ClassCursor.
     *
     * ClassCursor::getMethods() only returns CXXMethod cursors, not constructors.
     * Constructors are CXXConstructor cursors that must be fetched via getChildren().
     * We deduplicate by display name since Qt headers may produce duplicate entries.
     *
     * @return list<array{name: string, declaring_class: string, return_type: string, access: string, parameters: list<array<string, mixed>>, is_static: bool, is_const: bool, is_virtual: bool, is_pure_virtual: bool, is_override: bool, is_final: bool, is_signal: bool, is_slot: bool}>
     */
    private function extractConstructors(ClassCursor $class): array
    {
        $constructors = [];
        $seen = [];

        foreach ($class->getChildren(CursorKind::CXXConstructor) as $ctor) {
            $displayName = $ctor->getDisplayName();

            // Deduplicate — Qt headers sometimes produce identical constructor entries
            if (isset($seen[$displayName])) {
                continue;
            }
            $seen[$displayName] = true;

            $constructors[] = $this->extractConstructor($ctor, $class);
        }

        return $constructors;
    }

    /**
     * @return array{
     *   name: string,
     *   declaring_class: string,
     *   return_type: string,
     *   access: string,
     *   parameters: list<array<string, mixed>>,
     *   is_static: bool,
     *   is_const: bool,
     *   is_virtual: bool,
     *   is_pure_virtual: bool,
     *   is_override: bool,
     *   is_final: bool,
     *   is_signal: bool,
     *   is_slot: bool,
     *   is_deleted?: bool,
     *   is_defaulted?: bool,
     *   is_explicit?: bool,
     *   is_copy_constructor?: bool,
     *   is_move_constructor?: bool,
     *   is_default_constructor?: bool
     * }
     */
    private function extractConstructor(Cursor $constructor, ClassCursor $class): array
    {
        $parameters = [];
        if ($constructor instanceof MethodCursor) {
            foreach ($constructor->getParameters() as $param) {
                $parameters[] = $this->extractParameter($param);
            }
        } else {
            foreach ($constructor->getChildren(CursorKind::ParmDecl) as $param) {
                /** @var ParameterCursor $param */
                $parameters[] = $this->extractParameter($param);
            }
        }

        $access = $constructor instanceof MethodCursor
            ? self::accessLabel($constructor->getAccessSpecifier())
            : 'unknown';

        $isDeleted = false;
        $isDefaulted = false;
        $isExplicit = false;
        $isCopyConstructor = false;
        $isMoveConstructor = false;
        $isDefaultConstructor = false;
        if ($constructor instanceof MethodCursor && $this->supportsConstructorSemantics) {
            $isDeleted = $constructor->isDeleted();
            $isDefaulted = $constructor->isDefaulted();
            $isExplicit = $constructor->isExplicit();
            $isCopyConstructor = $constructor->isCopyConstructor();
            $isMoveConstructor = $constructor->isMoveConstructor();
            $isDefaultConstructor = $constructor->isDefaultConstructor();
        }

        // Constructor name in the IR is the class name; ClassDefinitionBuilder renames it to __construct.
        return [
            'name' => $constructor->getSpelling(),
            'declaring_class' => $this->qualifiedCursorName($class),
            'return_type' => 'void',
            'access' => $access,
            'parameters' => $parameters,
            'is_static' => false,
            'is_const' => false,
            'is_virtual' => false,
            'is_pure_virtual' => false,
            'is_override' => false,
            'is_final' => false,
            'is_signal' => false,
            'is_slot' => false,
            'is_deleted' => $isDeleted,
            'is_defaulted' => $isDefaulted,
            'is_explicit' => $isExplicit,
            'is_copy_constructor' => $isCopyConstructor,
            'is_move_constructor' => $isMoveConstructor,
            'is_default_constructor' => $isDefaultConstructor,
        ];
    }

    /**
     * @return list<string>
     */
    private function methodAnnotations(MethodCursor $method): array
    {
        if (!$this->supportsAnnotations) {
            return [];
        }

        /** @var list<string> $annotations */
        $annotations = $method->getAnnotations();

        return $annotations;
    }

    private function methodHasFinalAttr(MethodCursor $method): bool
    {
        foreach ($method->getChildren() as $child) {
            // 404 is libclang's CXCursor_CXXFinalAttr.
            if ($child->getKind() === 404) {
                return true;
            }
        }

        return false;
    }

    public static function accessLabel(?int $access): string
    {
        return match ($access) {
            Access::Public => 'public',
            Access::Protected => 'protected',
            Access::Private => 'private',
            default => 'unknown',
        };
    }
}
