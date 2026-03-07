<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class EnumHolderRegistry
{
    /**
     * @param array<string, EnumHolderDefinition> $holdersByCppType
     */
    public function __construct(
        public array $holdersByCppType,
    ) {}

    public function supportsType(string $cppType): bool
    {
        $normalized = $this->normalizeType($cppType);

        return isset($this->holdersByCppType[$normalized]);
    }

    /**
     * @return list<EnumHolderDefinition>
     */
    public function holders(): array
    {
        return array_values($this->holdersByCppType);
    }

    /**
     * @param list<string> $modules
     * @return list<EnumHolderDefinition>
     */
    public function holdersForModules(array $modules): array
    {
        $moduleSet = array_fill_keys($modules, true);

        return array_values(array_filter(
            $this->holders(),
            static fn(EnumHolderDefinition $holder): bool => isset($moduleSet[$holder->module]),
        ));
    }

    private function normalizeType(string $cppType): string
    {
        $normalized = trim($cppType);
        $normalized = preg_replace('/\bconst\b/', '', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
        $normalized = rtrim($normalized, '& ');

        return trim($normalized);
    }
}
