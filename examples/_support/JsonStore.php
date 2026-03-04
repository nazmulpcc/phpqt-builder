<?php

declare(strict_types=1);

namespace Examples\Support;

use RuntimeException;

final class JsonStore
{
    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function loadAssoc(string $path, array $default = []): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return $default;
        }

        return $decoded;
    }

    /**
     * @param list<mixed> $default
     * @return list<mixed>
     */
    public function loadList(string $path, array $default = []): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return $default;
        }

        return array_values($decoded);
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    public function save(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $tmp = $path . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON for ' . $path);
        }

        file_put_contents($tmp, $json . PHP_EOL);
        rename($tmp, $path);
    }
}
