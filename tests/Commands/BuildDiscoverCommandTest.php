<?php

declare(strict_types=1);

use QtBuilder\Commands\BuildCommand;
use QtBuilder\Commands\BuildDiscoverCommand;
use QtBuilder\Tests\Support\FakeExtensionBootstrapper;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

$removeDir = static function (string $path) use (&$removeDir): void {
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = $path . '/' . $entry;
        if (is_dir($entryPath)) {
            $removeDir($entryPath);
            continue;
        }

        @unlink($entryPath);
    }

    @rmdir($path);
};

it('writes reusable build metadata during discovery', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-discover-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    $classCacheDir = $buildRoot . '/classes';

    $result = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );

    expect($result['exitCode'])->toBe(Command::SUCCESS, $result['display']);
    expect($result['display'])->toContain(
        'Running 2 parallel discovery worker(s)...',
        'Class structure cache:',
        '0 hit(s), 5 miss(es)',
        'Discovery pass 1',
    );
    expect(is_file($metadataDir . '/discovery_cache.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/accepted_candidates.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/allowed_classes.json'))->toBeTrue()
        ->and(is_file($classCacheDir . '/QAbstractItemModel.json'))->toBeTrue()
        ->and(is_file($classCacheDir . '/QPoint.json'))->toBeTrue()
        ->and(is_file($outputDir . '/config.m4'))->toBeFalse();

    $cache = qt_decode_json((string) file_get_contents($metadataDir . '/discovery_cache.json'));
    expect($cache['candidate_count'])->toBe(6)
        ->and($cache['accepted_candidates'])->toHaveCount(5)
        ->and($cache['skipped_classes'])->toHaveCount(1)
        ->and(array_column($cache['accepted_candidates'], 'class'))
            ->toBe(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree']);

    $allowedClasses = qt_decode_json((string) file_get_contents($metadataDir . '/allowed_classes.json'));
    expect($allowedClasses)->toBe(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree']);
});

it('reuses class structure cache after generated metadata is cleared', function () use ($removeDir): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-discover-reuse-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    $classCacheDir = $buildRoot . '/classes';

    $firstRun = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );
    expect($firstRun['exitCode'])->toBe(Command::SUCCESS, $firstRun['display']);

    expect(is_file($classCacheDir . '/QPoint.json'))->toBeTrue();
    $removeDir($metadataDir);
    expect(is_dir($metadataDir))->toBeFalse();

    $secondRun = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );

    expect($secondRun['exitCode'])->toBe(Command::SUCCESS, $secondRun['display']);
    expect($secondRun['display'])->toContain('Class structure cache:', '5 hit(s), 0 miss(es)')
        ->not->toContain('Building cached class structures with 2 parallel worker(s)...');
    expect(is_file($metadataDir . '/discovery_cache.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/accepted_candidates.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/allowed_classes.json'))->toBeTrue();
});

it('feeds discovery cache into the build command', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-discover-build-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $bootstrapper = new FakeExtensionBootstrapper();

    $discover = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );
    expect($discover['exitCode'])->toBe(Command::SUCCESS, $discover['display']);

    $build = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );

    expect($build['exitCode'])->toBe(Command::SUCCESS, $build['display'])
        ->and($build['display'])->toContain('Using cached build metadata:')
        ->and($bootstrapper->contexts)->toHaveCount(1);
});
