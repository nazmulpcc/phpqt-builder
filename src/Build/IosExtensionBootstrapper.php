<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Contracts\SystemInformation;
use Symfony\Component\Process\Process;

final class IosExtensionBootstrapper implements ExtensionBootstrapper
{
    public function __construct(private readonly SystemInformation $systemInformation) {}

    public function bootstrap(ExtensionBuildContext $context, int $jobs, bool $useCcache = false, ?callable $onEvent = null): BootstrapResult
    {
        if (!$context->isIosTarget()) {
            throw new \RuntimeException('IosExtensionBootstrapper received a non-iOS build context.');
        }

        $metadataDir = $context->metadataDir();
        @mkdir($metadataDir, 0755, true);

        $iosOptions = $context->iosBuildOptions ?? new IosBuildOptions();
        $toolchain = (new IosToolchainResolver($this->systemInformation))->resolve($iosOptions);

        $steps = [];
        $genStubScript = $this->resolveGenStubScript();
        $steps[] = $this->runStep(
            'gen_stub',
            [PHP_BINARY, $genStubScript, '.'],
            $context->outputDir,
            $metadataDir,
            $onEvent,
        );

        $phpIncludes = $this->resolvePhpIncludes();
        $ccache = $useCcache ? $this->requireExecutable('ccache') : null;
        $artifacts = [];

        foreach ($toolchain->sdks as $sdkToolchain) {
            $artifactDir = $context->iosArtifactsDir() . '/' . $sdkToolchain->sdk;
            $objectDir = $artifactDir . '/objects';
            @mkdir($objectDir, 0755, true);

            $objectFiles = [];
            $sources = array_merge(
                [$context->outputDir . '/' . $context->moduleSourceFilename()],
                array_map(
                    fn(string $relative): string => $context->outputDir . '/' . $relative,
                    $context->classSources(),
                ),
            );

            foreach ($sources as $sourcePath) {
                $objectPath = $objectDir . '/' . basename($sourcePath, '.cpp') . '.o';
                $objectFiles[] = $objectPath;
                $steps[] = $this->runStep(
                    'compile:' . $sdkToolchain->sdk . ':' . basename($sourcePath),
                    $this->compileCommand($context, $sdkToolchain, $sourcePath, $objectPath, $phpIncludes, $ccache),
                    $context->outputDir,
                    $metadataDir,
                    $onEvent,
                    $toolchain->developerDir,
                );
            }

            $libraryPath = $context->iosStaticLibraryPath($sdkToolchain->sdk);
            @mkdir(dirname($libraryPath), 0755, true);
            $archiveCommand = [
                $sdkToolchain->libtoolPath,
                '-static',
                '-o',
                $libraryPath,
                ...$objectFiles,
            ];
            $steps[] = $this->runStep(
                'archive:' . $sdkToolchain->sdk,
                $archiveCommand,
                $context->outputDir,
                $metadataDir,
                $onEvent,
                $toolchain->developerDir,
            );

            $artifacts[$sdkToolchain->sdk] = [
                'sdk' => $sdkToolchain->sdk,
                'sdk_path' => $sdkToolchain->sdkPath,
                'library' => $libraryPath,
                'architectures' => $sdkToolchain->architectures,
                'minimum_version' => $sdkToolchain->minimumVersion,
            ];
        }

        (new IosBuildManifest(
            extensionName: $context->extensionName,
            qtRootPath: $context->installation->rootPath,
            developerDir: $toolchain->developerDir,
            artifacts: $artifacts,
        ))->write($metadataDir . '/ios_build.json');

        return new BootstrapResult($steps);
    }

    /**
     * @param list<string> $phpIncludes
     * @return list<string>
     */
    private function compileCommand(
        ExtensionBuildContext $context,
        IosSdkToolchain $sdkToolchain,
        string $sourcePath,
        string $objectPath,
        array $phpIncludes,
        ?string $ccache,
    ): array {
        $command = [];
        if ($ccache !== null && $ccache !== '') {
            $command[] = $ccache;
        }

        $command[] = $sdkToolchain->clangxxPath;
        $command[] = '-std=c++17';
        $command[] = '-c';
        $command[] = '-isysroot';
        $command[] = $sdkToolchain->sdkPath;
        $command[] = '-DHAVE_CONFIG_H';
        $command[] = '-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1';
        $command[] = '-DQT_STATIC_BUILD=1';
        foreach ($sdkToolchain->architectures as $architecture) {
            $command[] = '-arch';
            $command[] = $architecture;
        }
        $command[] = $sdkToolchain->sdk === IosBuildOptions::SDK_IPHONESIMULATOR
            ? '-mios-simulator-version-min=' . $sdkToolchain->minimumVersion
            : '-miphoneos-version-min=' . $sdkToolchain->minimumVersion;

        $command[] = '-I' . $context->outputDir;
        $command[] = '-I' . $context->outputDir . '/classes';
        foreach ($context->compileIncludeRoots() as $includeRoot) {
            if (str_starts_with($includeRoot, '-')) {
                foreach (preg_split('/\s+/', trim($includeRoot)) ?: [] as $flagPart) {
                    if ($flagPart !== '') {
                        $command[] = $flagPart;
                    }
                }
                continue;
            }

            $command[] = '-I' . $includeRoot;
        }

        foreach ($phpIncludes as $includeFlag) {
            $command[] = $includeFlag;
        }

        $command[] = $sourcePath;
        $command[] = '-o';
        $command[] = $objectPath;

        return $command;
    }

    /**
     * @return list<string>
     */
    private function resolvePhpIncludes(): array
    {
        $phpConfig = $this->requireExecutable('php-config');
        $result = $this->systemInformation->runCommand([$phpConfig, '--includes'], 5.0);
        if (!$result->isSuccessful()) {
            throw new \RuntimeException('Could not resolve PHP include flags via php-config --includes.');
        }

        return array_values(array_filter(preg_split('/\s+/', trim($result->getStdout())) ?: [], static fn(string $flag): bool => $flag !== ''));
    }

    private function resolveGenStubScript(): string
    {
        $phpConfig = $this->requireExecutable('php-config');
        $includeDirResult = $this->systemInformation->runCommand([$phpConfig, '--include-dir'], 5.0);
        if (!$includeDirResult->isSuccessful()) {
            throw new \RuntimeException('Could not resolve PHP include directory via php-config --include-dir.');
        }

        $includeDir = trim($includeDirResult->getStdout());
        $prefix = dirname(dirname($includeDir));
        $candidates = [
            $prefix . '/lib/build/gen_stub.php',
            dirname(PHP_BINARY) . '/../lib/build/gen_stub.php',
        ];

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate) ?: $candidate;
            if (is_file($resolved)) {
                return $resolved;
            }
        }

        throw new \RuntimeException('Could not locate PHP build/gen_stub.php for iOS arginfo generation.');
    }

    private function requireExecutable(string $name): string
    {
        $path = $this->systemInformation->findExecutable($name);
        if ($path === null || $path === '') {
            throw new \RuntimeException(sprintf('Required executable not found: %s', $name));
        }

        return $path;
    }

    /**
     * @param list<string> $command
     */
    private function runStep(
        string $name,
        array $command,
        string $workingDirectory,
        string $metadataDir,
        ?callable $onEvent = null,
        ?string $developerDir = null,
    ): BootstrapStep {
        $startedAt = microtime(true);

        if ($onEvent !== null) {
            $onEvent([
                'type' => 'step_started',
                'step' => $name,
                'command' => $command,
                'stdout_log' => null,
                'stderr_log' => null,
                'message' => null,
                'duration_seconds' => null,
            ]);
        }

        $env = null;
        if ($developerDir !== null && $developerDir !== '') {
            $env = ['DEVELOPER_DIR' => $developerDir];
        }

        $process = new Process($command, $workingDirectory, $env);
        $process->setTimeout(null);
        $process->run();

        $stdoutLogPath = $metadataDir . '/' . str_replace(':', '_', $name) . '.stdout.log';
        $stderrLogPath = $metadataDir . '/' . str_replace(':', '_', $name) . '.stderr.log';
        file_put_contents($stdoutLogPath, $process->getOutput());
        file_put_contents($stderrLogPath, $process->getErrorOutput());

        if (!$process->isSuccessful()) {
            $durationSeconds = microtime(true) - $startedAt;
            if ($onEvent !== null) {
                $onEvent([
                    'type' => 'step_failed',
                    'step' => $name,
                    'command' => $command,
                    'stdout_log' => $stdoutLogPath,
                    'stderr_log' => $stderrLogPath,
                    'message' => sprintf(
                        '%s failed with exit code %d. See %s and %s.',
                        $name,
                        $process->getExitCode() ?? 1,
                        $stdoutLogPath,
                        $stderrLogPath,
                    ),
                    'duration_seconds' => $durationSeconds,
                ]);
            }

            throw new \RuntimeException(sprintf(
                '%s failed with exit code %d. See %s and %s.',
                $name,
                $process->getExitCode() ?? 1,
                $stdoutLogPath,
                $stderrLogPath,
            ));
        }

        $durationSeconds = microtime(true) - $startedAt;
        if ($onEvent !== null) {
            $onEvent([
                'type' => 'step_succeeded',
                'step' => $name,
                'command' => $command,
                'stdout_log' => $stdoutLogPath,
                'stderr_log' => $stderrLogPath,
                'message' => null,
                'duration_seconds' => $durationSeconds,
            ]);
        }

        return new BootstrapStep($name, $command, $workingDirectory, $stdoutLogPath, $stderrLogPath, $durationSeconds);
    }
}
