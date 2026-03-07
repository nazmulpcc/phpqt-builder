<?php

declare(strict_types=1);

final class ObjMaterialLibrary
{
    /**
     * @param list<string> $libraryPaths
     * @return array{materials: array<string, array{name: string, diffuse: array{float, float, float}, alpha: float, texture_path: ?string}>, warnings: list<string>}
     */
    public static function loadLibraries(array $libraryPaths): array
    {
        /** @var array<string, array{name: string, diffuse: array{float, float, float}, alpha: float, texture_path: ?string}> $materials */
        $materials = [];
        $warnings = [];

        foreach ($libraryPaths as $libraryPath) {
            if (!is_file($libraryPath)) {
                $warnings[] = sprintf('Material library not found: %s', basename($libraryPath));
                continue;
            }

            $parsed = self::parseLibrary($libraryPath);
            $materials = array_replace($materials, $parsed['materials']);
            $warnings = array_merge($warnings, $parsed['warnings']);
        }

        return [
            'materials' => $materials,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array{materials: array<string, array{name: string, diffuse: array{float, float, float}, alpha: float, texture_path: ?string}>, warnings: list<string>}
     */
    private static function parseLibrary(string $libraryPath): array
    {
        $directory = dirname($libraryPath);
        $lines = file($libraryPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException(sprintf('Unable to read material library: %s', $libraryPath));
        }

        /** @var array<string, array{name: string, diffuse: array{float, float, float}, alpha: float, texture_path: ?string}> $materials */
        $materials = [];
        $warnings = [];
        $current = null;

        foreach ($lines as $lineNumber => $rawLine) {
            $line = trim((string) preg_replace('/#.*/', '', $rawLine));
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];
            if ($parts === []) {
                continue;
            }

            $command = array_shift($parts);
            if ($command === null) {
                continue;
            }

            switch ($command) {
                case 'newmtl':
                    $name = trim(implode(' ', $parts));
                    if ($name === '') {
                        $warnings[] = sprintf('Ignoring unnamed material in %s:%d', basename($libraryPath), $lineNumber + 1);
                        $current = null;
                        continue 2;
                    }

                    $current = [
                        'name' => $name,
                        'diffuse' => [1.0, 1.0, 1.0],
                        'alpha' => 1.0,
                        'texture_path' => null,
                    ];
                    $materials[$name] = $current;
                    break;

                case 'Kd':
                    if ($current === null) {
                        continue 2;
                    }

                    $current['diffuse'] = [
                        self::floatPart($parts, 0, 1.0),
                        self::floatPart($parts, 1, 1.0),
                        self::floatPart($parts, 2, 1.0),
                    ];
                    $materials[$current['name']] = $current;
                    break;

                case 'd':
                    if ($current === null) {
                        continue 2;
                    }

                    $current['alpha'] = max(0.0, min(1.0, self::floatPart($parts, 0, 1.0)));
                    $materials[$current['name']] = $current;
                    break;

                case 'Tr':
                    if ($current === null) {
                        continue 2;
                    }

                    $current['alpha'] = max(0.0, min(1.0, 1.0 - self::floatPart($parts, 0, 0.0)));
                    $materials[$current['name']] = $current;
                    break;

                case 'map_Kd':
                    if ($current === null) {
                        continue 2;
                    }

                    $textureRef = trim(implode(' ', $parts));
                    if ($textureRef === '') {
                        continue 2;
                    }

                    $texturePath = self::resolveRelativePath($directory, $textureRef);
                    if (!is_file($texturePath)) {
                        $warnings[] = sprintf(
                            'Diffuse texture not found for material %s: %s',
                            $current['name'],
                            $textureRef
                        );
                    }

                    $current['texture_path'] = $texturePath;
                    $materials[$current['name']] = $current;
                    break;

                default:
                    break;
            }
        }

        return [
            'materials' => $materials,
            'warnings' => $warnings,
        ];
    }

    private static function resolveRelativePath(string $directory, string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return $directory . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * @param list<string> $parts
     */
    private static function floatPart(array $parts, int $index, float $default): float
    {
        if (!isset($parts[$index]) || !is_numeric($parts[$index])) {
            return $default;
        }

        return (float) $parts[$index];
    }
}
