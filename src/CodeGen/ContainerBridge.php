<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use QtBuilder\Definition\ContainerType;
use QtBuilder\Parsing\ContainerTypeParser;
use QtBuilder\Parsing\CppToPhpTypeMapper;
use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\TypeResolutionContext;

class ContainerBridge
{
    private readonly ContainerTypeParser $parser;
    private readonly CppToPhpTypeMapper $typeMapper;
    private ?CppClassTypeResolver $classTypeResolver = null;
    private ?TypeResolutionContext $resolutionContext = null;
    /** @var array<string, string> */
    private array $smartPointerAliases = [];

    public function __construct(
        ?ContainerTypeParser $parser = null,
        ?CppToPhpTypeMapper $typeMapper = null,
    ) {
        $this->parser = $parser ?? new ContainerTypeParser();
        $this->typeMapper = $typeMapper ?? new CppToPhpTypeMapper();
    }

    /**
     * @param array<string, string> $smartPointerAliases
     */
    public function setTypeResolutionMetadata(
        ?CppClassTypeResolver $classTypeResolver,
        ?TypeResolutionContext $resolutionContext,
        array $smartPointerAliases = [],
    ): void {
        $this->classTypeResolver = $classTypeResolver;
        $this->resolutionContext = $resolutionContext;
        $this->smartPointerAliases = $smartPointerAliases;
    }

    public function parse(string $cppType): ?ContainerType
    {
        return $this->parser->parse($cppType);
    }

    public function isSupported(string $cppType): bool
    {
        $container = $this->parse($cppType);
        if ($container === null) {
            return false;
        }

        if ($container->isSequence()) {
            return $this->isSupportedSequenceElement((string) $container->elementType);
        }

        if ($container->isMapLike()) {
            return $this->isSupportedMapKey((string) $container->keyType)
                && $this->isSupportedMapValue((string) $container->valueType);
        }

        if ($container->isPairSequence()) {
            return $this->isSupportedMapKey((string) $container->keyType, allowObjectKeys: true)
                && $this->isSupportedMapValue((string) $container->valueType);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function classRefs(string $cppType): array
    {
        $container = $this->parse($cppType);
        if ($container === null) {
            return [];
        }

        $refs = [];
        foreach (array_filter([$container->elementType, $container->keyType, $container->valueType]) as $type) {
            foreach ($this->refsForElement((string) $type) as $ref) {
                $refs[$ref] = true;
            }
        }

        return array_keys($refs);
    }

    public function elementPhpType(string $cppType): string
    {
        return $this->typeMapper->map(
            $cppType,
            classTypeResolver: $this->classTypeResolver,
            resolutionContext: $this->resolutionContext,
            smartPointerAliases: $this->smartPointerAliases,
        );
    }

    private function isSupportedSequenceElement(string $cppType): bool
    {
        if ($cppType === '') {
            return false;
        }

        if (str_contains($cppType, '<')) {
            return false;
        }

        $phpType = $this->elementPhpType($cppType);
        if ($this->isUnsupportedQualifiedScalarFallback($cppType, $phpType)) {
            return false;
        }

        if (in_array($phpType, ['int', 'float', 'bool', 'string'], true)) {
            return true;
        }

        if ($phpType === 'mixed') {
            return trim($cppType) === 'QVariant';
        }

        if ($phpType === 'array') {
            return false;
        }

        if ($phpType === '') {
            return false;
        }

        return true;
    }

    private function isSupportedMapKey(string $cppType, bool $allowObjectKeys = false): bool
    {
        if (str_contains($cppType, '<')) {
            return false;
        }

        $phpType = $this->elementPhpType($cppType);
        if ($this->isUnsupportedQualifiedScalarFallback($cppType, $phpType)) {
            return false;
        }

        if (in_array($phpType, ['int', 'string'], true)) {
            return true;
        }

        if (!$allowObjectKeys) {
            return false;
        }

        if (in_array($phpType, ['float', 'bool', 'void', 'array', 'mixed', ''], true)) {
            return false;
        }

        return true;
    }

    private function isSupportedMapValue(string $cppType): bool
    {
        if ($cppType === '') {
            return false;
        }

        if (str_contains($cppType, '<')) {
            return false;
        }

        $phpType = $this->elementPhpType($cppType);
        if ($this->isUnsupportedQualifiedScalarFallback($cppType, $phpType)) {
            return false;
        }

        if (in_array($phpType, ['int', 'float', 'bool', 'string'], true)) {
            return true;
        }

        if ($phpType === 'mixed') {
            return trim($cppType) === 'QVariant';
        }

        return $phpType !== 'array' && $phpType !== '';
    }

    /**
     * @return list<string>
     */
    private function refsForElement(string $cppType): array
    {
        $trimmed = trim($cppType);
        if ($trimmed === 'QVariant') {
            return [];
        }

        $phpType = $this->elementPhpType($trimmed);
        if (in_array($phpType, ['int', 'float', 'bool', 'string', 'void', 'array', 'mixed'], true)) {
            return [];
        }

        $classRef = $this->canonicalClassLookupKey($trimmed);

        return $classRef !== '' ? [$classRef] : [];
    }

    private function canonicalClassLookupKey(string $cppType): string
    {
        $normalized = $this->normalizedClassLikeType($cppType);
        if ($normalized === '') {
            return '';
        }

        if ($this->classTypeResolver !== null && $this->resolutionContext !== null) {
            $normalized = $this->normalizedClassLikeType($this->classTypeResolver->canonicalizeType($normalized, $this->resolutionContext));
        }

        return $normalized;
    }

    private function normalizedClassLikeType(string $type): string
    {
        $trimmed = trim($type);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^(?:const\s+)?(?<base>(?:::)?(?:[A-Za-z_][A-Za-z0-9_]*::)*[A-Za-z_][A-Za-z0-9_]*)(?:\s*[*&]\s*)*$/', $trimmed, $matches) === 1) {
            return trim((string) ($matches['base'] ?? $trimmed));
        }

        return $trimmed;
    }

    private function isUnsupportedQualifiedScalarFallback(string $cppType, string $phpType): bool
    {
        $trimmed = trim($cppType);
        if (!str_contains($trimmed, '::')) {
            return false;
        }

        if (!in_array($phpType, ['int', 'float', 'bool', 'string'], true)) {
            return false;
        }

        return !$this->isKnownQualifiedScalarType($trimmed);
    }

    private function isKnownQualifiedScalarType(string $cppType): bool
    {
        return str_starts_with(trim($cppType), 'std::chrono::');
    }
}
