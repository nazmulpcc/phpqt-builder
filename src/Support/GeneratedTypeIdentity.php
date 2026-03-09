<?php

declare(strict_types=1);

namespace QtBuilder\Support;

final readonly class GeneratedTypeIdentity
{
    public function __construct(
        public string $canonicalKey,
        public string $generationId,
    ) {}

    public static function fromNames(string $className, ?string $qualifiedName = null, ?string $module = null): self
    {
        $className = trim($className);
        $qualifiedName = is_string($qualifiedName) ? trim($qualifiedName) : '';
        $canonicalKey = $qualifiedName !== '' ? $qualifiedName : $className;

        $ownerChain = self::ownerChain($canonicalKey);
        $topModule = $module;
        if ($topModule === null || $topModule === '') {
            $topModule = TypeResolutionContext::moduleForQualifiedName($canonicalKey);
        }

        $primary = self::snake(self::lastSegment($canonicalKey));
        if ($ownerChain === []) {
            return new self($canonicalKey, $primary);
        }

        $ownerSegments = array_map(self::snake(...), $ownerChain);
        if ($topModule !== null && $topModule !== '') {
            $topModuleSnake = self::snake($topModule);
            if ($ownerSegments !== [] && $ownerSegments[0] === $topModuleSnake) {
                array_shift($ownerSegments);
            }
            array_unshift($ownerSegments, $topModuleSnake);
            $ownerSegments = array_values(array_unique($ownerSegments));
        }

        $suffix = implode('_', array_values(array_filter($ownerSegments, static fn(string $segment): bool => $segment !== '')));

        return new self(
            $canonicalKey,
            $suffix !== '' ? sprintf('%s__%s', $primary, $suffix) : $primary,
        );
    }

    public static function provisional(string $module, string $className, string $parseHeader): self
    {
        $safeHeader = preg_replace('/[^A-Za-z0-9]+/', '_', basename($parseHeader)) ?? 'header';
        $safeHeader = strtolower(trim($safeHeader, '_'));
        $generationId = sprintf('%s__%s__%s', self::snake($className), self::snake($module), $safeHeader);

        return new self(sprintf('%s::%s@%s', $module, $className, $parseHeader), $generationId);
    }

    /**
     * @return list<string>
     */
    private static function ownerChain(string $canonicalKey): array
    {
        $parts = array_values(array_filter(
            explode('::', ltrim($canonicalKey, ':')),
            static fn(string $part): bool => $part !== '',
        ));
        if (count($parts) <= 1) {
            return [];
        }

        array_pop($parts);

        return $parts;
    }

    private static function lastSegment(string $canonicalKey): string
    {
        $parts = array_values(array_filter(
            explode('::', ltrim($canonicalKey, ':')),
            static fn(string $part): bool => $part !== '',
        ));

        return $parts !== [] ? $parts[array_key_last($parts)] : $canonicalKey;
    }

    private static function snake(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_]+/', '_', $value) ?? $value;

        return strtolower(trim($value, '_'));
    }
}
