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

    public function bootstrap(ExtensionBuildContext $context, int $jobs): BootstrapResult
    {
        $this->contexts[] = $context;

        if ($this->failureMessage !== null) {
            throw new \RuntimeException($this->failureMessage);
        }

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

            $steps[] = new BootstrapStep(
                $stepName,
                $command,
                $context->outputDir,
                $stdoutLogPath,
                $stderrLogPath,
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
