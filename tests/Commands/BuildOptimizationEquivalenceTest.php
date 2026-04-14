<?php

declare(strict_types=1);

use QtBuilder\Commands\BuildCommand;
use QtBuilder\Tests\Support\FakeExtensionBootstrapper;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

it('keeps build artifacts equivalent across cache and job matrix', function (): void {
    $fixtureMatrix = [
        'enum-qt' => 'QtCore,QtSql',
        'list-parent-qt' => 'QtCore',
        'supplemental-qt' => 'QtGui,QtOpenGL',
        'module-split-qt' => 'QtWidgets',
    ];

    foreach ($fixtureMatrix as $fixtureName => $modules) {
        $fixtureRoot = qt_fixture_path($fixtureName);
        $baseline = qt_capture_normalized_build_artifacts(
            $fixtureRoot,
            $modules,
            jobs: 1,
            warmCache: false,
        );

        $variants = [
            ['jobs' => 2, 'warm_cache' => false],
            ['jobs' => 1, 'warm_cache' => true],
            ['jobs' => 2, 'warm_cache' => true],
        ];

        foreach ($variants as $variant) {
            $artifacts = qt_capture_normalized_build_artifacts(
                $fixtureRoot,
                $modules,
                jobs: (int) $variant['jobs'],
                warmCache: (bool) $variant['warm_cache'],
            );

            qt_assert_equivalent_artifacts(
                $baseline,
                $artifacts,
                sprintf(
                    '%s (jobs=%d, warm_cache=%s)',
                    $fixtureName,
                    (int) $variant['jobs'],
                    (bool) $variant['warm_cache'] ? 'true' : 'false',
                ),
            );
        }
    }
});

/**
 * @return array<string, string>
 */
function qt_capture_normalized_build_artifacts(
    string $fixtureRoot,
    string $modules,
    int $jobs,
    bool $warmCache,
): array {
    $buildRoot = qt_equivalence_temp_dir('qtbuilder-opt-equivalence-');
    $command = new BuildCommand(
        FakeSystemInformation::passing(),
        new FakeExtensionBootstrapper(),
    );
    $tester = new CommandTester($command);
    $input = [
        '--qt-path' => $fixtureRoot,
        'modules' => $modules,
        '--output' => $buildRoot,
        '--jobs' => (string) $jobs,
        '--no-build' => true,
    ];

    set_error_handler(static function (int $severity, string $message): bool {
        if ($severity === E_WARNING && $message === 'mkdir(): File exists') {
            return true;
        }

        return false;
    });

    try {
        if ($warmCache) {
            $primeExitCode = $tester->execute($input);
            expect($primeExitCode)->toBe(Command::SUCCESS, $tester->getDisplay());
        }

        $exitCode = $tester->execute($input);
        $display = $tester->getDisplay();
    } finally {
        restore_error_handler();
    }

    expect($exitCode)->toBe(Command::SUCCESS, $display);
    if ($warmCache) {
        expect($display)->toContain('Using cached build metadata:');
    }

    $artifacts = qt_collect_normalized_artifacts($buildRoot);
    qt_remove_dir($buildRoot);

    return $artifacts;
}

/**
 * @return array<string, string>
 */
function qt_collect_normalized_artifacts(string $buildRoot): array
{
    $metadataDir = $buildRoot . '/generated';
    $classesDir = $buildRoot . '/ext/classes';

    $jsonArtifacts = [
        'classmap.json',
        'accepted_candidates.json',
        'skipped_classes.json',
        'skipped_methods.json',
        'supplemental_candidates.json',
        'enum_candidate_headers.json',
    ];

    /** @var array<string, string> $artifacts */
    $artifacts = [];
    foreach ($jsonArtifacts as $filename) {
        $path = $metadataDir . '/' . $filename;
        expect(is_file($path))->toBeTrue(sprintf('Missing artifact: %s', $path));
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $normalized = qt_normalize_equivalence_value($decoded, $buildRoot);
        $artifacts[$filename] = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
    }

    foreach (['*.stub.php', '*.h', '*.cpp'] as $pattern) {
        $matches = glob($classesDir . '/' . $pattern) ?: [];
        sort($matches);

        foreach ($matches as $path) {
            $key = 'classes/' . basename($path);
            $contents = (string) file_get_contents($path);
            $contents = str_replace("\r\n", "\n", $contents);
            $contents = str_replace($buildRoot, '__BUILD_ROOT__', $contents);
            $artifacts[$key] = $contents;
        }
    }

    ksort($artifacts);

    return $artifacts;
}

function qt_assert_equivalent_artifacts(array $expected, array $actual, string $context): void
{
    $expectedKeys = array_keys($expected);
    $actualKeys = array_keys($actual);
    sort($expectedKeys);
    sort($actualKeys);

    expect($actualKeys)->toBe($expectedKeys, sprintf('Artifact key mismatch for %s', $context));

    foreach ($expected as $artifact => $contents) {
        $actualContents = $actual[$artifact] ?? null;
        expect($actualContents)->toBe($contents, sprintf(
            "Artifact mismatch for %s: %s",
            $context,
            $artifact,
        ));
    }
}

function qt_normalize_equivalence_value(mixed $value, string $buildRoot): mixed
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            $normalized = array_map(
                static fn(mixed $item): mixed => qt_normalize_equivalence_value($item, $buildRoot),
                $value,
            );
            usort(
                $normalized,
                static fn(mixed $a, mixed $b): int => strcmp(
                    json_encode($a, JSON_UNESCAPED_SLASHES) ?: '',
                    json_encode($b, JSON_UNESCAPED_SLASHES) ?: '',
                ),
            );

            return $normalized;
        }

        $normalized = [];
        $keys = array_keys($value);
        sort($keys);
        foreach ($keys as $key) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $normalizedKey = is_string($key)
                ? qt_normalize_equivalence_string($key, $buildRoot)
                : (string) $key;

            $normalized[$normalizedKey] = qt_normalize_equivalence_value($value[$key], $buildRoot);
        }

        return $normalized;
    }

    if (is_string($value)) {
        return qt_normalize_equivalence_string($value, $buildRoot);
    }

    return $value;
}

function qt_normalize_equivalence_string(string $value, string $buildRoot): string
{
    $normalizedBuildRoot = str_replace('\\', '/', $buildRoot);
    $value = str_replace('\\', '/', $value);
    $value = str_replace($normalizedBuildRoot, '__BUILD_ROOT__', $value);

    if (preg_match('/^[A-Za-z]:\\//', $value) === 1 || str_starts_with($value, '//')) {
        return strtolower($value);
    }

    return $value;
}

function qt_remove_dir(string $path): void
{
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
            qt_remove_dir($entryPath);
            continue;
        }

        @unlink($entryPath);
    }

    @rmdir($path);
}

function qt_equivalence_temp_dir(string $prefix): string
{
    $base = rtrim(sys_get_temp_dir(), '/');

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $path = $base . '/' . $prefix . bin2hex(random_bytes(8));
        if (is_dir($path)) {
            continue;
        }

        if (mkdir($path, 0777, true)) {
            return $path;
        }
    }

    throw new RuntimeException(sprintf('Could not create temp directory for prefix: %s', $prefix));
}
