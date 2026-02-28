<?php

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
            $result = $this->runCommand([$qtpaths, '--qt-version']);
            $attempts[] = ['tool' => basename($qtpaths), 'path' => $qtpaths, 'exit_code' => $result->getExitCode()];
            if ($result->isSuccessful()) {
                return new QtDetectionResult(
                    true,
                    sprintf('Qt discovered via %s.', basename($qtpaths)),
                    ['tool' => basename($qtpaths), 'path' => $qtpaths],
                );
            }
        }

        $qmake = $this->findFirstExecutable(['qmake6', 'qmake']);
        if ($qmake !== null) {
            $result = $this->runCommand([$qmake, '-query', 'QT_VERSION']);
            $attempts[] = ['tool' => basename($qmake), 'path' => $qmake, 'exit_code' => $result->getExitCode()];
            if ($result->isSuccessful()) {
                return new QtDetectionResult(
                    true,
                    sprintf('Qt discovered via %s.', basename($qmake)),
                    ['tool' => basename($qmake), 'path' => $qmake],
                );
            }
        }

        $pkgConfig = $this->findExecutable('pkg-config');
        if ($pkgConfig !== null) {
            $result = $this->runCommand([$pkgConfig, '--exists', 'Qt6Core']);
            $attempts[] = ['tool' => 'pkg-config', 'path' => $pkgConfig, 'exit_code' => $result->getExitCode()];
            if ($result->isSuccessful()) {
                return new QtDetectionResult(
                    true,
                    'Qt discovered via pkg-config (Qt6Core).',
                    ['tool' => 'pkg-config', 'path' => $pkgConfig, 'package' => 'Qt6Core'],
                );
            }
        }

        return new QtDetectionResult(
            false,
            'No working Qt discovery path found (qtpaths/qmake/pkg-config Qt6Core).',
            ['attempts' => $attempts],
        );
    }

    /**
     * @param list<string> $command
     */
    private function runCommand(array $command, float $timeoutSeconds = 5.0): CommandResult
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
}
