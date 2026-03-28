<?php

declare(strict_types=1);

use QtBuilder\Build\StaticBuildStager;

it('stages only extension source files into the target tree', function (): void {
    $sourceDir = qt_temp_dir('qtbuilder-static-stage-src-') . '/ext';
    $targetDir = qt_temp_dir('qtbuilder-static-stage-dst-') . '/php-src/ext/qt';

    mkdir($sourceDir . '/classes/.libs', 0777, true);
    mkdir($sourceDir . '/src_00', 0777, true);
    mkdir($sourceDir . '/build', 0777, true);

    file_put_contents($sourceDir . '/config.m4', "config\n");
    file_put_contents($sourceDir . '/config.w32', "config\n");
    file_put_contents($sourceDir . '/php_qt.h', "header\n");
    file_put_contents($sourceDir . '/qt.cpp', "source\n");
    file_put_contents($sourceDir . '/qt_php_compat.h', "compat\n");
    file_put_contents($sourceDir . '/configure', "bootstrap\n");
    file_put_contents($sourceDir . '/Makefile', "bootstrap\n");
    file_put_contents($sourceDir . '/build/gen_stub.php', "<?php\n");
    file_put_contents($sourceDir . '/classes/qt_qpoint.h', "header\n");
    file_put_contents($sourceDir . '/classes/qt_qpoint.cpp', "source\n");
    file_put_contents($sourceDir . '/classes/qt_qpoint.stub.php', "<?php\n");
    file_put_contents($sourceDir . '/classes/qt_qpoint_arginfo.h', "arginfo\n");
    file_put_contents($sourceDir . '/classes/.libs/qt_qpoint.o', "object\n");
    file_put_contents($sourceDir . '/src_00/qt_bucket_00.cpp', "unity\n");

    $result = (new StaticBuildStager())->stage($sourceDir, $targetDir);

    expect(is_file($targetDir . '/config.m4'))->toBeTrue()
        ->and(is_file($targetDir . '/config.w32'))->toBeTrue()
        ->and(is_file($targetDir . '/php_qt.h'))->toBeTrue()
        ->and(is_file($targetDir . '/qt.cpp'))->toBeTrue()
        ->and(is_file($targetDir . '/qt_php_compat.h'))->toBeTrue()
        ->and(is_file($targetDir . '/classes/qt_qpoint.h'))->toBeTrue()
        ->and(is_file($targetDir . '/classes/qt_qpoint.cpp'))->toBeTrue()
        ->and(is_file($targetDir . '/classes/qt_qpoint.stub.php'))->toBeTrue()
        ->and(is_file($targetDir . '/src_00/qt_bucket_00.cpp'))->toBeTrue()
        ->and(is_file($targetDir . '/configure'))->toBeFalse()
        ->and(is_file($targetDir . '/Makefile'))->toBeFalse()
        ->and(is_file($targetDir . '/build/gen_stub.php'))->toBeFalse()
        ->and(is_file($targetDir . '/classes/qt_qpoint_arginfo.h'))->toBeFalse()
        ->and(is_file($targetDir . '/classes/.libs/qt_qpoint.o'))->toBeFalse()
        ->and(is_file($targetDir . '/.qtb-stage-manifest.json'))->toBeTrue()
        ->and($result->writeStats->written())->toBeGreaterThan(0)
        ->and($result->prunedFiles)->toBe([]);
});

it('keeps a repeated static stage unchanged when sources match', function (): void {
    $sourceDir = qt_temp_dir('qtbuilder-static-stage-repeat-src-') . '/ext';
    $targetDir = qt_temp_dir('qtbuilder-static-stage-repeat-dst-') . '/php-src/ext/qt';

    mkdir($sourceDir . '/classes', 0777, true);
    file_put_contents($sourceDir . '/config.m4', "config\n");
    file_put_contents($sourceDir . '/php_qt.h', "header\n");
    file_put_contents($sourceDir . '/qt.cpp', "source\n");
    file_put_contents($sourceDir . '/classes/qt_qpoint.h', "header\n");

    $stager = new StaticBuildStager();
    $stager->stage($sourceDir, $targetDir);
    $second = $stager->stage($sourceDir, $targetDir);

    expect($second->writeStats->written())->toBe(0)
        ->and($second->writeStats->unchanged())->toBeGreaterThan(0)
        ->and($second->prunedFiles)->toBe([]);
});

it('prunes stale tracked files and normalizes relative target paths', function (): void {
    $workspace = qt_temp_dir('qtbuilder-static-stage-relative-');
    $sourceDir = $workspace . '/build/ext';
    $originalCwd = getcwd();

    mkdir($sourceDir . '/classes', 0777, true);
    file_put_contents($sourceDir . '/config.m4', "config\n");
    file_put_contents($sourceDir . '/php_qt.h', "header\n");
    file_put_contents($sourceDir . '/qt.cpp', "source\n");
    file_put_contents($sourceDir . '/classes/qt_qpoint.h', "header\n");

    chdir($workspace);

    try {
        $targetDir = 'php-src/ext/qt';
        $absoluteTargetDir = str_replace('\\', '/', $workspace . '/php-src/ext/qt');
        mkdir($absoluteTargetDir . '/classes', 0777, true);
        file_put_contents($absoluteTargetDir . '/classes/qt_stale.h', "stale\n");
        file_put_contents(
            $absoluteTargetDir . '/.qtb-stage-manifest.json',
            json_encode([
                'schema_version' => 1,
                'source_dir' => $sourceDir,
                'target_dir' => $absoluteTargetDir,
                'files' => ['classes/qt_stale.h'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        $result = (new StaticBuildStager())->stage($sourceDir, $targetDir);

        expect($result->targetDir)->toBe($absoluteTargetDir)
            ->and(is_file($absoluteTargetDir . '/classes/qt_stale.h'))->toBeFalse()
            ->and($result->prunedFiles)->toContain($absoluteTargetDir . '/classes/qt_stale.h');
    } finally {
        if (is_string($originalCwd) && $originalCwd !== '') {
            chdir($originalCwd);
        }
    }
});
