<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Contracts\SystemInformation;
use Symfony\Component\Process\Process;

class ProcessExtensionBootstrapper implements ExtensionBootstrapper
{
    public function __construct(private readonly SystemInformation $systemInformation) {}

    public function bootstrap(ExtensionBuildContext $context, int $jobs, ?callable $onEvent = null): BootstrapResult
    {
        $metadataDir = $context->metadataDir();
        @mkdir($metadataDir, 0755, true);

        $phpize = $this->requireExecutable('phpize');
        $steps = [];
        $steps[] = $this->runStep('phpize', [$phpize], $context->outputDir, $metadataDir, $onEvent);

        $genStubScript = $context->outputDir . '/build/gen_stub.php';
        if (!is_file($genStubScript)) {
            throw new \RuntimeException(sprintf('phpize did not produce build/gen_stub.php under %s.', $context->outputDir));
        }

        $steps[] = $this->runStep(
            'gen_stub',
            [PHP_BINARY, 'build/gen_stub.php', '.'],
            $context->outputDir,
            $metadataDir,
            $onEvent,
        );

        $configureCommand = [
            './configure',
            '--enable-' . $context->extensionName,
        ];

        $phpConfig = $this->systemInformation->findExecutable('php-config');
        if ($phpConfig !== null && $phpConfig !== '') {
            $configureCommand[] = '--with-php-config=' . $phpConfig;
        }

        $steps[] = $this->runStep('configure', $configureCommand, $context->outputDir, $metadataDir, $onEvent);
        $steps[] = $this->runStep('make', ['make', '-j' . max(1, $jobs)], $context->outputDir, $metadataDir, $onEvent);

        return new BootstrapResult($steps);
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
    private function runStep(string $name, array $command, string $workingDirectory, string $metadataDir, ?callable $onEvent = null): BootstrapStep
    {
        if ($onEvent !== null) {
            $onEvent([
                'type' => 'step_started',
                'step' => $name,
                'command' => $command,
                'stdout_log' => null,
                'stderr_log' => null,
                'message' => null,
            ]);
        }

        $process = new Process($command, $workingDirectory);
        $process->setTimeout(null);
        $process->run();

        $stdoutLogPath = $metadataDir . '/' . $name . '.stdout.log';
        $stderrLogPath = $metadataDir . '/' . $name . '.stderr.log';

        file_put_contents($stdoutLogPath, $process->getOutput());
        file_put_contents($stderrLogPath, $process->getErrorOutput());

        if (!$process->isSuccessful()) {
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

        if ($onEvent !== null) {
            $onEvent([
                'type' => 'step_succeeded',
                'step' => $name,
                'command' => $command,
                'stdout_log' => $stdoutLogPath,
                'stderr_log' => $stderrLogPath,
                'message' => null,
            ]);
        }

        return new BootstrapStep($name, $command, $workingDirectory, $stdoutLogPath, $stderrLogPath);
    }
}
