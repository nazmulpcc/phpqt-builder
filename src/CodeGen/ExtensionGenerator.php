<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

use eftec\bladeone\BladeOne;
use QtBuilder\Definition\PhpClass;

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

    public function __construct(
        ?string $templatePath = null,
        ?string $compiledPath = null,
    ) {
        $this->typeBridge = new TypeBridge();

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
     * @return list<string> List of files written (absolute paths)
     */
    public function generate(PhpClass $phpClass, string $namespace, string $outputDir, array $classNamespaces = []): array
    {
        $ctx = new ClassContext($phpClass, $namespace, $this->typeBridge, $classNamespaces);
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

        file_put_contents($headerFile, $this->cleanOutput($headerContent));
        $files[] = $headerFile;

        file_put_contents($sourceFile, $this->cleanOutput($sourceContent));
        $files[] = $sourceFile;

        file_put_contents($stubFile, $this->cleanOutput($stubContent));
        $files[] = $stubFile;

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
    public function buildContext(PhpClass $phpClass, string $namespace, array $classNamespaces = []): ClassContext
    {
        return new ClassContext($phpClass, $namespace, $this->typeBridge, $classNamespaces);
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
}
