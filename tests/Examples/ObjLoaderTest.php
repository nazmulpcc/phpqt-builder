<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/examples/obj-browser/ObjMaterialLibrary.php';
require_once dirname(__DIR__, 2) . '/examples/obj-browser/ObjLoader.php';

it('loads the bundled sample obj with material and texture metadata', function (): void {
    $scene = ObjLoader::load(dirname(__DIR__, 2) . '/examples/obj-browser/sample.obj');

    expect($scene['triangle_count'])->toBe(12)
        ->and($scene['vertex_count'])->toBe(36)
        ->and($scene['materials'])->toHaveCount(1)
        ->and($scene['materials'][0]['texture_path'])->toEndWith('sample_diffuse.png')
        ->and($scene['batches'])->toHaveCount(1);
});

it('triangulates quads and resolves negative indices', function (): void {
    $tempDir = sys_get_temp_dir() . '/phpqt-obj-' . uniqid('', true);
    mkdir($tempDir, 0777, true);

    $objPath = $tempDir . '/quad.obj';
    file_put_contents($objPath, <<<OBJ
v -1 0 0
v 1 0 0
v 1 1 0
v -1 1 0
f -4 -3 -2 -1
OBJ);

    $scene = ObjLoader::load($objPath);

    expect($scene['triangle_count'])->toBe(2)
        ->and($scene['vertex_count'])->toBe(6);
});

it('generates normals when the obj does not provide them', function (): void {
    $tempDir = sys_get_temp_dir() . '/phpqt-obj-' . uniqid('', true);
    mkdir($tempDir, 0777, true);

    $objPath = $tempDir . '/triangle.obj';
    file_put_contents($objPath, <<<OBJ
v 0 0 0
v 1 0 0
v 0 1 0
f 1 2 3
OBJ);

    $scene = ObjLoader::load($objPath);

    expect($scene['vertex_count'])->toBe(3)
        ->and(array_slice($scene['vertex_floats'], 3, 3))->toBe([0.0, 0.0, 1.0]);
});

it('parses mtl diffuse color alpha and texture paths', function (): void {
    $tempDir = sys_get_temp_dir() . '/phpqt-mtl-' . uniqid('', true);
    mkdir($tempDir, 0777, true);

    $mtlPath = $tempDir . '/sample.mtl';
    file_put_contents($mtlPath, <<<MTL
newmtl Painted
Kd 0.25 0.5 0.75
Tr 0.2
map_Kd tex.png
MTL);
    file_put_contents($tempDir . '/tex.png', '');

    $result = ObjMaterialLibrary::loadLibraries([$mtlPath]);

    expect($result['materials']['Painted']['diffuse'])->toBe([0.25, 0.5, 0.75])
        ->and($result['materials']['Painted']['alpha'])->toBe(0.8)
        ->and($result['materials']['Painted']['texture_path'])->toBe($tempDir . '/tex.png');
});
