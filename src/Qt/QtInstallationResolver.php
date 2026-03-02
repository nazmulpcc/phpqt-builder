<?php

declare(strict_types=1);

namespace QtBuilder\Qt;

use QtBuilder\Contracts\SystemInformation;
use RuntimeException;
use Symfony\Component\Process\Process;

class QtInstallationResolver
{
    public function __construct(private readonly SystemInformation $systemInformation) {}

    /**
     * @param list<string> $modules
     */
    public function resolve(?string $qtPath = null, array $modules = ['QtCore']): QtInstallation
    {
        if ($qtPath !== null && $qtPath !== '') {
            return $this->resolveFromPath($qtPath, $modules, []);
        }

        $tools = [];

        $qtpaths = $this->findFirstExecutable(['qtpaths6', 'qtpaths']);
        if ($qtpaths !== null) {
            $headers = $this->queryTool($qtpaths, ['--query', 'QT_INSTALL_HEADERS']);
            $libs = $this->queryTool($qtpaths, ['--query', 'QT_INSTALL_LIBS']);
            $hostPrefix = $this->queryTool($qtpaths, ['--query', 'QT_HOST_PREFIX']);

            if ($headers !== null) {
                $tools['qtpaths'] = $qtpaths;

                return $this->resolveFromPath($hostPrefix ?? dirname($headers), $modules, $tools, $headers, $libs);
            }
        }

        $qmake = $this->findFirstExecutable(['qmake6', 'qmake']);
        if ($qmake !== null) {
            $headers = $this->queryTool($qmake, ['-query', 'QT_INSTALL_HEADERS']);
            $libs = $this->queryTool($qmake, ['-query', 'QT_INSTALL_LIBS']);
            $hostPrefix = $this->queryTool($qmake, ['-query', 'QT_HOST_PREFIX']);

            if ($headers !== null) {
                $tools['qmake'] = $qmake;

                return $this->resolveFromPath($hostPrefix ?? dirname($headers), $modules, $tools, $headers, $libs);
            }
        }

        throw new RuntimeException('Unable to resolve a Qt installation. Pass --qt-path or install qtpaths/qmake.');
    }

    /**
     * @param list<string> $modules
     * @param array<string, string> $tools
     */
    private function resolveFromPath(
        string $qtPath,
        array $modules,
        array $tools = [],
        ?string $headersPath = null,
        ?string $libsPath = null,
    ): QtInstallation {
        $rootPath = realpath($qtPath) ?: $qtPath;
        $headersPath ??= is_dir($rootPath . '/include') ? $rootPath . '/include' : null;
        $libsPath ??= is_dir($rootPath . '/lib') ? $rootPath . '/lib' : null;

        $includeRoots = [];
        if ($headersPath !== null && is_dir($headersPath)) {
            $includeRoots[] = $headersPath;
        }

        if ($this->systemInformation->getOsFamily() === 'Darwin') {
            $frameworkLibRoot = $rootPath . '/lib';
            if (is_dir($frameworkLibRoot)) {
                $includeRoots[] = '-F' . $frameworkLibRoot;
            }
        }

        $moduleHeaderRoots = [];
        foreach ($modules as $module) {
            $headerRoot = null;

            if ($headersPath !== null && is_dir($headersPath . '/' . $module)) {
                $headerRoot = $headersPath . '/' . $module;
                $includeRoots[] = $headerRoot;
            }

            if ($headerRoot === null) {
                $frameworkHeaders = $this->frameworkHeaderRoot($rootPath, $module);
                if ($frameworkHeaders !== null) {
                    $headerRoot = $frameworkHeaders;
                    $includeRoots[] = $frameworkHeaders;

                    $versionedModuleDirs = glob($frameworkHeaders . '/*/' . $module);
                    if ($versionedModuleDirs !== false) {
                        foreach ($versionedModuleDirs as $dir) {
                            if (is_dir($dir)) {
                                $includeRoots[] = $dir;
                            }
                        }
                    }
                }
            }

            if ($headerRoot === null) {
                throw new RuntimeException(sprintf('Unable to locate headers for module %s under %s.', $module, $rootPath));
            }

            $moduleHeaderRoots[$module] = $headerRoot;
        }

        $libraryRoots = [];
        if ($libsPath !== null && is_dir($libsPath)) {
            $libraryRoots[] = $libsPath;
        }

        $moduleLinkFlags = $this->resolveModuleLinkFlags($modules, $rootPath, $tools);

        return new QtInstallation(
            rootPath: $rootPath,
            osFamily: $this->systemInformation->getOsFamily(),
            includeRoots: array_values(array_unique($includeRoots)),
            libraryRoots: array_values(array_unique($libraryRoots)),
            moduleHeaderRoots: $moduleHeaderRoots,
            moduleLinkFlags: $moduleLinkFlags,
            tools: $tools,
        );
    }

    /**
     * @param list<string> $modules
     * @param array<string, string> $tools
     */
    private function resolveModuleLinkFlags(array $modules, string $rootPath, array $tools): ?string
    {
        $pkgConfig = $this->systemInformation->findExecutable('pkg-config');
        if ($pkgConfig === null) {
            return null;
        }

        $packages = array_map(
            static fn(string $module): string => self::pkgConfigPackageForModule($module),
            $modules,
        );

        $prefix = $this->queryTool($pkgConfig, ['--variable=prefix', $packages[0]]);
        if ($prefix !== null) {
            $normalizedPrefix = realpath($prefix) ?: $prefix;
            $normalizedRootPath = realpath($rootPath) ?: $rootPath;

            if ($normalizedPrefix !== $normalizedRootPath && !isset($tools['pkg-config'])) {
                return null;
            }
        }

        $flags = $this->queryTool($pkgConfig, ['--libs', ...$packages]);
        if ($flags === null) {
            return null;
        }

        return $flags;
    }

    private static function pkgConfigPackageForModule(string $module): string
    {
        if (str_starts_with($module, 'Qt6')) {
            return $module;
        }

        if (str_starts_with($module, 'Qt')) {
            return 'Qt6' . substr($module, 2);
        }

        return $module;
    }

    private function frameworkHeaderRoot(string $rootPath, string $module): ?string
    {
        $patterns = [
            $rootPath . '/lib/' . $module . '.framework/Headers',
            $rootPath . '/lib/' . $module . '.framework/Versions/*/Headers',
        ];

        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if ($matches === false) {
                continue;
            }

            foreach ($matches as $match) {
                if (is_dir($match)) {
                    return $match;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $args
     */
    private function queryTool(string $binary, array $args): ?string
    {
        $process = new Process([$binary, ...$args]);
        $process->setTimeout(5.0);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output !== '' ? $output : null;
    }

    /**
     * @param list<string> $candidates
     */
    private function findFirstExecutable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $path = $this->systemInformation->findExecutable($candidate);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }
}
