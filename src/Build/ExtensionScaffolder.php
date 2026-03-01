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
        $compiledPath ??= $projectRoot . '/storage/blade';

        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0755, true);
        }

        $this->blade = new BladeOne($templatePath, $compiledPath, BladeOne::MODE_DEBUG);
    }

    public function prepare(ExtensionBuildContext $context): void
    {
        @mkdir($context->outputDir, 0755, true);
        @mkdir($context->outputDir . '/classes', 0755, true);
        @mkdir($context->metadataDir(), 0755, true);

        $this->writeCoreFiles($context);
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
}
