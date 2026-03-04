<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class BuildLayout
{
    public function __construct(public string $buildRootDir) {}

    public static function fromCliOutput(string $output): self
    {
        $normalized = rtrim(trim($output), '/');
        if ($normalized === '') {
            throw new \InvalidArgumentException('The build root directory cannot be empty.');
        }

        if (basename($normalized) === 'ext') {
            throw new \InvalidArgumentException(sprintf(
                '--output must be a build root directory, not an extension directory. Use %s instead of %s.',
                dirname($normalized),
                $normalized,
            ));
        }

        return new self($normalized);
    }

    public function extensionDir(): string
    {
        return $this->buildRootDir . '/ext';
    }

    public function metadataDir(): string
    {
        return $this->buildRootDir . '/generated';
    }

    public function classCacheDir(): string
    {
        return $this->buildRootDir . '/classes';
    }
}
