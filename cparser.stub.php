<?php

/** @generate-class-entries */

namespace CParser;

/**
 * @template T of Cursor
 * @implements \Iterator<int, T>
 */
final class CursorIterator implements \Iterator
{
    public function __construct(TranslationUnit|Cursor $source, int $filter_kind = -1) {}
    public function current(): Cursor|null {}
    public function key(): int {}
    public function next(): void {}
    public function rewind(): void {}
    public function valid(): bool {}
}

/**
 * @implements \Iterator<int, Diagnostic>
 */
final class DiagnosticIterator implements \Iterator
{
    public function __construct(TranslationUnit $tu) {}
    public function current(): Diagnostic|null {}
    public function key(): int {}
    public function next(): void {}
    public function rewind(): void {}
    public function valid(): bool {}
}

/**
 * @implements \Iterator<int, TemplateArgument>
 */
final class TemplateArgumentIterator implements \Iterator
{
    public function __construct(Type $type) {}
    public function current(): TemplateArgument|null {}
    public function key(): int {}
    public function next(): void {}
    public function rewind(): void {}
    public function valid(): bool {}
}

final class TranslationUnitFlags {
    public const int None = 0x0;
    public const int DetailedPreprocessingRecord = 0x01;
    public const int Incomplete = 0x02;
    public const int PrecompiledPreamble = 0x04;
    public const int CacheCompletionResults = 0x08;
    public const int ForSerialization = 0x10;
    public const int CXXChainedPCH = 0x20;
    public const int SkipFunctionBodies = 0x40;
    public const int IncludeBriefCommentsInCodeCompletion = 0x80;
    public const int CreatePreambleOnFirstParse = 0x100;
    public const int KeepGoing = 0x200;
    public const int SingleFileParse = 0x400;
    public const int LimitSkipFunctionBodiesToPreamble = 0x800;
    public const int IncludeAttributedTypes = 0x1000;
    public const int VisitImplicitAttributes = 0x2000;
    public const int IgnoreNonErrorsFromIncludedFiles = 0x4000;
    public const int RetainExcludedConditionalBlocks = 0x8000;
}

/**
 * Represents a parsed translation unit (a source or header file).
 */
final class TranslationUnit
{
    /**
     * Parse a file and return a TranslationUnit.
     *
     * @param string $filename  Path to the C-family source/header file
     * @param array $args  Compiler flags (e.g. ["-std=c11", "-Iinclude"])
     */
    public static function fromFile(string $filename, array $args = [], int $flags = TranslationUnitFlags::None): TranslationUnit {}

    /**
     * Recursively traverse all cursors in the translation unit (depth-first).
     *
     * @return iterable<Cursor>
     */
    public function cursors(int $kind = -1): iterable {}

    /**
     * Lazily yield all top-level classes (structs/classes) in the translation unit.
     *
     * @return iterable<ClassCursor>
     */
    public function classes(): iterable {}

    /**
     * Lazily yield all top-level enums in the translation unit.
     *
     * @return iterable<EnumCursor>
     */
    public function enums(): iterable {}

    /**
     * Lazily yield diagnostics (parse warnings/errors).
     *
     * @return iterable<Diagnostic>
     */
    public function diagnostics(): iterable {}
}

class Cursor {
    /** Kind of cursor (e.g. function, class, field, etc.) */
    public function getKind(): int {}  // CParser\CursorKind::*

    /** The display name or spelling of the cursor */
    public function getSpelling(): string {}

    /** The human-readable name of the cursor (may include extra info) */
    public function getDisplayName(): string {}

    /** Source location of this cursor */
    public function getLocation(): array {} // ['file' => string|null, 'line' => int, 'column' => int]

    /** The type associated with this cursor, if any */
    public function getType(): ?Type {}

    /** True if this cursor represents a definition, not just a declaration */
    public function isDefinition(): bool {}

    /** The canonical cursor this one refers to (e.g. from typedef or alias) */
    public function getCanonical(): ?Cursor {}

    /** The parent cursor (e.g. the class that contains a method) */
    public function getParent(): ?Cursor {}

    /**
     * Get direct child cursors of this cursor, optionally filtered by kind.
     * @param int $kind  Cursor kind to filter by, or -1 for all kinds (CParser\CursorKind::*)
     * @return iterable<Cursor>
    */
    public function getChildren(int $kind = -1): iterable {}
}

final class ClassCursor extends Cursor {
    /** @return iterable<ClassCursor> */
    public function getBases(): iterable {}
    /** @return iterable<MethodCursor> */
    public function getMethods(): iterable {}
    /** @return iterable<FieldCursor> */
    public function getFields(): iterable {}
    /** @return iterable<ClassCursor> */
    public function getInnerClasses(): iterable {}
    /** @return iterable<EnumCursor> */
    public function getEnums(): iterable {}
    public function isAbstract(): bool {}
    public function isStruct(): bool {}
}

final class MethodCursor extends Cursor {
    public function getReturnType(): Type {}
    /** @return iterable<ParameterCursor> */
    public function getParameters(): iterable {}
    /** @return array<int,MethodCursor> */
    public function getBaseMethods(): array {}
    public function isStatic(): bool {}
    public function isConst(): bool {}
    public function isOverride(): bool {}
    public function isVirtual(): bool {}
    public function isPureVirtual(): bool {}
    public function getAccessSpecifier(): ?int {}
}

final class FunctionCursor extends Cursor {
    public function getReturnType(): Type {}
    /** @return iterable<ParameterCursor> */
    public function getParameters(): iterable {}
    public function getNumArguments(): int {}
}

final class FieldCursor extends Cursor {
    public function getType(): ?Type {}
    public function getAccessSpecifier(): ?int {}
    public function isStatic(): bool {}
}

final class EnumCursor extends Cursor {
    /** @return iterable<EnumConstantCursor> */
    public function getConstants(): iterable {}
    public function getIntegerType(): Type {}
}

final class EnumConstantCursor extends Cursor {
    public function getValue(): int|float|string {}
}

final class ParameterCursor extends Cursor {
    public function getType(): ?Type {}
    public function isConstQualified(): bool {}
    public function hasDefaultValue(): bool {}
}

final class NamespaceCursor extends Cursor {
    //
}

final class TypeAliasCursor extends Cursor {
    public function getUnderlyingType(): Type {}
}

/**
 * Represents a C-family type.
 */
final class Type
{
    public function toString(): string {}

    public function isConstQualified(): bool {}
    public function isPointer(): bool {}
    public function isReference(): bool {}

    public function getPointeeType(): ?Type {}
    public function getCanonicalType(): Type {}

    /** For template specializations: e.g. QVector<int> */
    public function isTemplateSpecialization(): bool {}

    /** @return iterable<TemplateArgument> */
    public function getTemplateArguments(): iterable {}
}

/**
 * A template declaration.
 */
final class TemplateDecl
{
    public function getName(): string {}

    /** @return iterable<TemplateParameter> */
    public function getParameters(): iterable {}
}

final class TemplateParameter
{
    public function getName(): string {}
    public function getKind(): string {}
}

final class TemplateArgument
{
    public function getKind(): string {}
    public function getType(): ?Type {}
    public function getValue(): int|string|float|null {}
}

/**
 * A compiler diagnostic.
 */
final class Diagnostic
{
    public function getMessage(): string {}
    public function getSeverity(): int {}
    public function getLine(): int {}
    public function getColumn(): int {}
}

/**
 * Access specifiers for class members.
 */
final class Access
{
    public const int Public = 1;
    public const int Protected = 2;
    public const int Private = 3;
}

final class CursorKind {
    //
}
