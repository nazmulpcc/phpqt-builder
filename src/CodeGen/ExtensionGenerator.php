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
    /** @var array<string, true> */
    private array $emittedPhpCompatHeaders = [];
    /** @var array<string, true> */
    private array $emittedSharedHelperSupport = [];

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

        if (!is_dir($compiledPath) && !mkdir($compiledPath, 0755, true) && !is_dir($compiledPath)) {
            throw new \RuntimeException(sprintf('Could not create Blade compile directory: %s', $compiledPath));
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
     * @param array<string, array{name: string, namespace: string, generation_id: string, qualified_name: string, module?: string, is_qobject_derived?: bool}> $classMetadata
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
        ?bool $supportsRuntimeNotifyFunctorConnect = null,
    ): array
    {
        $this->lastWriteStats = new FileWriteStats();
        $ctx = new ClassContext(
            $phpClass,
            $namespace,
            $this->typeBridge,
            $classNamespaces,
            $classNativeTypes,
            $classMetadata,
            $this->resolveRuntimeNotifyFunctorConnectSupport($supportsRuntimeNotifyFunctorConnect),
        );
        $files = [];

        // Ensure output directory exists
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Could not create output directory: %s', $outputDir));
        }

        $files = [...$files, ...$this->writeSharedHelperSupport($outputDir)];

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
        ?bool $supportsRuntimeNotifyFunctorConnect = null,
    ): ClassContext
    {
        return new ClassContext(
            $phpClass,
            $namespace,
            $this->typeBridge,
            $classNamespaces,
            $classNativeTypes,
            $classMetadata,
            $this->resolveRuntimeNotifyFunctorConnectSupport($supportsRuntimeNotifyFunctorConnect),
        );
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
    public function generateThreadRuntimeSupport(string $outputDir): array
    {
        $this->lastWriteStats = new FileWriteStats();

        return $this->writeThreadRuntimeSupport($outputDir);
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
        $files = $this->writePhpCompatHeader($outputDir);

        $supportFiles = [
            ['qt_qmetaobjectconnection.h', 'generation.support.qmetaobjectconnection_header'],
            ['qt_qmetaobjectconnection.cpp', 'generation.support.qmetaobjectconnection_source'],
            ['qt_qmetaobjectconnection.stub.php', 'generation.support.qmetaobjectconnection_stub'],
            ['qt_qphpsignalconnection.h', 'generation.support.qphpsignalconnection_header'],
            ['qt_qphpsignalconnection.cpp', 'generation.support.qphpsignalconnection_source'],
            ['qt_qphpsignalconnection.stub.php', 'generation.support.qphpsignalconnection_stub'],
            ['qt_php_signal_helpers.cpp', 'generation.support.php_signal_helpers_source'],
            ['qt_signalattribute.h', 'generation.support.signalattribute_header'],
            ['qt_signalattribute.cpp', 'generation.support.signalattribute_source'],
            ['qt_signalattribute.stub.php', 'generation.support.signalattribute_stub'],
            ['qt_slotattribute.h', 'generation.support.slotattribute_header'],
            ['qt_slotattribute.cpp', 'generation.support.slotattribute_source'],
            ['qt_slotattribute.stub.php', 'generation.support.slotattribute_stub'],
            ['qt_qmetaobject_bridge.h', 'generation.support.qmetaobject_bridge_header'],
            ['qt_qmetaobject_bridge.cpp', 'generation.support.qmetaobject_bridge_source'],
        ];

        foreach ($supportFiles as [$filename, $view]) {
            $path = $outputDir . '/' . $filename;
            $result = $this->fileWriter->write(
                $path,
                $this->cleanOutput($this->blade->run($view, [])),
            );
            $this->lastWriteStats->record($result);
            $files[] = $path;
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function writeBuildInfoSupport(string $outputDir, ExtensionBuildContext $context): array
    {
        $files = $this->writePhpCompatHeader($outputDir);

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
        $files = $this->writePhpCompatHeader($outputDir);
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

    /**
     * @return list<string>
     */
    private function writeThreadRuntimeSupport(string $outputDir): array
    {
        $files = $this->writePhpCompatHeader($outputDir);

        $supportFiles = [
            ['qt_qthreadruntime.h', 'generation.support.qthreadruntime_header'],
            ['qt_qthreadruntime.cpp', 'generation.support.qthreadruntime_source'],
            ['qt_qthreadruntime.stub.php', 'generation.support.qthreadruntime_stub'],
            ['qt_qfuture.h', 'generation.support.qfuture_header'],
            ['qt_qfuture.cpp', 'generation.support.qfuture_source'],
            ['qt_qfuture.stub.php', 'generation.support.qfuture_stub'],
            ['qt_qpromise.h', 'generation.support.qpromise_header'],
            ['qt_qpromise.cpp', 'generation.support.qpromise_source'],
            ['qt_qpromise.stub.php', 'generation.support.qpromise_stub'],
        ];

        foreach ($supportFiles as [$filename, $view]) {
            $path = $outputDir . '/' . $filename;
            $result = $this->fileWriter->write(
                $path,
                $this->cleanOutput($this->blade->run($view, [])),
            );
            $this->lastWriteStats->record($result);
            $files[] = $path;
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function writePhpCompatHeader(string $outputDir): array
    {
        $outputKey = $this->outputDirKey($outputDir);
        if (isset($this->emittedPhpCompatHeaders[$outputKey])) {
            return [];
        }

        $path = $outputDir . '/qt_php_compat.h';
        $result = $this->fileWriter->write(
            $path,
            $this->cleanOutput($this->blade->run('generation.php_compat_header', [])),
        );
        $this->lastWriteStats->record($result);
        $this->emittedPhpCompatHeaders[$outputKey] = true;

        return [$path];
    }

    /**
     * @return list<string>
     */
    private function writeSharedHelperSupport(string $outputDir): array
    {
        $outputKey = $this->outputDirKey($outputDir);
        if (isset($this->emittedSharedHelperSupport[$outputKey])) {
            return [];
        }

        $files = $this->writePhpCompatHeader($outputDir);
        $supportFiles = [
            ['qt_class_helpers.h', 'generation.support.class_helpers_header'],
            ['qt_qobject_helpers.h', 'generation.support.qobject_helpers_header'],
            ['qt_php_signal_helpers.h', 'generation.support.php_signal_helpers_header'],
            ['qt_signal_helpers.h', 'generation.support.signal_helpers_header'],
            ['qt_ownership_helpers.h', 'generation.support.ownership_helpers_header'],
        ];

        foreach ($supportFiles as [$filename, $view]) {
            $path = $outputDir . '/' . $filename;
            $result = $this->fileWriter->write(
                $path,
                $this->cleanOutput($this->blade->run($view, [])),
            );
            $this->lastWriteStats->record($result);
            $files[] = $path;
        }

        $this->emittedSharedHelperSupport[$outputKey] = true;

        return $files;
    }

    private function outputDirKey(string $outputDir): string
    {
        return rtrim(str_replace('\\', '/', $outputDir), '/');
    }

    private function resolveRuntimeNotifyFunctorConnectSupport(?bool $supportsRuntimeNotifyFunctorConnect): bool
    {
        return $supportsRuntimeNotifyFunctorConnect ?? (PHP_OS_FAMILY !== 'Windows');
    }
}
