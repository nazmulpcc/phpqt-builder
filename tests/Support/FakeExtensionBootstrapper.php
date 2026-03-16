<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Support;

use QtBuilder\Build\BootstrapResult;
use QtBuilder\Build\BootstrapStep;
use QtBuilder\Build\ExtensionBootstrapper;
use QtBuilder\Build\ExtensionBuildContext;

final class FakeExtensionBootstrapper implements ExtensionBootstrapper
{
    /**
     * @var list<ExtensionBuildContext>
     */
    public array $contexts = [];

    public ?string $failureMessage = null;
    public string $failureStep = 'configure';

    public function bootstrap(ExtensionBuildContext $context, int $jobs, bool $useCcache = false, ?callable $onEvent = null): BootstrapResult
    {
        $this->contexts[] = $context;

        if (!is_dir($context->outputDir . '/build')) {
            @mkdir($context->outputDir . '/build', 0755, true);
        }

        foreach ($this->discoverStubFiles($context->outputDir) as $stubFile) {
            $arginfoFile = substr($stubFile, 0, -strlen('.stub.php')) . '_arginfo.h';
            file_put_contents($arginfoFile, "/* generated */\n");
        }

        $metadataDir = $context->metadataDir();
        if (!is_dir($metadataDir)) {
            @mkdir($metadataDir, 0755, true);
        }

        if ($context->isIosTarget()) {
            $stepNames = ['gen_stub'];
            foreach (($context->iosBuildOptions?->sdks ?? []) as $sdk) {
                $stepNames[] = 'compile:' . $sdk . ':' . $context->moduleSourceFilename();
                $stepNames[] = 'archive:' . $sdk;

                $libraryPath = $context->iosStaticLibraryPath($sdk);
                if (!is_dir(dirname($libraryPath))) {
                    @mkdir(dirname($libraryPath), 0755, true);
                }
                file_put_contents($libraryPath, 'archive');
            }

            file_put_contents($metadataDir . '/ios_build.json', json_encode([
                'extension_name' => $context->extensionName,
                'qt_root_path' => $context->installation->rootPath,
                'artifacts' => array_map(
                    fn(string $sdk): array => [
                        'sdk' => $sdk,
                        'library' => $context->iosStaticLibraryPath($sdk),
                    ],
                    $context->iosBuildOptions?->sdks ?? [],
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            file_put_contents($context->outputDir . '/build/gen_stub.php', "<?php\n");
            file_put_contents($context->outputDir . '/configure', "#!/bin/sh\n");
            file_put_contents($context->outputDir . '/Makefile', "all:\n\t@echo ok\n");

            if (!is_dir($context->outputDir . '/modules')) {
                @mkdir($context->outputDir . '/modules', 0755, true);
            }
            file_put_contents($context->outputDir . '/modules/' . $context->extensionName . '.so', 'binary');
            $stepNames = ['phpize', 'gen_stub', 'configure', 'make'];
        }

        $steps = [];
        foreach ($stepNames as $stepName) {
            $safeStepName = str_replace(':', '_', $stepName);
            $stdoutLogPath = $metadataDir . '/' . $safeStepName . '.stdout.log';
            $stderrLogPath = $metadataDir . '/' . $safeStepName . '.stderr.log';
            file_put_contents($stdoutLogPath, $stepName . " ok\n");
            file_put_contents($stderrLogPath, '');

            $command = $this->commandForStep($context, $stepName, $jobs);

            if ($onEvent !== null) {
                $onEvent([
                    'type' => 'step_started',
                    'step' => $stepName,
                    'command' => $command,
                    'stdout_log' => null,
                    'stderr_log' => null,
                    'message' => null,
                    'duration_seconds' => null,
                ]);
            }

            if ($this->failureMessage !== null && $stepName === $this->failureStep) {
                if ($onEvent !== null) {
                    $onEvent([
                        'type' => 'step_failed',
                        'step' => $stepName,
                        'command' => $command,
                        'stdout_log' => $stdoutLogPath,
                        'stderr_log' => $stderrLogPath,
                        'message' => $this->failureMessage,
                        'duration_seconds' => 0.01,
                    ]);
                }
                throw new \RuntimeException($this->failureMessage);
            }

            if ($onEvent !== null) {
                $onEvent([
                    'type' => 'step_succeeded',
                    'step' => $stepName,
                    'command' => $command,
                    'stdout_log' => $stdoutLogPath,
                    'stderr_log' => $stderrLogPath,
                    'message' => null,
                    'duration_seconds' => 0.01,
                ]);
            }

            $steps[] = new BootstrapStep(
                $stepName,
                $command,
                $context->outputDir,
                $stdoutLogPath,
                $stderrLogPath,
                0.01,
            );
        }

        return new BootstrapResult($steps);
    }

    /**
     * @return list<string>
     */
    private function commandForStep(ExtensionBuildContext $context, string $stepName, int $jobs): array
    {
        if ($context->isIosTarget()) {
            if ($stepName === 'gen_stub') {
                return [PHP_BINARY, 'build/gen_stub.php', '.'];
            }

            if (str_starts_with($stepName, 'compile:')) {
                return ['/usr/bin/clang++', '-c', 'source.cpp', '-o', 'source.o'];
            }

            if (str_starts_with($stepName, 'archive:')) {
                return ['/usr/bin/libtool', '-static', '-o', 'lib' . $context->extensionName . '.a', 'source.o'];
            }
        }

        return match ($stepName) {
            'phpize' => ['/usr/bin/phpize'],
            'gen_stub' => [PHP_BINARY, 'build/gen_stub.php', '.'],
            'make' => ['make', '-j' . max(1, $jobs)],
            default => ['./configure', '--enable-' . $context->extensionName, '--with-php-config=/usr/bin/php-config'],
        };
    }

    /**
     * @return list<string>
     */
    private function discoverStubFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            if (str_ends_with($path, '.stub.php')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }
}
