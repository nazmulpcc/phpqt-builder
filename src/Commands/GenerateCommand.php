<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\ClassGenerationService;
use QtBuilder\CodeGen\ExtensionGenerator;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Parsing\ClassDefinitionBuilder;
use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;
use QtBuilder\Qt\QtInstallationResolver;
use QtBuilder\UnixSystemInformation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('generate', 'Generate C/C++ PHP extension code from a Qt header.')]
class GenerateCommand extends Command
{
    private readonly SystemInformation $systemInformation;

    public function __construct(?SystemInformation $systemInformation = null)
    {
        $this->systemInformation = $systemInformation ?? new UnixSystemInformation();

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('header', InputArgument::REQUIRED, 'Path to the Qt C++ header file')
            ->addArgument('class', InputArgument::REQUIRED, 'Name of the class to wrap')
            ->addOption('namespace', 'N', InputOption::VALUE_REQUIRED, 'PHP namespace (e.g. Qt\\Core)', 'Qt\\Core')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory for generated files', 'ext/')
            ->addOption('output-subdir', null, InputOption::VALUE_REQUIRED, 'Optional subdirectory under --output')
            ->addOption('include', 'I', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional include paths for the compiler')
            ->addOption('qt-path', null, InputOption::VALUE_REQUIRED, 'Path to the Qt installation root')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Qt module name for build mode', 'QtCore')
            ->addOption('extension-name', null, InputOption::VALUE_REQUIRED, 'Extension name for build mode', 'qt')
            ->addOption('allowed-classes', null, InputOption::VALUE_REQUIRED, 'Comma-separated allow-list of generated classes')
            ->addOption('build-mode', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON and apply conservative filtering');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $headerPath = (string) $input->getArgument('header');
        $className = (string) $input->getArgument('class');
        $namespace = (string) $input->getOption('namespace');
        $module = (string) $input->getOption('module');
        $buildMode = (bool) $input->getOption('build-mode');
        $outputDir = $this->resolveOutputDir(
            (string) $input->getOption('output'),
            $input->getOption('output-subdir') !== null ? (string) $input->getOption('output-subdir') : null,
        );

        if (!file_exists($headerPath)) {
            return $this->renderFailure($output, $buildMode, $className, $headerPath, 'header_not_found', sprintf('Header file not found: %s', $headerPath));
        }

        $headerPath = realpath($headerPath) ?: $headerPath;
        $includePaths = $this->resolveIncludePaths($input, $module, $buildMode);

        if ($buildMode) {
            return $this->executeBuildMode($headerPath, $className, $namespace, $outputDir, $includePaths, $input, $output);
        }

        $output->writeln(sprintf('<info>Parsing %s for class %s...</info>', basename($headerPath), $className));

        $inspector = new QtClassInspector(new ClangArgumentBuilder($includePaths));
        $classData = $inspector->inspect($headerPath, $className);

        if ($classData === null) {
            $output->writeln(sprintf('<error>Class "%s" not found in %s</error>', $className, $headerPath));

            return self::FAILURE;
        }

        $builder = new ClassDefinitionBuilder();
        $phpClass = $builder->build($classData);

        $output->writeln(sprintf(
            '  Found %d methods (%d overloaded), %d properties',
            count($phpClass->methods),
            count(array_filter($phpClass->methods, fn($m) => $m->isOverloaded())),
            count($phpClass->properties),
        ));

        $output->writeln(sprintf('<info>Generating extension code to %s...</info>', $outputDir));

        $generator = new ExtensionGenerator();
        $files = $generator->generate($phpClass, $namespace, $outputDir);

        foreach ($files as $file) {
            $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $file));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done. Run gen_stub.php on %s/%s.stub.php to generate _arginfo.h</info>',
            $outputDir,
            $generator->buildContext($phpClass, $namespace)->filePrefix,
        ));

        return self::SUCCESS;
    }

    /**
     * @param list<string> $includePaths
     */
    private function executeBuildMode(
        string $headerPath,
        string $className,
        string $namespace,
        string $outputDir,
        array $includePaths,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $allowedClasses = $this->parseCsvOption($input->getOption('allowed-classes'));
        $service = new ClassGenerationService();
        $result = $service->generate($headerPath, $className, $includePaths, $allowedClasses);

        if ($result->status === 'ok' && $result->phpClass !== null) {
            $generator = new ExtensionGenerator();
            $files = $generator->generate($result->phpClass, $namespace, $outputDir);
            $result = $result->withGeneratedFiles($files);
        }

        $output->writeln($this->encodeJson($result->toArray()));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveIncludePaths(InputInterface $input, string $module, bool $buildMode): array
    {
        /** @var list<string> $includePaths */
        $includePaths = $input->getOption('include');
        $qtPath = $input->getOption('qt-path');

        if ($qtPath === null && !$buildMode) {
            return $includePaths;
        }

        $resolver = new QtInstallationResolver($this->systemInformation);
        $installation = $resolver->resolve($qtPath !== null ? (string) $qtPath : null, [$module]);

        return array_values(array_unique([...$installation->includeRoots, ...$includePaths]));
    }

    private function resolveOutputDir(string $outputDir, ?string $outputSubdir): string
    {
        $outputDir = rtrim($outputDir, '/');

        if ($outputSubdir === null || $outputSubdir === '') {
            return $outputDir;
        }

        return $outputDir . '/' . trim($outputSubdir, '/');
    }

    /**
     * @return list<string>
     */
    private function parseCsvOption(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $value));

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : '{}';
    }

    private function renderFailure(
        OutputInterface $output,
        bool $buildMode,
        string $className,
        string $headerPath,
        string $reasonCode,
        string $reasonMessage,
    ): int {
        if ($buildMode) {
            $output->writeln($this->encodeJson([
                'status' => 'error',
                'class' => $className,
                'header' => $headerPath,
                'generated_files' => [],
                'reason_code' => $reasonCode,
                'reason_message' => $reasonMessage,
                'skipped_methods' => [],
                'summary' => [
                    'generated_methods' => 0,
                    'skipped_methods' => 0,
                ],
            ]));
        } else {
            $output->writeln(sprintf('<error>%s</error>', $reasonMessage));
        }

        return self::FAILURE;
    }
}
