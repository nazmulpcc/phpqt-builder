<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class IosBuildManifest
{
    /**
     * @param array<string, array<string, mixed>> $artifacts
     */
    public function __construct(
        public string $extensionName,
        public string $qtRootPath,
        public string $developerDir,
        public array $artifacts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'extension_name' => $this->extensionName,
            'qt_root_path' => $this->qtRootPath,
            'developer_dir' => $this->developerDir,
            'artifacts' => $this->artifacts,
        ];
    }

    public function write(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create directory: %s', $directory));
        }

        $encoded = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Could not encode iOS build manifest.');
        }

        file_put_contents($path, $encoded . "\n");
    }
}
