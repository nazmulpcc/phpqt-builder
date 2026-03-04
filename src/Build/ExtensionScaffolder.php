<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use eftec\bladeone\BladeOne;

class ExtensionScaffolder
{
    private BladeOne $blade;

    public function __construct(?string $templatePath = null, ?string $compiledPath = null)
    {
        $projectRoot = dirname(__DIR__, 2);
        $templatePath ??= $projectRoot . '/templates';
        $compiledPath ??= $projectRoot . '/storage/blade/' . (string) getmypid();

        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0755, true);
        }

        $this->blade = new BladeOne($templatePath, $compiledPath, BladeOne::MODE_DEBUG);
    }

    public function prepare(ExtensionBuildContext $context): void
    {
        $this->ensureDirectory($context->outputDir);
        $this->ensureDirectory($context->outputDir . '/classes');
        $this->ensureDirectory($context->metadataDir());

        $this->clearTransientClassBuildArtifacts($context->outputDir . '/classes');
    }

    /**
     * @return list<string>
     */
    public function finalize(ExtensionBuildContext $context): array
    {
        $this->writeCoreFiles($context);

        return [
            $context->outputDir . '/config.m4',
            $context->outputDir . '/' . $context->phpHeaderFilename(),
            $context->outputDir . '/' . $context->moduleSourceFilename(),
        ];
    }

    private function writeCoreFiles(ExtensionBuildContext $context): void
    {
        file_put_contents(
            $context->outputDir . '/config.m4',
            $this->cleanOutput($this->blade->run('generation.config_m4', ['ctx' => $context])),
        );
        file_put_contents(
            $context->outputDir . '/' . $context->phpHeaderFilename(),
            $this->cleanOutput($this->blade->run('generation.extension_header', ['ctx' => $context])),
        );
        file_put_contents(
            $context->outputDir . '/' . $context->moduleSourceFilename(),
            $this->cleanOutput($this->blade->run('generation.extension_source', ['ctx' => $context])),
        );
    }

    private function cleanOutput(string $content): string
    {
        $content = preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;
        $content = preg_replace('/[ \t]+$/m', '', $content) ?? $content;

        return rtrim($content) . "\n";
    }

    private function clearTransientClassBuildArtifacts(string $classesDir): void
    {
        foreach (glob($classesDir . '/*.lo') ?: [] as $path) {
            @unlink($path);
        }

        foreach (glob($classesDir . '/*.loT') ?: [] as $path) {
            @unlink($path);
        }

        foreach ([$classesDir . '/.deps', $classesDir . '/.libs'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create directory: %s', $directory));
        }
    }
}
