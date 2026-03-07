<?php

declare(strict_types=1);

final class ObjLoader
{
    /**
     * @return array{
     *   label: string,
     *   path: string,
     *   vertex_floats: list<float>,
     *   vertex_count: int,
     *   triangle_count: int,
     *   materials: list<array{name: string, diffuse: array{float, float, float}, alpha: float, texture_path: ?string}>,
     *   batches: list<array{material_name: string, material_index: int, first_vertex: int, vertex_count: int}>,
     *   bounds: array{min: array{float, float, float}, max: array{float, float, float}, center: array{float, float, float}, radius: float},
     *   warnings: list<string>
     * }
     */
    public static function load(string $objPath): array
    {
        if (!is_file($objPath)) {
            throw new RuntimeException(sprintf('OBJ file not found: %s', $objPath));
        }

        $resolvedPath = realpath($objPath) ?: $objPath;
        $directory = dirname($resolvedPath);
        $lines = file($resolvedPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException(sprintf('Unable to read OBJ file: %s', $resolvedPath));
        }

        /** @var list<array{float, float, float}> $positions */
        $positions = [];
        /** @var list<array{float, float}> $texcoords */
        $texcoords = [];
        /** @var list<array{float, float, float}> $normals */
        $normals = [];
        /** @var list<float> $vertexFloats */
        $vertexFloats = [];
        /** @var list<string> $warnings */
        $warnings = [];
        /** @var list<string> $mtlPaths */
        $mtlPaths = [];
        /** @var list<string> $usedMaterialNames */
        $usedMaterialNames = [];
        /** @var list<array{material_name: string, first_vertex: int, vertex_count: int}> $batches */
        $batches = [];

        $currentMaterial = '__default__';
        $triangleCount = 0;

        $boundsMin = [INF, INF, INF];
        $boundsMax = [-INF, -INF, -INF];

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
                case 'v':
                    $vertex = [
                        self::floatPart($parts, 0, 0.0),
                        self::floatPart($parts, 1, 0.0),
                        self::floatPart($parts, 2, 0.0),
                    ];
                    $positions[] = $vertex;
                    $boundsMin[0] = min($boundsMin[0], $vertex[0]);
                    $boundsMin[1] = min($boundsMin[1], $vertex[1]);
                    $boundsMin[2] = min($boundsMin[2], $vertex[2]);
                    $boundsMax[0] = max($boundsMax[0], $vertex[0]);
                    $boundsMax[1] = max($boundsMax[1], $vertex[1]);
                    $boundsMax[2] = max($boundsMax[2], $vertex[2]);
                    break;

                case 'vt':
                    $texcoords[] = [
                        self::floatPart($parts, 0, 0.0),
                        self::floatPart($parts, 1, 0.0),
                    ];
                    break;

                case 'vn':
                    $normals[] = self::normalizeVector([
                        self::floatPart($parts, 0, 0.0),
                        self::floatPart($parts, 1, 0.0),
                        self::floatPart($parts, 2, 1.0),
                    ]);
                    break;

                case 'mtllib':
                    $reference = trim(implode(' ', $parts));
                    if ($reference !== '') {
                        $mtlPaths[] = $directory . DIRECTORY_SEPARATOR . $reference;
                    }
                    break;

                case 'usemtl':
                    $reference = trim(implode(' ', $parts));
                    $currentMaterial = $reference !== '' ? $reference : '__default__';
                    break;

                case 'f':
                    if (count($parts) < 3) {
                        $warnings[] = sprintf('Ignoring malformed face in %s:%d', basename($resolvedPath), $lineNumber + 1);
                        continue 2;
                    }

                    $faceRefs = [];
                    foreach ($parts as $part) {
                        $faceRefs[] = self::parseFaceReference($part, count($positions), count($texcoords), count($normals));
                    }

                    for ($index = 1, $count = count($faceRefs) - 1; $index < $count; $index++) {
                        $triangle = [$faceRefs[0], $faceRefs[$index], $faceRefs[$index + 1]];
                        self::appendTriangle(
                            $triangle,
                            $positions,
                            $texcoords,
                            $normals,
                            $currentMaterial,
                            $vertexFloats,
                            $usedMaterialNames,
                            $batches
                        );
                        $triangleCount++;
                    }
                    break;

                default:
                    break;
            }
        }

        if ($positions === [] || $triangleCount === 0) {
            throw new RuntimeException(sprintf('OBJ file contains no renderable triangles: %s', basename($resolvedPath)));
        }

        $materialResult = ObjMaterialLibrary::loadLibraries(array_values(array_unique($mtlPaths)));
        $warnings = array_merge($warnings, $materialResult['warnings']);
        $materialsByName = $materialResult['materials'];
        if (!isset($materialsByName['__default__'])) {
            $materialsByName['__default__'] = [
                'name' => '__default__',
                'diffuse' => [0.85, 0.88, 0.95],
                'alpha' => 1.0,
                'texture_path' => null,
            ];
        }

        if ($usedMaterialNames === []) {
            $usedMaterialNames[] = '__default__';
        }

        /** @var list<array{name: string, diffuse: array{float, float, float}, alpha: float, texture_path: ?string}> $materials */
        $materials = [];
        /** @var array<string, int> $materialIndexMap */
        $materialIndexMap = [];
        foreach ($usedMaterialNames as $materialName) {
            $material = $materialsByName[$materialName] ?? [
                'name' => $materialName,
                'diffuse' => [0.85, 0.88, 0.95],
                'alpha' => 1.0,
                'texture_path' => null,
            ];
            $materialIndexMap[$materialName] = count($materials);
            $materials[] = $material;
        }

        $resolvedBatches = [];
        foreach ($batches as $batch) {
            $resolvedBatches[] = [
                'material_name' => $batch['material_name'],
                'material_index' => $materialIndexMap[$batch['material_name']] ?? 0,
                'first_vertex' => $batch['first_vertex'],
                'vertex_count' => $batch['vertex_count'],
            ];
        }

        $center = [
            ($boundsMin[0] + $boundsMax[0]) / 2.0,
            ($boundsMin[1] + $boundsMax[1]) / 2.0,
            ($boundsMin[2] + $boundsMax[2]) / 2.0,
        ];
        $radius = max(
            0.001,
            self::vectorLength([
                $boundsMax[0] - $center[0],
                $boundsMax[1] - $center[1],
                $boundsMax[2] - $center[2],
            ])
        );

        return [
            'label' => basename($resolvedPath),
            'path' => $resolvedPath,
            'vertex_floats' => $vertexFloats,
            'vertex_count' => (int) (count($vertexFloats) / 8),
            'triangle_count' => $triangleCount,
            'materials' => $materials,
            'batches' => $resolvedBatches,
            'bounds' => [
                'min' => [$boundsMin[0], $boundsMin[1], $boundsMin[2]],
                'max' => [$boundsMax[0], $boundsMax[1], $boundsMax[2]],
                'center' => $center,
                'radius' => $radius,
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param list<array{vertex_index: int, texcoord_index: ?int, normal_index: ?int}> $triangle
     * @param list<array{float, float, float}> $positions
     * @param list<array{float, float}> $texcoords
     * @param list<array{float, float, float}> $normals
     * @param list<float> $vertexFloats
     * @param list<string> $usedMaterialNames
     * @param list<array{material_name: string, first_vertex: int, vertex_count: int}> $batches
     */
    private static function appendTriangle(
        array $triangle,
        array $positions,
        array $texcoords,
        array $normals,
        string $materialName,
        array &$vertexFloats,
        array &$usedMaterialNames,
        array &$batches
    ): void {
        $positionA = $positions[$triangle[0]['vertex_index']];
        $positionB = $positions[$triangle[1]['vertex_index']];
        $positionC = $positions[$triangle[2]['vertex_index']];
        $faceNormal = self::triangleNormal($positionA, $positionB, $positionC);

        if ($batches === [] || $batches[array_key_last($batches)]['material_name'] !== $materialName) {
            $batches[] = [
                'material_name' => $materialName,
                'first_vertex' => (int) (count($vertexFloats) / 8),
                'vertex_count' => 0,
            ];
            if (!in_array($materialName, $usedMaterialNames, true)) {
                $usedMaterialNames[] = $materialName;
            }
        }

        foreach ($triangle as $vertexRef) {
            $position = $positions[$vertexRef['vertex_index']];
            $normal = $vertexRef['normal_index'] !== null && isset($normals[$vertexRef['normal_index']])
                ? $normals[$vertexRef['normal_index']]
                : $faceNormal;
            $uv = $vertexRef['texcoord_index'] !== null && isset($texcoords[$vertexRef['texcoord_index']])
                ? $texcoords[$vertexRef['texcoord_index']]
                : [0.0, 0.0];

            array_push(
                $vertexFloats,
                $position[0],
                $position[1],
                $position[2],
                $normal[0],
                $normal[1],
                $normal[2],
                $uv[0],
                1.0 - $uv[1]
            );
        }

        $lastIndex = array_key_last($batches);
        if ($lastIndex !== null) {
            $batches[$lastIndex]['vertex_count'] += 3;
        }
    }

    /**
     * @return array{vertex_index: int, texcoord_index: ?int, normal_index: ?int}
     */
    private static function parseFaceReference(string $reference, int $positionCount, int $texcoordCount, int $normalCount): array
    {
        $segments = explode('/', $reference);

        return [
            'vertex_index' => self::resolveIndex($segments[0] ?? '0', $positionCount),
            'texcoord_index' => ($segments[1] ?? '') !== '' ? self::resolveIndex($segments[1], $texcoordCount) : null,
            'normal_index' => ($segments[2] ?? '') !== '' ? self::resolveIndex($segments[2], $normalCount) : null,
        ];
    }

    private static function resolveIndex(string $segment, int $count): int
    {
        $index = (int) $segment;
        if ($index > 0) {
            return $index - 1;
        }

        return $count + $index;
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

    /**
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     * @param array{float, float, float} $c
     * @return array{float, float, float}
     */
    private static function triangleNormal(array $a, array $b, array $c): array
    {
        $ab = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
        $ac = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];

        return self::normalizeVector([
            ($ab[1] * $ac[2]) - ($ab[2] * $ac[1]),
            ($ab[2] * $ac[0]) - ($ab[0] * $ac[2]),
            ($ab[0] * $ac[1]) - ($ab[1] * $ac[0]),
        ]);
    }

    /**
     * @param array{float, float, float} $vector
     * @return array{float, float, float}
     */
    private static function normalizeVector(array $vector): array
    {
        $length = self::vectorLength($vector);
        if ($length < 0.000001) {
            return [0.0, 0.0, 1.0];
        }

        return [
            $vector[0] / $length,
            $vector[1] / $length,
            $vector[2] / $length,
        ];
    }

    /**
     * @param array{float, float, float} $vector
     */
    private static function vectorLength(array $vector): float
    {
        return sqrt(($vector[0] ** 2) + ($vector[1] ** 2) + ($vector[2] ** 2));
    }
}
