<?php

declare(strict_types=1);

namespace QtBuilder\Parsing;

/**
 * Builds the compiler argument list needed for libclang to parse C++ / Qt headers.
 *
 * Handles OS-specific include paths, clang resource directory discovery,
 * and Qt header path detection.
 */
class ClangArgumentBuilder
{
    /** @var list<string> */
    private array $extraIncludePaths = [];

    /**
     * Base flags that are always needed.
     *
     * @var list<string>
     */
    private const array BASE_ARGS = [
        '-x', 'c++',
        '-std=c++17',
    ];

    /**
     * @param list<string> $extraIncludePaths  Additional -I paths supplied by the caller (e.g. CLI --include).
     */
    public function __construct(array $extraIncludePaths = [])
    {
        $this->extraIncludePaths = $extraIncludePaths;
    }

    /**
     * Compute the full argument list ready for TranslationUnit::fromFile().
     *
     * @return list<string>
     */
    public function build(): array
    {
        $args = self::BASE_ARGS;
        $hasExplicitQtIncludes = $this->hasExplicitQtIncludePath();

        $args = [...$args, ...$this->extraIncludeArgs()];
        $args = [...$args, ...$this->discoverSystemIncludes()];
        $args = [...$args, ...$this->discoverClangResourceDir()];
        if (!$hasExplicitQtIncludes) {
            $args = [...$args, ...$this->discoverQtIncludes()];
        }
        $args = [...$args, ...$this->qtFeatureOverrides()];
        $args = [...$args, ...$this->platformDefines()];

        return array_values(array_unique($args));
    }

    /**
     * Pre-include parser feature overrides for optional Qt dependencies.
     *
     * @return list<string>
     */
    private function qtFeatureOverrides(): array
    {
        $overrideHeader = dirname(__DIR__, 2) . '/templates/clang/qt_feature_overrides.h';
        if (!is_file($overrideHeader)) {
            return [];
        }

        return ['-include', $overrideHeader];
    }

    // ------------------------------------------------------------------
    // System include paths
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function discoverSystemIncludes(): array
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => $this->windowsSystemIncludes(),
            'Darwin' => $this->darwinSystemIncludes(),
            default => $this->linuxSystemIncludes(),
        };
    }

    /**
     * @return list<string>
     */
    private function linuxSystemIncludes(): array
    {
        $candidates = [
            '/usr/local/include',
            '/usr/include',
        ];

        return $this->resolveIncludePaths($candidates);
    }

    /**
     * @return list<string>
     */
    private function darwinSystemIncludes(): array
    {
        $candidates = [
            '/Applications/Xcode.app/Contents/Developer/Toolchains/XcodeDefault.xctoolchain/usr/include',
            '/Library/Developer/CommandLineTools/usr/include',
            '/opt/homebrew/include',
            '/opt/homebrew/opt/llvm/include',
        ];

        $args = $this->resolveIncludePaths($candidates);

        $sdkPath = trim((string) shell_exec('xcrun --show-sdk-path 2>/dev/null'));
        if ($sdkPath !== '' && is_dir($sdkPath)) {
            $args[] = '-isysroot';
            $args[] = $sdkPath;
        }

        return $args;
    }

    /**
     * @return list<string>
     */
    private function windowsSystemIncludes(): array
    {
        $candidates = [
            'C:/Program Files (x86)/Microsoft Visual Studio/*/Community/VC/Tools/MSVC/*/include',
            'C:/Program Files (x86)/Microsoft Visual Studio/*/BuildTools/VC/Tools/MSVC/*/include',
            'C:/Program Files/Microsoft Visual Studio/*/Community/VC/Tools/MSVC/*/include',
            'C:/Program Files/Microsoft Visual Studio/*/BuildTools/VC/Tools/MSVC/*/include',
            'C:/Program Files (x86)/Windows Kits/10/Include/*/ucrt',
            'C:/Program Files (x86)/Windows Kits/10/Include/*/shared',
            'C:/Program Files (x86)/Windows Kits/10/Include/*/km',
            'C:/Program Files (x86)/Windows Kits/10/Include/*/winrt',
            'C:/Program Files (x86)/Windows Kits/10/Include/*/cppwinrt',
        ];

        return $this->resolveIncludePaths($candidates);
    }

    // ------------------------------------------------------------------
    // Clang resource directory
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function discoverClangResourceDir(): array
    {
        $searchPatterns = match (PHP_OS_FAMILY) {
            'Darwin' => ['/opt/homebrew/Cellar/llvm/*/lib/clang/*'],
            'Windows' => ['C:/Program Files/LLVM/lib/clang/*'],
            default => ['/usr/lib/clang/*', '/usr/lib/llvm-*/lib/clang/*'],
        };

        foreach ($searchPatterns as $pattern) {
            $matches = glob($pattern);
            if ($matches === false || $matches === []) {
                continue;
            }

            $versionDirs = array_filter(
                $matches,
                static fn(string $path): bool => preg_match('/\/\d+(\.\d+)*$/', $path) === 1 && is_dir($path),
            );

            if ($versionDirs !== []) {
                rsort($versionDirs);

                return ['-resource-dir', array_values($versionDirs)[0]];
            }
        }

        return [];
    }

    // ------------------------------------------------------------------
    // Qt include paths
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function discoverQtIncludes(): array
    {
        $searchPaths = match (PHP_OS_FAMILY) {
            'Darwin' => [
                '/opt/homebrew/opt/qt/include',
                '/opt/homebrew/opt/qt@6/include',
                '/usr/local/opt/qt/include',
            ],
            default => [
                '/usr/include/qt6',
                '/usr/include/x86_64-linux-gnu/qt6',
                '/usr/include/aarch64-linux-gnu/qt6',
            ],
        };

        $args = [];

        foreach ($searchPaths as $path) {
            if (is_dir($path)) {
                $args[] = '-I' . $path;

                $subdirs = glob($path . '/*');
                if ($subdirs !== false) {
                    foreach ($subdirs as $subdir) {
                        if (is_dir($subdir)) {
                            $args[] = '-I' . $subdir;
                        }
                    }
                }

                break;
            }
        }

        return $args;
    }

    // ------------------------------------------------------------------
    // Platform defines
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function platformDefines(): array
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => ['-D_WIN32', '-D_WINDOWS'],
            'Linux' => ['-D', '__linux__'],
            default => [],
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Resolve glob patterns to -I flags for directories that actually exist.
     *
     * @param list<string> $patterns
     * @return list<string>
     */
    private function resolveIncludePaths(array $patterns): array
    {
        $args = [];

        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if ($matches === false) {
                continue;
            }
            foreach ($matches as $path) {
                if (is_dir($path)) {
                    $args[] = '-I' . $path;
                }
            }
        }

        return $args;
    }

    /**
     * @return list<string>
     */
    private function extraIncludeArgs(): array
    {
        $args = [];

        foreach ($this->extraIncludePaths as $path) {
            if ($path === '') {
                continue;
            }

            if (str_starts_with($path, '-')) {
                $args[] = $path;
                continue;
            }

            $args[] = '-I' . $path;
        }

        return $args;
    }

    private function hasExplicitQtIncludePath(): bool
    {
        foreach ($this->extraIncludePaths as $path) {
            if ($path === '' || str_starts_with($path, '-')) {
                continue;
            }

            $trimmed = rtrim($path, '/');
            $baseName = basename($trimmed);
            if (preg_match('/^Qt[A-Za-z0-9]+$/', $baseName) === 1) {
                return true;
            }

            if (is_dir($trimmed . '/QtCore') || is_dir($trimmed . '/QtGui') || is_dir($trimmed . '/QtQml')) {
                return true;
            }
        }

        return false;
    }
}
