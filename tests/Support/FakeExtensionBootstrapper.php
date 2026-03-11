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
            mkdir($context->outputDir . '/build', 0755, true);
        }

        file_put_contents($context->outputDir . '/build/gen_stub.php', "<?php\n");
        file_put_contents($context->outputDir . '/configure', "#!/bin/sh\n");
        file_put_contents($context->outputDir . '/Makefile', "all:\n\t@echo ok\n");

        foreach ($this->discoverStubFiles($context->outputDir) as $stubFile) {
            $arginfoFile = substr($stubFile, 0, -strlen('.stub.php')) . '_arginfo.h';
            file_put_contents($arginfoFile, "/* generated */\n");
        }

        if (!is_dir($context->outputDir . '/modules')) {
            mkdir($context->outputDir . '/modules', 0755, true);
        }
        file_put_contents($context->outputDir . '/modules/' . $context->extensionName . '.so', 'binary');

        $metadataDir = $context->metadataDir();
        if (!is_dir($metadataDir)) {
            mkdir($metadataDir, 0755, true);
        }

        $steps = [];
        foreach (['phpize', 'gen_stub', 'configure', 'make'] as $stepName) {
            $stdoutLogPath = $metadataDir . '/' . $stepName . '.stdout.log';
            $stderrLogPath = $metadataDir . '/' . $stepName . '.stderr.log';
            file_put_contents($stdoutLogPath, $stepName . " ok\n");
            file_put_contents($stderrLogPath, '');

            $command = match ($stepName) {
                'phpize' => ['/usr/bin/phpize'],
                'gen_stub' => [PHP_BINARY, 'build/gen_stub.php', '.'],
                'make' => ['make', '-j' . max(1, $jobs)],
                default => ['./configure', '--enable-' . $context->extensionName, '--with-php-config=/usr/bin/php-config'],
            };

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
