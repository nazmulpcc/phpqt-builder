<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use eftec\bladeone\BladeOne;
use QtBuilder\IO\FileWriteStats;
use QtBuilder\IO\SmartFileWriter;

class ExtensionScaffolder
{
    private BladeOne $blade;
    private readonly SmartFileWriter $fileWriter;
    private FileWriteStats $lastWriteStats;

    public function __construct(
        ?string $templatePath = null,
        ?string $compiledPath = null,
        ?SmartFileWriter $fileWriter = null,
    )
    {
        $projectRoot = dirname(__DIR__, 2);
        $templatePath ??= $projectRoot . '/templates';
        $compiledPath ??= $projectRoot . '/storage/blade/' . (string) getmypid();
        $this->fileWriter = $fileWriter ?? new SmartFileWriter();
        $this->lastWriteStats = new FileWriteStats();

        if (!is_dir($compiledPath) && !mkdir($compiledPath, 0755, true) && !is_dir($compiledPath)) {
            throw new \RuntimeException(sprintf('Could not create Blade compile directory: %s', $compiledPath));
        }

        $this->blade = new BladeOne($templatePath, $compiledPath, BladeOne::MODE_DEBUG);
    }

    public function prepare(ExtensionBuildContext $context): void
    {
        $this->ensureDirectory($context->outputDir);
        $this->ensureDirectory($context->outputDir . '/classes');
        $this->ensureDirectory($context->metadataDir());
    }

    /**
     * @return list<string>
     */
    public function finalize(ExtensionBuildContext $context): array
    {
        $this->lastWriteStats = new FileWriteStats();
        $this->writeCoreFiles($context);

        return [
            $context->outputDir . '/config.m4',
            $context->outputDir . '/' . $context->phpHeaderFilename(),
            $context->outputDir . '/' . $context->moduleSourceFilename(),
        ];
    }

    private function writeCoreFiles(ExtensionBuildContext $context): void
    {
        $this->lastWriteStats->record($this->fileWriter->write(
            $context->outputDir . '/config.m4',
            $this->cleanOutput($this->blade->run('generation.config_m4', ['ctx' => $context])),
        ));
        $this->lastWriteStats->record($this->fileWriter->write(
            $context->outputDir . '/' . $context->phpHeaderFilename(),
            $this->cleanOutput($this->blade->run('generation.extension_header', ['ctx' => $context])),
        ));
        $this->lastWriteStats->record($this->fileWriter->write(
            $context->outputDir . '/' . $context->moduleSourceFilename(),
            $this->cleanOutput($this->blade->run('generation.extension_source', ['ctx' => $context])),
        ));
    }

    public function lastWriteStats(): FileWriteStats
    {
        return $this->lastWriteStats;
    }

    public function writeComparatorName(): string
    {
        return $this->fileWriter->comparatorName();
    }

    private function cleanOutput(string $content): string
    {
        $content = preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;
        $content = preg_replace('/[ \t]+$/m', '', $content) ?? $content;

        return rtrim($content) . "\n";
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
