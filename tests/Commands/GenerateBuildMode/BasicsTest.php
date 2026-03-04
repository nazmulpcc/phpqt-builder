<?php

declare(strict_types=1);

use QtBuilder\Tests\Support\GenerateBuildModeRunner;
use Symfony\Component\Console\Command\Command;

it('returns json and writes files in build mode', function (): void {
    $result = GenerateBuildModeRunner::run('qt', [
        'header' => qt_fixture_path('qt/include/QtCore/qpoint.h'),
        'class' => 'QPoint',
        '--qt-path' => qt_fixture_path('qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => 'QPoint',
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and($result->payload['class'])->toBe('QPoint')
        ->and(is_file($result->path('QPoint', 'cpp')))->toBeTrue()
        ->and(is_file($result->path('QPoint', 'h')))->toBeTrue()
        ->and(is_file($result->path('QPoint', 'stub.php')))->toBeTrue()
        ->and(array_column($result->payload['skipped_methods'], 'name'))->toContain('rx');
});

it('returns json without writing files in probe mode', function (): void {
    $result = GenerateBuildModeRunner::run('qt', [
        'header' => qt_fixture_path('qt/include/QtCore/qpoint.h'),
        'class' => 'QPoint',
        '--qt-path' => qt_fixture_path('qt'),
        '--module' => 'QtCore',
        '--worker-mode' => 'probe',
        '--allowed-classes' => 'QPoint',
    ], 'qtbuilder-probe-');

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and($result->payload['class'])->toBe('QPoint')
        ->and(array_key_exists('generated_files', $result->payload))->toBeFalse()
        ->and(is_file($result->path('QPoint', 'cpp')))->toBeFalse()
        ->and(is_file($result->path('QPoint', 'h')))->toBeFalse()
        ->and(is_file($result->path('QPoint', 'stub.php')))->toBeFalse();
});

it('uses explicit includes even when qt path is invalid', function (): void {
    $result = GenerateBuildModeRunner::run('qt', [
        'header' => qt_fixture_path('qt/include/QtCore/qpoint.h'),
        'class' => 'QPoint',
        '--qt-path' => '/definitely/not/a/qt/root',
        '--include' => [
            qt_fixture_path('qt/include'),
            qt_fixture_path('qt/include/QtCore'),
        ],
        '--module' => 'QtCore',
        '--allowed-classes' => 'QPoint',
    ], 'qtbuilder-generate-includes-');

    expect($result->exitCode)->toBe(Command::SUCCESS, $result->display)
        ->and($result->payload['status'])->toBe('ok')
        ->and(is_file($result->path('QPoint', 'cpp')))->toBeTrue();
});

it('can load allowed classes from a json file', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generate-');
    $allowedClassesFile = $outputDir . '/allowed_classes.json';
    file_put_contents($allowedClassesFile, json_encode(['QTree', 'QNode'], JSON_THROW_ON_ERROR));

    $result = GenerateBuildModeRunner::run('qt', [
        'header' => qt_fixture_path('qt/include/QtCore/qtree.h'),
        'class' => 'QTree',
        '--qt-path' => qt_fixture_path('qt'),
        '--module' => 'QtCore',
        '--allowed-classes-file' => $allowedClassesFile,
        '--output' => $outputDir,
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and(is_file($result->path('QTree', 'cpp')))->toBeTrue();
});
