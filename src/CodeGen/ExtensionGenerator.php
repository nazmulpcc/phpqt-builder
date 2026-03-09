<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use eftec\bladeone\BladeOne;
use QtBuilder\Build\EnumHolderDefinition;
use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Definition\PhpClass;
use QtBuilder\IO\FileWriteStats;
use QtBuilder\IO\SmartFileWriter;

/**
 * Orchestrates Blade template rendering to generate C/C++ extension files.
 *
 * Takes a PhpClass IR + namespace and produces:
 *   - {prefix}.h       — header file (struct, macros, externs)
 *   - {prefix}.cpp     — source file (lifecycle, methods, MINIT)
 *   - {prefix}.stub.php — stub for gen_stub.php to produce _arginfo.h
 */
class ExtensionGenerator
{
    private BladeOne $blade;
    private TypeBridge $typeBridge;
    private readonly SmartFileWriter $fileWriter;
    private FileWriteStats $lastWriteStats;

    public function __construct(
        ?string $templatePath = null,
        ?string $compiledPath = null,
        ?SmartFileWriter $fileWriter = null,
    ) {
        $this->typeBridge = new TypeBridge();
        $this->fileWriter = $fileWriter ?? new SmartFileWriter();
        $this->lastWriteStats = new FileWriteStats();

        $projectRoot = dirname(__DIR__, 2);
        $templatePath ??= $projectRoot . '/templates';
        $compiledPath ??= $projectRoot . '/storage/blade/' . (string) getmypid();

        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0755, true);
        }

        $this->blade = new BladeOne(
            $templatePath,
            $compiledPath,
            BladeOne::MODE_DEBUG,
        );
    }

    /**
     * Generate all files for a single class and write them to the output directory.
     *
     * @param PhpClass $phpClass  The IR class definition
     * @param string   $namespace PHP namespace (e.g. "Qt\Core")
     * @param string   $outputDir Directory to write generated files
     * @param array<string, string> $classNamespaces Class-to-namespace map used for stub generation
     * @param array<string, string> $classNativeTypes Class-to-native-C++-type map used for namespaced type resolution
     * @param array<string, array{name: string, namespace: string, generation_id: string, qualified_name: string}> $classMetadata
     * @return list<string> List of files written (absolute paths)
     */
    public function generate(
        PhpClass $phpClass,
        string $namespace,
        string $outputDir,
        array $classNamespaces = [],
        array $classNativeTypes = [],
        array $classMetadata = [],
        bool $emitSignalConnectionSupport = true,
    ): array
    {
        $this->lastWriteStats = new FileWriteStats();
        $ctx = new ClassContext($phpClass, $namespace, $this->typeBridge, $classNamespaces, $classNativeTypes, $classMetadata);
        $files = [];

        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Render each template
        $headerContent = $this->render('generation.class_header', $ctx);
        $sourceContent = $this->render('generation.class_source', $ctx);
        $stubContent = $this->render('generation.class_stub', $ctx);

        // Write files
        $headerFile = $outputDir . '/' . $ctx->filePrefix . '.h';
        $sourceFile = $outputDir . '/' . $ctx->filePrefix . '.cpp';
        $stubFile = $outputDir . '/' . $ctx->filePrefix . '.stub.php';

        $headerResult = $this->fileWriter->write($headerFile, $this->cleanOutput($headerContent));
        $this->lastWriteStats->record($headerResult);
        $files[] = $headerFile;

        $sourceResult = $this->fileWriter->write($sourceFile, $this->cleanOutput($sourceContent));
        $this->lastWriteStats->record($sourceResult);
        $files[] = $sourceFile;

        $stubResult = $this->fileWriter->write($stubFile, $this->cleanOutput($stubContent));
        $this->lastWriteStats->record($stubResult);
        $files[] = $stubFile;

        if ($emitSignalConnectionSupport && $ctx->hasSignals()) {
            $files = [...$files, ...$this->writeSignalConnectionSupport($outputDir)];
        }

        return $files;
    }

    /**
     * Render a single template with the ClassContext.
     */
    public function render(string $view, ClassContext $ctx): string
    {
        return $this->blade->run($view, ['ctx' => $ctx]);
    }

    /**
     * Get the ClassContext for a given PhpClass (useful for inspection/debugging).
     */
    public function buildContext(
        PhpClass $phpClass,
        string $namespace,
        array $classNamespaces = [],
        array $classNativeTypes = [],
        array $classMetadata = [],
    ): ClassContext
    {
        return new ClassContext($phpClass, $namespace, $this->typeBridge, $classNamespaces, $classNativeTypes, $classMetadata);
    }

    public function lastWriteStats(): FileWriteStats
    {
        return $this->lastWriteStats;
    }

    public function writeComparatorName(): string
    {
        return $this->fileWriter->comparatorName();
    }

    /**
     * @return list<string>
     */
    public function generateSignalConnectionSupport(string $outputDir): array
    {
        $this->lastWriteStats = new FileWriteStats();

        return $this->writeSignalConnectionSupport($outputDir);
    }

    /**
     * @return list<string>
     */
    public function generateBuildInfoSupport(string $outputDir, ExtensionBuildContext $context): array
    {
        $this->lastWriteStats = new FileWriteStats();

        return $this->writeBuildInfoSupport($outputDir, $context);
    }

    /**
     * @return list<string>
     */
    public function generateEnumHolderSupport(string $outputDir, EnumHolderDefinition $definition): array
    {
        $this->lastWriteStats = new FileWriteStats();

        return $this->writeEnumHolderSupport($outputDir, $definition);
    }

    /**
     * Clean up Blade output: remove excessive blank lines, trim trailing whitespace.
     */
    private function cleanOutput(string $content): string
    {
        // Collapse 3+ consecutive newlines into 2
        $content = preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;

        // Remove trailing whitespace from lines
        $content = preg_replace('/[ \t]+$/m', '', $content) ?? $content;

        // Ensure file ends with a single newline
        $content = rtrim($content) . "\n";

        return $content;
    }

    /**
     * @return list<string>
     */
    private function writeSignalConnectionSupport(string $outputDir): array
    {
        $files = [];

        $headerFile = $outputDir . '/qt_qmetaobjectconnection.h';
        $sourceFile = $outputDir . '/qt_qmetaobjectconnection.cpp';
        $stubFile = $outputDir . '/qt_qmetaobjectconnection.stub.php';

        $headerResult = $this->fileWriter->write(
            $headerFile,
            $this->cleanOutput($this->blade->run('generation.support.qmetaobjectconnection_header', [])),
        );
        $this->lastWriteStats->record($headerResult);
        $files[] = $headerFile;

        $sourceResult = $this->fileWriter->write(
            $sourceFile,
            $this->cleanOutput($this->blade->run('generation.support.qmetaobjectconnection_source', [])),
        );
        $this->lastWriteStats->record($sourceResult);
        $files[] = $sourceFile;

        $stubResult = $this->fileWriter->write(
            $stubFile,
            $this->cleanOutput($this->blade->run('generation.support.qmetaobjectconnection_stub', [])),
        );
        $this->lastWriteStats->record($stubResult);
        $files[] = $stubFile;

        return $files;
    }

    /**
     * @return list<string>
     */
    private function writeBuildInfoSupport(string $outputDir, ExtensionBuildContext $context): array
    {
        $files = [];

        $headerFile = $outputDir . '/qt_buildinfo.h';
        $sourceFile = $outputDir . '/qt_buildinfo.cpp';
        $stubFile = $outputDir . '/qt_buildinfo.stub.php';

        $headerResult = $this->fileWriter->write(
            $headerFile,
            $this->cleanOutput($this->blade->run('generation.support.buildinfo_header', ['ctx' => $context])),
        );
        $this->lastWriteStats->record($headerResult);
        $files[] = $headerFile;

        $sourceResult = $this->fileWriter->write(
            $sourceFile,
            $this->cleanOutput($this->blade->run('generation.support.buildinfo_source', ['ctx' => $context])),
        );
        $this->lastWriteStats->record($sourceResult);
        $files[] = $sourceFile;

        $stubResult = $this->fileWriter->write(
            $stubFile,
            $this->cleanOutput($this->blade->run('generation.support.buildinfo_stub', ['ctx' => $context])),
        );
        $this->lastWriteStats->record($stubResult);
        $files[] = $stubFile;

        return $files;
    }

    /**
     * @return list<string>
     */
    private function writeEnumHolderSupport(string $outputDir, EnumHolderDefinition $definition): array
    {
        $files = [];
        $ctx = EnumHolderContext::fromDefinition($definition);

        $headerFile = $outputDir . '/' . $ctx->filePrefix . '.h';
        $sourceFile = $outputDir . '/' . $ctx->filePrefix . '.cpp';
        $stubFile = $outputDir . '/' . $ctx->filePrefix . '.stub.php';

        $headerResult = $this->fileWriter->write(
            $headerFile,
            $this->cleanOutput($this->blade->run('generation.support.enumholder_header', ['ctx' => $ctx])),
        );
        $this->lastWriteStats->record($headerResult);
        $files[] = $headerFile;

        $sourceResult = $this->fileWriter->write(
            $sourceFile,
            $this->cleanOutput($this->blade->run('generation.support.enumholder_source', ['ctx' => $ctx])),
        );
        $this->lastWriteStats->record($sourceResult);
        $files[] = $sourceFile;

        $stubResult = $this->fileWriter->write(
            $stubFile,
            $this->cleanOutput($this->blade->run('generation.support.enumholder_stub', ['ctx' => $ctx])),
        );
        $this->lastWriteStats->record($stubResult);
        $files[] = $stubFile;

        return $files;
    }
}
