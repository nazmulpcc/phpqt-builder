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
use QtBuilder\Support\CppClassTypeResolver;
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
            ->addOption('allowed-classes-file', null, InputOption::VALUE_REQUIRED, 'Path to a JSON file containing the allow-list of generated classes')
            ->addOption('known-classes-file', null, InputOption::VALUE_REQUIRED, 'Path to a JSON file containing known class names for enum worker mode')
            ->addOption('class-namespaces-file', null, InputOption::VALUE_REQUIRED, 'Path to a JSON file containing class-to-namespace mappings')
            ->addOption('class-headers-file', null, InputOption::VALUE_REQUIRED, 'Path to a JSON file containing class-to-header mappings')
            ->addOption('class-batch-file', null, InputOption::VALUE_REQUIRED, 'Path to a JSON file containing batch class/task-key entries for facts worker mode')
            ->addOption('task-key', null, InputOption::VALUE_REQUIRED, 'Internal worker correlation key')
            ->addOption('worker-mode', null, InputOption::VALUE_REQUIRED, 'Internal worker mode for build pipelines', 'generate')
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
            return $this->executeBuildMode($headerPath, $className, $namespace, $module, $outputDir, $includePaths, $input, $output);
        }

        $output->writeln(sprintf('<info>Parsing %s for class %s...</info>', basename($headerPath), $className));

        $inspector = new QtClassInspector(new ClangArgumentBuilder($includePaths));
        $classData = $inspector->inspect($headerPath, $className);

        if ($classData === null) {
            $output->writeln(sprintf('<error>Class "%s" not found in %s</error>', $className, $headerPath));

            return self::FAILURE;
        }

        $builder = new ClassDefinitionBuilder();
        $phpClass = $builder->build(
            $classData,
            CppClassTypeResolver::forSingleClass(
                $className,
                is_string($classData['qualified_name'] ?? null) ? (string) $classData['qualified_name'] : null,
            ),
        );

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
        string $module,
        string $outputDir,
        array $includePaths,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $allowedClasses = $this->resolveAllowedClasses($input);
        } catch (\RuntimeException $e) {
            return $this->renderFailure(
                $output,
                true,
                $className,
                $headerPath,
                'allowed_classes_load_failed',
                $e->getMessage(),
            );
        }

        $workerMode = (string) $input->getOption('worker-mode');
        if (!in_array($workerMode, ['generate', 'probe', 'facts', 'facts-batch', 'enum-facts'], true)) {
            return $this->renderFailure(
                $output,
                true,
                $className,
                $headerPath,
                'invalid_worker_mode',
                sprintf('Unsupported worker mode "%s".', $workerMode),
            );
        }

        try {
            $classNamespaces = $this->resolveClassNamespaces($input);
        } catch (\RuntimeException $e) {
            return $this->renderFailure(
                $output,
                true,
                $className,
                $headerPath,
                'class_namespaces_load_failed',
                $e->getMessage(),
            );
        }

        try {
            $classHeaders = $this->resolveClassHeaders($input);
        } catch (\RuntimeException $e) {
            return $this->renderFailure(
                $output,
                true,
                $className,
                $headerPath,
                'class_headers_load_failed',
                $e->getMessage(),
            );
        }

        $service = new ClassGenerationService();
        $taskKey = $input->getOption('task-key');
        $taskKey = is_string($taskKey) && $taskKey !== '' ? $taskKey : null;
        if ($workerMode === 'facts') {
            $payload = $service->prepareDiscoveryFacts($headerPath, $className, $includePaths);
            if ($taskKey !== null) {
                $payload['task_key'] = $taskKey;
            }
            $output->writeln($this->encodeJson($payload));

            return self::SUCCESS;
        }

        if ($workerMode === 'facts-batch') {
            try {
                $batchEntries = $this->resolveClassBatchEntries($input, $className, $taskKey);
            } catch (\RuntimeException $e) {
                return $this->renderFailure(
                    $output,
                    true,
                    $className,
                    $headerPath,
                    'class_batch_load_failed',
                    $e->getMessage(),
                );
            }

            $batchClassNames = array_values(array_map(
                static fn(array $entry): string => $entry['class'],
                $batchEntries,
            ));
            $batchFacts = $service->prepareDiscoveryFactsBatch($headerPath, $batchClassNames, $includePaths);
            $batchResults = [];
            foreach ($batchFacts as $index => $payload) {
                if (!is_array($payload)) {
                    continue;
                }
                $entry = $batchEntries[$index] ?? null;
                if (is_array($entry) && is_string($entry['task_key'] ?? null) && $entry['task_key'] !== '') {
                    $payload['task_key'] = $entry['task_key'];
                }
                $batchResults[] = $payload;
            }

            $output->writeln($this->encodeJson([
                'status' => 'ok',
                'header' => $headerPath,
                'results' => $batchResults,
            ]));

            return self::SUCCESS;
        }

        if ($workerMode === 'enum-facts') {
            try {
                $knownClasses = $this->resolveKnownClasses($input);
            } catch (\RuntimeException $e) {
                return $this->renderFailure(
                    $output,
                    true,
                    $className,
                    $headerPath,
                    'known_classes_load_failed',
                    $e->getMessage(),
                );
            }

            $extractor = new \QtBuilder\Build\EnumHolderExtractor();
            $holders = $extractor->extractNamespaceOwnedHoldersForHeader(
                $includePaths,
                $headerPath,
                $module,
                array_fill_keys($knownClasses, true),
            );

            $output->writeln($this->encodeJson([
                'status' => 'ok',
                'header' => $headerPath,
                'holders' => array_map(
                    static fn(\QtBuilder\Build\EnumHolderDefinition $holder): array => $holder->toArray(),
                    $holders,
                ),
            ]));

            return self::SUCCESS;
        }

        $result = $service->generate($headerPath, $className, $includePaths, $allowedClasses, $classHeaders);

        if ($workerMode === 'generate' && $result->status === 'ok' && $result->phpClass !== null) {
            $generator = new ExtensionGenerator();
            $qualifiedName = is_string($result->phpClass->nativeCppType) && $result->phpClass->nativeCppType !== ''
                ? $result->phpClass->nativeCppType
                : $result->phpClass->name;
            $classMetadata = [
                $result->phpClass->name => [
                    'name' => $result->phpClass->name,
                    'namespace' => $namespace,
                    'generation_id' => $result->phpClass->resolvedGenerationId(),
                    'qualified_name' => $qualifiedName,
                ],
            ];
            $files = $generator->generate($result->phpClass, $namespace, $outputDir, $classNamespaces, [], $classMetadata);
            $result = $result->withGeneratedFiles($files);
        }

        $output->writeln($this->encodeJson($this->buildWorkerPayload($result, $workerMode)));

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

        if ($buildMode && $includePaths !== []) {
            return array_values(array_unique($includePaths));
        }

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
     * @return list<string>
     */
    private function resolveAllowedClasses(InputInterface $input): array
    {
        $allowedClasses = $this->parseCsvOption($input->getOption('allowed-classes'));
        $allowedClassesFile = $input->getOption('allowed-classes-file');

        if (!is_string($allowedClassesFile) || trim($allowedClassesFile) === '') {
            return $allowedClasses;
        }

        if (!is_file($allowedClassesFile)) {
            throw new \RuntimeException(sprintf('Allowed classes file not found: %s', $allowedClassesFile));
        }

        $decoded = json_decode((string) file_get_contents($allowedClassesFile), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Allowed classes file is not valid JSON: %s', $allowedClassesFile));
        }

        $fromFile = array_values(array_filter(
            array_map(static fn(mixed $value): string => is_string($value) ? trim($value) : '', $decoded),
            static fn(string $value): bool => $value !== '',
        ));

        return array_values(array_unique([...$fromFile, ...$allowedClasses]));
    }

    /**
     * @return array<string, string>
     */
    private function resolveClassNamespaces(InputInterface $input): array
    {
        $classNamespacesFile = $input->getOption('class-namespaces-file');
        if (!is_string($classNamespacesFile) || trim($classNamespacesFile) === '') {
            return [];
        }

        if (!is_file($classNamespacesFile)) {
            throw new \RuntimeException(sprintf('Class namespaces file not found: %s', $classNamespacesFile));
        }

        $decoded = json_decode((string) file_get_contents($classNamespacesFile), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Class namespaces file is not valid JSON: %s', $classNamespacesFile));
        }

        $classNamespaces = [];
        foreach ($decoded as $className => $namespace) {
            if (!is_string($className) || !is_string($namespace)) {
                continue;
            }

            $className = trim($className);
            $namespace = trim($namespace);
            if ($className === '' || $namespace === '') {
                continue;
            }

            $classNamespaces[$className] = $namespace;
        }

        return $classNamespaces;
    }

    /**
     * @return array<string, string>
     */
    private function resolveClassHeaders(InputInterface $input): array
    {
        $classHeadersFile = $input->getOption('class-headers-file');
        if (!is_string($classHeadersFile) || trim($classHeadersFile) === '') {
            return [];
        }

        if (!is_file($classHeadersFile)) {
            throw new \RuntimeException(sprintf('Class headers file not found: %s', $classHeadersFile));
        }

        $decoded = json_decode((string) file_get_contents($classHeadersFile), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Class headers file is not valid JSON: %s', $classHeadersFile));
        }

        $classHeaders = [];
        foreach ($decoded as $className => $headerPath) {
            if (!is_string($className) || !is_string($headerPath)) {
                continue;
            }

            $className = trim($className);
            $headerPath = trim($headerPath);
            if ($className === '' || $headerPath === '') {
                continue;
            }

            $classHeaders[$className] = $headerPath;
        }

        return $classHeaders;
    }

    /**
     * @return list<string>
     */
    private function resolveKnownClasses(InputInterface $input): array
    {
        $knownClassesFile = $input->getOption('known-classes-file');
        if (!is_string($knownClassesFile) || trim($knownClassesFile) === '') {
            return [];
        }

        if (!is_file($knownClassesFile)) {
            throw new \RuntimeException(sprintf('Known classes file not found: %s', $knownClassesFile));
        }

        $decoded = json_decode((string) file_get_contents($knownClassesFile), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Known classes file is not valid JSON: %s', $knownClassesFile));
        }

        return array_values(array_filter(
            array_map(static fn(mixed $value): string => is_string($value) ? trim($value) : '', $decoded),
            static fn(string $value): bool => $value !== '',
        ));
    }

    /**
     * @return list<array{class: string, task_key: string|null}>
     */
    private function resolveClassBatchEntries(InputInterface $input, string $defaultClassName, ?string $defaultTaskKey): array
    {
        $classBatchFile = $input->getOption('class-batch-file');
        if (!is_string($classBatchFile) || trim($classBatchFile) === '') {
            return [[
                'class' => $defaultClassName,
                'task_key' => $defaultTaskKey,
            ]];
        }

        if (!is_file($classBatchFile)) {
            throw new \RuntimeException(sprintf('Class batch file not found: %s', $classBatchFile));
        }

        $decoded = json_decode((string) file_get_contents($classBatchFile), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Class batch file is not valid JSON: %s', $classBatchFile));
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $className = is_string($entry['class'] ?? null) ? trim((string) $entry['class']) : '';
            if ($className === '') {
                continue;
            }

            $taskKey = is_string($entry['task_key'] ?? null) ? trim((string) $entry['task_key']) : null;
            $entries[] = [
                'class' => $className,
                'task_key' => $taskKey !== '' ? $taskKey : null,
            ];
        }

        if ($entries === []) {
            throw new \RuntimeException(sprintf('Class batch file is empty: %s', $classBatchFile));
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : '{}';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWorkerPayload(\QtBuilder\Build\ClassGenerationResult $result, string $workerMode): array
    {
        if ($workerMode === 'probe') {
            return [
                'status' => $result->status,
                'class' => $result->className,
                'header' => $result->headerPath,
                'reason_code' => $result->reasonCode,
                'reason_message' => $result->reasonMessage,
            ];
        }

        return $result->toArray();
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
