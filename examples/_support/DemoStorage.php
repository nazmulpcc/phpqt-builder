<?php

declare(strict_types=1);

namespace Examples\Support;

final readonly class DemoStorage
{
    public function __construct(
        private AppPaths $paths,
        private JsonStore $jsonStore = new JsonStore(),
    ) {
    }

    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function loadAssoc(string $relativePath, array $default = []): array
    {
        return $this->jsonStore->loadAssoc($this->paths->dataFile($relativePath), $default);
    }

    /**
     * @param list<mixed> $default
     * @return list<mixed>
     */
    public function loadList(string $relativePath, array $default = []): array
    {
        return $this->jsonStore->loadList($this->paths->dataFile($relativePath), $default);
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    public function saveData(string $relativePath, array $data): void
    {
        $this->jsonStore->save($this->paths->dataFile($relativePath), $data);
    }

    public function loadText(string $relativePath, string $default = ''): string
    {
        $path = $this->paths->dataFile($relativePath);
        if (!is_file($path)) {
            return $default;
        }

        return (string) file_get_contents($path);
    }

    public function saveText(string $relativePath, string $contents): void
    {
        $path = $this->paths->dataFile($relativePath);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, $contents);
    }
}
