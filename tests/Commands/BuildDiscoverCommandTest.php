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
    $extDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    $classCacheDir = $buildRoot . '/classes';

    $result = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult();
    expect($result['display'])->toContain(
        'Running 2 parallel discovery worker(s)...',
        'Class structure cache:',
        '0 hit(s), 5 miss(es)',
        'Discovery pass 1',
        'Module acceptance:',
        'Module Name',
        'Class Acceptance',
        'Method Acceptance',
        'QtCore',
    );
    expect(is_file($metadataDir . '/discovery_cache.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/accepted_candidates.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/allowed_classes.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/supplemental_candidates.json'))->toBeTrue()
        ->and((glob($classCacheDir . '/qabstractitemmodel__*.json') ?: []) !== [])->toBeTrue()
        ->and((glob($classCacheDir . '/qpoint__*.json') ?: []) !== [])->toBeTrue()
        ->and(is_file($extDir . '/config.m4'))->toBeFalse();
    expect(substr_count($result['display'], 'Module acceptance:'))->toBe(1);

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
    $metadataDir = $buildRoot . '/generated';
    $classCacheDir = $buildRoot . '/classes';

    $firstRun = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );
    expect($firstRun)->toBeSuccessfulCommandResult();

    expect((glob($classCacheDir . '/qpoint__*.json') ?: []) !== [])->toBeTrue();
    $removeDir($metadataDir);
    expect(is_dir($metadataDir))->toBeFalse();

    $secondRun = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($secondRun)->toBeSuccessfulCommandResult();
    expect($secondRun['display'])->toContain('Class structure cache:', '5 hit(s), 0 miss(es)')
        ->toContain('Module acceptance:', 'Module Name', 'QtCore')
        ->not->toContain('Building cached class structures with 2 parallel worker(s)...');
    expect(substr_count($secondRun['display'], 'Module acceptance:'))->toBe(1);
    expect(is_file($metadataDir . '/discovery_cache.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/accepted_candidates.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/allowed_classes.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/supplemental_candidates.json'))->toBeTrue();
});

it('queues supplemental class candidates discovered through included headers', function (): void {
    $fixtureRoot = qt_fixture_path('supplemental-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-discover-supplemental-' . bin2hex(random_bytes(4));
    $metadataDir = $buildRoot . '/generated';

    $result = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtGui,QtOpenGL',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and($result['display'])->toContain(
            'Supplemental class discovery: queued 1 new candidate(s).',
            'Discovery pass 1',
            'supplemental_candidates.json',
        );

    $accepted = qt_decode_json((string) file_get_contents($metadataDir . '/accepted_candidates.json'));
    expect(array_column($accepted, 'class'))->toContain('QAbstractOpenGLFunctions', 'QOpenGLFunctions_1_0');

    $normalizePath = static fn(string $path): string => str_replace('\\', '/', $path);
    $supplemental = array_map(
        static function (array $entry) use ($normalizePath): array {
            foreach (['public_header', 'parse_header', 'discovered_from_header'] as $field) {
                if (is_string($entry[$field] ?? null)) {
                    $entry[$field] = $normalizePath($entry[$field]);
                }
            }

            return $entry;
        },
        qt_decode_json((string) file_get_contents($metadataDir . '/supplemental_candidates.json')),
    );
    expect($supplemental)->toContainEqual([
        'module' => 'QtOpenGL',
        'class' => 'QAbstractOpenGLFunctions',
        'public_header' => $normalizePath($fixtureRoot . '/include/QtOpenGL/qopenglversionfunctions.h'),
        'parse_header' => $normalizePath($fixtureRoot . '/include/QtOpenGL/qopenglversionfunctions.h'),
        'discovered_from_class' => 'QOpenGLFunctions_1_0',
        'discovered_from_header' => $normalizePath($fixtureRoot . '/include/QtOpenGL/qopenglfunctions_1_0.h'),
        'trigger_reason' => 'unsupported_parent_class',
    ]);

    $cache = qt_decode_json((string) file_get_contents($metadataDir . '/discovery_cache.json'));
    expect(array_column($cache['accepted_candidates'], 'class'))->toContain('QAbstractOpenGLFunctions');

    $secondRun = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtGui,QtOpenGL',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($secondRun)->toBeSuccessfulCommandResult();

    $secondSupplemental = array_map(
        static function (array $entry) use ($normalizePath): array {
            foreach (['public_header', 'parse_header', 'discovered_from_header'] as $field) {
                if (is_string($entry[$field] ?? null)) {
                    $entry[$field] = $normalizePath($entry[$field]);
                }
            }

            return $entry;
        },
        qt_decode_json((string) file_get_contents($metadataDir . '/supplemental_candidates.json')),
    );
    expect($secondSupplemental)->toHaveCount(1)
        ->and($secondSupplemental)->toContainEqual($supplemental[0]);
});

it('feeds discovery cache into the build command', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-discover-build-' . bin2hex(random_bytes(4));
    $bootstrapper = new FakeExtensionBootstrapper();

    $discover = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );
    expect($discover)->toBeSuccessfulCommandResult();

    $build = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($build)->toBeSuccessfulCommandResult()
        ->and($build['display'])->toContain('Using cached build metadata:', 'Module acceptance:', 'Module Name', 'QtCore')
        ->and($bootstrapper->contexts)->toHaveCount(1);
    expect(substr_count($build['display'], 'Module acceptance:'))->toBe(1);
});

it('rejects an ext directory as the build root for discovery', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-discover-invalid-' . bin2hex(random_bytes(4));

    $result = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot . '/ext',
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeFailureCommandResult()
        ->and($result['display'])->toContain('--output must be a build root directory, not an extension directory.');
});

it('clears the build root before discovery when forced', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-discover-force-' . bin2hex(random_bytes(4));
    mkdir($buildRoot . '/generated', 0777, true);
    file_put_contents($buildRoot . '/generated/stale.txt', "stale\n");

    $result = qt_command_result(
        new BuildDiscoverCommand(FakeSystemInformation::passing()),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--force' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and($result['display'])->toContain('Cleared build root:', $buildRoot);
    expect(is_file($buildRoot . '/generated/stale.txt'))->toBeFalse()
        ->and(is_file($buildRoot . '/generated/discovery_cache.json'))->toBeTrue()
        ->and((glob($buildRoot . '/classes/qpoint__*.json') ?: []) !== [])->toBeTrue();
});
