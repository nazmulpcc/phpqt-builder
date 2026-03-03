<?php

declare(strict_types=1);

namespace QtBuilder\Parsing;

use CParser\Access;
use CParser\ClassCursor;
use CParser\Cursor;
use CParser\CursorKind;
use CParser\FieldCursor;
use CParser\MethodCursor;
use CParser\ParameterCursor;
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

    public function __construct(
        private readonly ClangArgumentBuilder $argBuilder,
    ) {
        $this->supportsAnnotations = method_exists(Cursor::class, 'getAnnotations');
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
    public function findClass(string $className): ?ClassCursor
    {
        $candidate = null;

        foreach ($this->tu->classes() as $class) {
            if ($class->getSpelling() === $className) {
                if ($class->isDefinition()) {
                    return $class;
                }
                $candidate ??= $class;
            }
        }

        return $candidate;
    }

    /**
     * Parse a header and return structured data for the requested class.
     *
     * Convenience method that combines {@see parse()}, {@see findClass()},
     * and {@see extractClassData()} in one call.
     *
     * @return array{name: string, is_abstract: bool, is_struct: bool, bases: list<string>, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>}|null
     */
    public function inspect(string $headerPath, string $className): ?array
    {
        $this->parse($headerPath);

        $classCursor = $this->findClass($className);

        if ($classCursor === null) {
            return null;
        }

        if ($this->hasDirectMembers($classCursor)) {
            return $this->extractClassData($classCursor);
        }

        return $this->extractClassDataFromTranslationUnit($className, $classCursor);
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
     * Some Qt classes, notably exported QObject-style classes inside QT_BEGIN_NAMESPACE,
     * are exposed by ext-cparser as a forward-declaration ClassCursor plus generic Cursor
     * nodes for the actual definition. In that case we recover members by scanning the
     * translation unit for methods/fields whose parent spelling matches the class name.
     *
     * @return array{name: string, is_abstract: bool, is_struct: bool, bases: list<string>, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>}
     */
    private function extractClassDataFromTranslationUnit(string $className, ?ClassCursor $classCursor = null): array
    {
        $properties = [];
        $propertySignatures = [];
        foreach ($this->tu->cursors(CursorKind::FieldDecl) as $field) {
            if (!$field instanceof FieldCursor || !$this->belongsToClass($field, $className)) {
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
            if (!$this->belongsToClass($ctor, $className)) {
                continue;
            }

            $parameters = [];
            foreach ($ctor->getChildren(CursorKind::ParmDecl) as $param) {
                /** @var ParameterCursor $param */
                $parameters[] = $this->extractParameter($param);
            }

            $signature = $className . '|void|' . json_encode($parameters);
            if (isset($methodSignatures[$signature])) {
                continue;
            }
            $methodSignatures[$signature] = true;

            $methods[] = [
                'name' => $className,
                'declaring_class' => $className,
                'return_type' => 'void',
                'access' => 'public',
                'parameters' => $parameters,
                'is_static' => false,
                'is_const' => false,
                'is_virtual' => false,
                'is_pure_virtual' => false,
                'is_override' => false,
                'is_signal' => false,
                'is_slot' => false,
            ];
        }

        foreach ($this->tu->cursors(CursorKind::CXXMethod) as $method) {
            if (!$method instanceof MethodCursor || !$this->belongsToClass($method, $className)) {
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
            'is_abstract' => $classCursor?->isAbstract() ?? false,
            'is_struct' => $classCursor?->isStruct() ?? false,
            'bases' => $classCursor !== null ? array_values(array_map(
                static fn(ClassCursor $base): string => $base->getSpelling(),
                iterator_to_array($classCursor->getBases(), false),
            )) : [],
            'properties' => $properties,
            'methods' => $methods,
        ];
    }

    private function belongsToClass(Cursor $cursor, string $className): bool
    {
        $parent = $cursor->getParent();

        return $parent !== null && $parent->getSpelling() === $className;
    }

    /**
     * Extract structured metadata from a ClassCursor.
     *
     * @return array{name: string, is_abstract: bool, is_struct: bool, bases: list<string>, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>}
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
        // These are generic Cursor objects; parameters are ParameterCursor children.
        $constructors = $this->extractConstructors($class);
        foreach ($constructors as $ctor) {
            $methods[] = $ctor;
        }

        foreach ($class->getMethods() as $method) {
            $methods[] = $this->extractMethod($method);
        }

        return [
            'name' => $class->getSpelling(),
            'is_abstract' => $class->isAbstract(),
            'is_struct' => $class->isStruct(),
            'bases' => $bases,
            'properties' => $properties,
            'methods' => $methods,
        ];
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
     * @return array{name: string, declaring_class: string, return_type: string, access: string, parameters: list<array<string, mixed>>, is_static: bool, is_const: bool, is_virtual: bool, is_pure_virtual: bool, is_override: bool, is_signal: bool, is_slot: bool}
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
            'declaring_class' => $method->getParent()?->getSpelling() ?? '',
            'return_type' => $method->getReturnType()->toString(),
            'access' => self::accessLabel($method->getAccessSpecifier()),
            'parameters' => $parameters,
            'is_static' => $method->isStatic(),
            'is_const' => $method->isConst(),
            'is_virtual' => $method->isVirtual(),
            'is_pure_virtual' => $method->isPureVirtual(),
            'is_override' => $method->isOverride(),
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
     * @return list<array{name: string, declaring_class: string, return_type: string, access: string, parameters: list<array<string, mixed>>, is_static: bool, is_const: bool, is_virtual: bool, is_pure_virtual: bool, is_override: bool, is_signal: bool, is_slot: bool}>
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

            $parameters = [];
            foreach ($ctor->getChildren(CursorKind::ParmDecl) as $param) {
                /** @var ParameterCursor $param */
                $parameters[] = $this->extractParameter($param);
            }

            // Constructor name in the IR is the class name; the ClassDefinitionBuilder
            // will rename it to __construct.
            $constructors[] = [
                'name' => $ctor->getSpelling(),
                'declaring_class' => $class->getSpelling(),
                'return_type' => 'void',
                'access' => 'public', // generic Cursor lacks getAccessSpecifier()
                'parameters' => $parameters,
                'is_static' => false,
                'is_const' => false,
                'is_virtual' => false,
                'is_pure_virtual' => false,
                'is_override' => false,
                'is_signal' => false,
                'is_slot' => false,
            ];
        }

        return $constructors;
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
