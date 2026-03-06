<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Runtime\Support;

use Symfony\Component\Process\Process;

final class QtRuntimeProcessRunner
{
    private const RESULT_PREFIX = 'PHPQT_RESULT=';

    public static function extensionPath(): ?string
    {
        $configured = getenv('PHPQT_EXTENSION');
        if (is_string($configured) && $configured !== '') {
            return is_file($configured) ? $configured : null;
        }

        $default = dirname(__DIR__, 3) . '/build/ext/.libs/qt.so';

        return is_file($default) ? $default : null;
    }

    public static function moduleExtensionPath(string $module): ?string
    {
        $envName = 'PHPQT_EXTENSION_' . strtoupper($module);
        $configured = getenv($envName);
        if (is_string($configured) && $configured !== '') {
            return is_file($configured) ? $configured : null;
        }

        $extensionName = strtolower($module);
        $root = dirname(__DIR__, 3) . '/build/' . $module . '/ext';
        $candidates = [
            $root . '/.libs/' . $extensionName . '.so',
            $root . '/modules/' . $extensionName . '.so',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function fixturePath(string $fixture): string
    {
        return dirname(__DIR__) . '/Fixtures/' . ltrim($fixture, '/');
    }

    public static function runFixture(string $fixture, array $env = [], int $timeout = 5): QtRuntimeProcessResult
    {
        $fixturePath = self::fixturePath($fixture);
        if (!is_file($fixturePath)) {
            return new QtRuntimeProcessResult(1, '', '', [
                'reason' => sprintf('Fixture not found: %s', $fixturePath),
            ]);
        }

        return self::runScript($fixturePath, $env, $timeout);
    }

    /**
     * @param list<string> $modules
     */
    public static function runFixtureWithModules(string $fixture, array $modules, array $env = [], int $timeout = 5): QtRuntimeProcessResult
    {
        $fixturePath = self::fixturePath($fixture);
        if (!is_file($fixturePath)) {
            return new QtRuntimeProcessResult(1, '', '', [
                'reason' => sprintf('Fixture not found: %s', $fixturePath),
            ]);
        }

        $extensions = [];
        foreach ($modules as $module) {
            $path = self::moduleExtensionPath($module);
            if ($path === null) {
                return new QtRuntimeProcessResult(77, '', '', [
                    'reason' => sprintf('Built %s extension not found. Run `php qtb build:modules --modules=%s` first.', strtolower($module), implode(',', $modules)),
                ]);
            }

            $extensions[] = $path;
        }

        return self::runScript($fixturePath, $env, $timeout, $extensions);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parsePayload(string $stdout): array
    {
        $lines = preg_split('/\R/', trim($stdout)) ?: [];

        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $line = $lines[$index];
            if (!str_starts_with($line, self::RESULT_PREFIX)) {
                continue;
            }

            $json = substr($line, strlen(self::RESULT_PREFIX));
            $decoded = json_decode($json, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param list<string>|null $extensionPaths
     */
    private static function runScript(string $scriptPath, array $env, int $timeout, ?array $extensionPaths = null): QtRuntimeProcessResult
    {
        $extensionPaths ??= [];
        if ($extensionPaths === []) {
            $extensionPath = self::extensionPath();
            if ($extensionPath !== null) {
                $extensionPaths[] = $extensionPath;
            }
        }

        if ($extensionPaths === []) {
            return new QtRuntimeProcessResult(77, '', '', [
                'reason' => 'Built qt extension not found. Run `php qtb build` first or set PHPQT_EXTENSION.',
            ]);
        }

        $runtimeEnv = array_merge($_ENV, [
            'PHPQT_EXTENSION' => $extensionPaths[0],
            'PHPQT_TEST_MODE' => '1',
            'QT_QPA_PLATFORM' => $env['QT_QPA_PLATFORM'] ?? 'offscreen',
        ], $env);

        $command = [PHP_BINARY];
        foreach ($extensionPaths as $extensionPath) {
            $command[] = '-dextension=' . $extensionPath;
        }
        $command[] = $scriptPath;

        $process = new Process(
            $command,
            dirname(__DIR__, 3),
            $runtimeEnv,
        );
        $process->setTimeout($timeout);
        $process->run();

        return new QtRuntimeProcessResult(
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            $process->getErrorOutput(),
            self::parsePayload($process->getOutput()),
        );
    }
}
