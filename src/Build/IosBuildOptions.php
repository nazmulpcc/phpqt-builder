<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class IosBuildOptions
{
    public const string SDK_IPHONEOS = 'iphoneos';
    public const string SDK_IPHONESIMULATOR = 'iphonesimulator';

    /**
     * @param list<string> $sdks
     * @param list<string> $architectures
     */
    public function __construct(
        public array $sdks = [self::SDK_IPHONEOS, self::SDK_IPHONESIMULATOR],
        public string $minimumVersion = '15.0',
        public array $architectures = ['arm64'],
        public ?string $developerDir = null,
    ) {}

    /**
     * @return list<string>
     */
    public static function normalizeSdks(string $value): array
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || $normalized === 'all') {
            return [self::SDK_IPHONEOS, self::SDK_IPHONESIMULATOR];
        }

        $parts = array_values(array_filter(array_map(
            static fn(string $entry): string => strtolower(trim($entry)),
            explode(',', $normalized),
        ), static fn(string $entry): bool => $entry !== ''));

        if ($parts === []) {
            return [self::SDK_IPHONEOS, self::SDK_IPHONESIMULATOR];
        }

        foreach ($parts as $sdk) {
            if (!in_array($sdk, [self::SDK_IPHONEOS, self::SDK_IPHONESIMULATOR], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unsupported iOS SDK "%s". Expected iphoneos, iphonesimulator, or all.',
                    $sdk,
                ));
            }
        }

        return array_values(array_unique($parts));
    }

    /**
     * @return list<string>
     */
    public static function normalizeArchitectures(?string $value): array
    {
        $parts = array_values(array_filter(array_map(
            static fn(string $entry): string => trim($entry),
            explode(',', (string) $value),
        ), static fn(string $entry): bool => $entry !== ''));

        return $parts === [] ? ['arm64'] : array_values(array_unique($parts));
    }

    public function cacheNamespace(): string
    {
        return sprintf(
            '%s|%s|%s|%s',
            BuildTarget::IOS,
            implode(',', $this->sdks),
            $this->minimumVersion,
            implode(',', $this->architectures),
        );
    }
}
