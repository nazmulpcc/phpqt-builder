<?php

declare(strict_types=1);

namespace QtBuilder\Parsing;

use CParser\Access;
use CParser\ClassCursor;
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

    public function __construct(
        private readonly ClangArgumentBuilder $argBuilder,
    ) {}

    /**
     * Parse the given header file into a translation unit.
     *
     * Must be called before {@see inspect()} or {@see findClass()}.
     */
    public function parse(string $headerPath): void
    {
        $this->tu = TranslationUnit::fromFile(
            $headerPath,
            $this->argBuilder->build(),
            TranslationUnitFlags::SkipFunctionBodies | TranslationUnitFlags::KeepGoing,
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

        return $this->extractClassData($classCursor);
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
     * @return array{name: string, return_type: string, access: string, parameters: list<array<string, mixed>>, is_static: bool, is_const: bool, is_virtual: bool, is_pure_virtual: bool, is_override: bool}
     */
    public function extractMethod(MethodCursor $method): array
    {
        $parameters = [];
        foreach ($method->getParameters() as $param) {
            $parameters[] = $this->extractParameter($param);
        }

        return [
            'name' => $method->getSpelling(),
            'return_type' => $method->getReturnType()->toString(),
            'access' => self::accessLabel($method->getAccessSpecifier()),
            'parameters' => $parameters,
            'is_static' => $method->isStatic(),
            'is_const' => $method->isConst(),
            'is_virtual' => $method->isVirtual(),
            'is_pure_virtual' => $method->isPureVirtual(),
            'is_override' => $method->isOverride(),
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
