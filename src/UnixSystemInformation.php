<?php

declare(strict_types=1);

namespace QtBuilder;

use QtBuilder\Contracts\SystemInformation;
use QtBuilder\System\CommandResult;
use QtBuilder\System\QtDetectionResult;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class UnixSystemInformation implements SystemInformation
{
    private ExecutableFinder $executableFinder;

    public function __construct(?ExecutableFinder $executableFinder = null)
    {
        $this->executableFinder = $executableFinder ?? new ExecutableFinder();
    }

    public function getOsFamily(): string
    {
        return PHP_OS_FAMILY;
    }

    public function getOsName(): string
    {
        return php_uname('s');
    }

    public function getKernelVersion(): string
    {
        return php_uname('r');
    }

    public function getArchitecture(): string
    {
        return php_uname('m');
    }

    public function getPhpVersion(): string
    {
        return PHP_VERSION;
    }

    public function hasExtension(string $extension): bool
    {
        return extension_loaded($extension);
    }

    public function findExecutable(string $name): ?string
    {
        return $this->executableFinder->find($name);
    }

    public function detectQt(): QtDetectionResult
    {
        $attempts = [];

        $qtpaths = $this->findFirstExecutable(['qtpaths6', 'qtpaths']);
        if ($qtpaths !== null) {
            $version = $this->runCommand([$qtpaths, '--qt-version']);
            $headers = $this->runCommand([$qtpaths, '--query', 'QT_INSTALL_HEADERS']);
            $libs = $this->runCommand([$qtpaths, '--query', 'QT_INSTALL_LIBS']);
            $hostPrefix = $this->runCommand([$qtpaths, '--query', 'QT_HOST_PREFIX']);

            $attempt = [
                'tool' => basename($qtpaths),
                'path' => $qtpaths,
                'version_command' => '--qt-version',
                'exit_code' => $version->getExitCode(),
            ];

            if ($version->isSuccessful()) {
                $attempt['version'] = $this->trimOutput($version->getStdout());
            }

            $attempts[] = $attempt;

            if ($version->isSuccessful() && $headers->isSuccessful()) {
                $versionText = $this->trimOutput($version->getStdout());
                $headersPath = $this->trimOutput($headers->getStdout());
                $libsPath = $this->trimOutput($libs->getStdout());
                $hostPrefixPath = $this->trimOutput($hostPrefix->getStdout());

                return new QtDetectionResult(
                    true,
                    sprintf(
                        'Qt%s discovered via %s.',
                        $versionText !== null ? ' ' . $versionText : '',
                        basename($qtpaths),
                    ),
                    [
                        'tool' => basename($qtpaths),
                        'path' => $qtpaths,
                        'version' => $versionText,
                        'headers' => $headersPath,
                        'headers_exists' => $headersPath !== null ? is_dir($headersPath) : false,
                        'libs' => $libsPath,
                        'libs_exists' => $libsPath !== null ? is_dir($libsPath) : false,
                        'host_prefix' => $hostPrefixPath,
                    ],
                );
            }
        }

        $qmake = $this->findFirstExecutable(['qmake6', 'qmake']);
        if ($qmake !== null) {
            $version = $this->runCommand([$qmake, '-query', 'QT_VERSION']);
            $headers = $this->runCommand([$qmake, '-query', 'QT_INSTALL_HEADERS']);
            $libs = $this->runCommand([$qmake, '-query', 'QT_INSTALL_LIBS']);
            $hostPrefix = $this->runCommand([$qmake, '-query', 'QT_HOST_PREFIX']);

            $attempt = [
                'tool' => basename($qmake),
                'path' => $qmake,
                'version_command' => '-query QT_VERSION',
                'exit_code' => $version->getExitCode(),
            ];

            if ($version->isSuccessful()) {
                $attempt['version'] = $this->trimOutput($version->getStdout());
            }

            $attempts[] = $attempt;

            if ($version->isSuccessful() && $headers->isSuccessful()) {
                $versionText = $this->trimOutput($version->getStdout());
                $headersPath = $this->trimOutput($headers->getStdout());
                $libsPath = $this->trimOutput($libs->getStdout());
                $hostPrefixPath = $this->trimOutput($hostPrefix->getStdout());

                return new QtDetectionResult(
                    true,
                    sprintf(
                        'Qt%s discovered via %s.',
                        $versionText !== null ? ' ' . $versionText : '',
                        basename($qmake),
                    ),
                    [
                        'tool' => basename($qmake),
                        'path' => $qmake,
                        'version' => $versionText,
                        'headers' => $headersPath,
                        'headers_exists' => $headersPath !== null ? is_dir($headersPath) : false,
                        'libs' => $libsPath,
                        'libs_exists' => $libsPath !== null ? is_dir($libsPath) : false,
                        'host_prefix' => $hostPrefixPath,
                    ],
                );
            }
        }

        $pkgConfig = $this->findExecutable('pkg-config');
        if ($pkgConfig !== null) {
            $exists = $this->runCommand([$pkgConfig, '--exists', 'Qt6Core']);
            $version = $this->runCommand([$pkgConfig, '--modversion', 'Qt6Core']);
            $cflags = $this->runCommand([$pkgConfig, '--cflags', 'Qt6Core']);
            $libs = $this->runCommand([$pkgConfig, '--libs', 'Qt6Core']);
            $prefix = $this->runCommand([$pkgConfig, '--variable=prefix', 'Qt6Core']);

            $attempt = [
                'tool' => 'pkg-config',
                'path' => $pkgConfig,
                'package' => 'Qt6Core',
                'exit_code' => $exists->getExitCode(),
            ];

            if ($version->isSuccessful()) {
                $attempt['version'] = $this->trimOutput($version->getStdout());
            }

            $attempts[] = $attempt;

            if ($exists->isSuccessful()) {
                $versionText = $this->trimOutput($version->getStdout());

                return new QtDetectionResult(
                    true,
                    sprintf(
                        'Qt%s discovered via pkg-config (Qt6Core).',
                        $versionText !== null ? ' ' . $versionText : '',
                    ),
                    [
                        'tool' => 'pkg-config',
                        'path' => $pkgConfig,
                        'package' => 'Qt6Core',
                        'version' => $versionText,
                        'cflags' => $this->trimOutput($cflags->getStdout()),
                        'libs' => $this->trimOutput($libs->getStdout()),
                        'prefix' => $this->trimOutput($prefix->getStdout()),
                    ],
                );
            }
        }

        return new QtDetectionResult(
            false,
            'No working Qt discovery path found. Install Qt 6 development tools or pass --qt-path to build commands.',
            [
                'hint' => 'Expected one of: qtpaths/qtpaths6, qmake/qmake6, or pkg-config with Qt6Core.',
                'attempts' => $attempts,
            ],
        );
    }

    /**
     * @param list<string> $command
     */
    public function runCommand(array $command, float $timeoutSeconds = 5.0): CommandResult
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            return new CommandResult(124, $process->getOutput(), $exception->getMessage());
        } catch (\Throwable $exception) {
            return new CommandResult(1, $process->getOutput(), $exception->getMessage());
        }

        return new CommandResult(
            $process->getExitCode() ?? 1,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }

    /**
     * @param list<string> $candidates
     */
    private function findFirstExecutable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $path = $this->findExecutable($candidate);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    private function trimOutput(string $output): ?string
    {
        $trimmed = trim($output);

        return $trimmed === '' ? null : $trimmed;
    }
}
