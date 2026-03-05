<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\BootstrapResult;
use QtBuilder\Build\BuildDirectoryCleaner;
use QtBuilder\Build\BuildDiscoveryResult;
use QtBuilder\Build\BuildDiscoveryService;
use QtBuilder\Build\BuildLayout;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Build\ExtensionBootstrapper;
use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Build\ExtensionScaffolder;
use QtBuilder\Build\ProcessExtensionBootstrapper;
use QtBuilder\CodeGen\ExtensionGenerator;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\IO\FileWriteStats;
use QtBuilder\Qt\QtInstallationResolver;
use QtBuilder\Scanning\HeaderCandidate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('build', 'Generate a PHP extension source tree from Qt modules.')]
class BuildCommand extends Command
{
    private readonly ExtensionBootstrapper $bootstrapper;

    public function __construct(
        private readonly SystemInformation $systemInformation,
        ?ExtensionBootstrapper $bootstrapper = null,
        private readonly BuildDiscoveryService $discoveryService = new BuildDiscoveryService(),
        private readonly BuildDirectoryCleaner $buildDirectoryCleaner = new BuildDirectoryCleaner(),
    ) {
        $this->bootstrapper = $bootstrapper ?? new ProcessExtensionBootstrapper($systemInformation);

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('qt-path', null, InputOption::VALUE_REQUIRED, 'Path to the Qt installation root')
            ->addOption('modules', null, InputOption::VALUE_REQUIRED, 'Comma-separated Qt modules to scan', 'QtCore')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Extension name', 'qt')
            ->addOption('ext-version', null, InputOption::VALUE_REQUIRED, 'Extension version', '0.1.0')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Build root directory; extension sources go under <output>/ext', 'build')
            ->addOption('force', 'F', InputOption::VALUE_NONE, 'Clear the selected build root before starting')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel discovery/bootstrap workers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modules = $this->parseModules((string) $input->getOption('modules'));

        $qtResolver = new QtInstallationResolver($this->systemInformation);
        $installation = $qtResolver->resolve($input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null, $modules);

        try {
            $layout = BuildLayout::fromCliOutput((string) $input->getOption('output'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }

        $outputDir = $layout->extensionDir();
        $extensionName = (string) $input->getOption('name');
        $extensionVersion = (string) $input->getOption('ext-version');
        $jobs = $this->resolveJobs($input->getOption('jobs'));
        $force = (bool) $input->getOption('force');

        if ($force) {
            try {
                $this->buildDirectoryCleaner->clear($layout->buildRootDir);
                $output->writeln(sprintf('<comment>Cleared build root:</comment> %s', $layout->buildRootDir));
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        }

        $context = new ExtensionBuildContext($extensionName, $extensionVersion, $layout->buildRootDir, $outputDir, $installation, $modules);
        $scaffolder = new ExtensionScaffolder();
        $scaffolder->prepare($context);
        $metadataDir = $context->metadataDir();

        $cachedDiscovery = $this->discoveryService->loadCache($metadataDir, $modules, $installation->rootPath);
        if ($cachedDiscovery !== null) {
            $acceptedCandidates = $cachedDiscovery->acceptedCandidates;
            $skippedClasses = $cachedDiscovery->skippedClasses;
            $allowedClasses = $cachedDiscovery->allowedClasses;
            $candidateCount = $cachedDiscovery->candidateCount;
            $moduleMethodTotals = $cachedDiscovery->moduleMethodTotals;
            $moduleAcceptedMethodTotals = $cachedDiscovery->moduleAcceptedMethodTotals;
            $this->renderCacheUsage($output, $metadataDir);
        } else {
            $output->writeln('<comment>Discovery cache miss; invoking build:discover.</comment>');

            $discoverExitCode = $this->runDiscoverCommand(
                $input,
                $output,
                qtPath: $input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null,
                modules: $modules,
                outputDir: $layout->buildRootDir,
                jobs: $jobs,
            );
            if ($discoverExitCode !== self::SUCCESS) {
                return $discoverExitCode;
            }

            $cachedDiscovery = $this->discoveryService->loadCache($metadataDir, $modules, $installation->rootPath);
            if ($cachedDiscovery === null) {
                $output->writeln('<error>Discovery completed without producing a usable cache.</error>');
                return self::FAILURE;
            }

            $acceptedCandidates = $cachedDiscovery->acceptedCandidates;
            $skippedClasses = $cachedDiscovery->skippedClasses;
            $allowedClasses = $cachedDiscovery->allowedClasses;
            $candidateCount = $cachedDiscovery->candidateCount;
            $moduleMethodTotals = $cachedDiscovery->moduleMethodTotals;
            $moduleAcceptedMethodTotals = $cachedDiscovery->moduleAcceptedMethodTotals;
        }

        $output->writeln(sprintf('<info>Scanning complete.</info> %d candidates queued, %d filtered before generation.', count($acceptedCandidates), count($skippedClasses)));
        $classStructures = $this->discoveryService->prepareClassStructures(
            $acceptedCandidates,
            $outputDir,
            $installation->includeRoots,
            $metadataDir,
            $jobs,
            $output,
            $extensionName,
        );

        if ($classStructures['errors'] !== []) {
            foreach ($classStructures['errors'] as $error) {
                $message = is_string($error['reason_message'] ?? null) ? $error['reason_message'] : 'Class structure cache failed.';
                $output->writeln(sprintf('<error>%s</error>', $message));
            }

            return self::FAILURE;
        }

        $acceptedCandidates = $classStructures['accepted_candidates'];
        $skippedClasses = [...$skippedClasses, ...$classStructures['skipped_classes']];

        $output->writeln('<info>Evaluating generated class set from cached class structures...</info>');
        $generation = $this->stabilizeGeneratedCandidates(
            $acceptedCandidates,
            $skippedClasses,
            $allowedClasses,
            $classStructures['prepared_class_data'],
            $outputDir,
            $output,
        );

        $acceptedCandidates = $generation['accepted_candidates'];
        $generatedClasses = $generation['generated_classes'];
        $generatedClassParents = $generation['generated_class_parents'];
        $skippedClasses = $generation['skipped_classes'];
        $skippedMethods = $generation['skipped_methods'];
        $errors = $generation['errors'];
        $classmap = $generation['classmap'];
        /** @var FileWriteStats $classWriteStats */
        $classWriteStats = $generation['file_write_stats'];
        $this->renderModuleAcceptance(
            $output,
            $modules,
            $acceptedCandidates,
            $skippedClasses,
            $moduleMethodTotals,
            $generation['module_generated_method_totals'] ?? [],
        );

        $this->discoveryService->writeCache(
            $metadataDir,
            $modules,
            $installation->rootPath,
            new BuildDiscoveryResult(
                acceptedCandidates: $acceptedCandidates,
                skippedClasses: $skippedClasses,
                allowedClasses: $generatedClasses,
                candidateCount: $candidateCount,
                moduleMethodTotals: $moduleMethodTotals,
                moduleAcceptedMethodTotals: $moduleAcceptedMethodTotals,
            ),
        );

        $context = $context->withGeneratedClasses(
            $generatedClasses,
            $generatedClassParents,
            $generation['generated_class_dependencies'],
            (bool) ($generation['requires_signal_connection_support'] ?? false),
        );
        $scaffoldFiles = $scaffolder->finalize($context);
        $coreWriteStats = $scaffolder->lastWriteStats();
        $totalWriteStats = new FileWriteStats();
        $totalWriteStats->merge($classWriteStats);
        $totalWriteStats->merge($coreWriteStats);

        $bootstrapResult = null;
        $bootstrapError = null;
        $bootstrapSkipped = false;

        if ($generatedClasses !== [] && $errors === []) {
            if ($totalWriteStats->written() === 0 && $this->moduleBinaryExists($context)) {
                $bootstrapSkipped = true;
                $output->writeln('<comment>No generated file changes detected; skipping bootstrap.</comment>');
            } else {
                $output->writeln('<info>Bootstrapping extension build tree...</info>');

                try {
                    $bootstrapResult = $this->bootstrapper->bootstrap($context, $jobs, function (array $event) use ($output): void {
                        $this->renderBootstrapEvent($output, $event);
                    });
                } catch (\RuntimeException $e) {
                    $bootstrapError = $e->getMessage();
                    $output->writeln(sprintf('<error>%s</error>', $bootstrapError));
                }
            }
        }

        $summary = [
            'modules' => $modules,
            'candidate_classes' => $candidateCount,
            'generated_classes' => count($generatedClasses),
            'skipped_classes' => count($skippedClasses),
            'failed_classes' => count($errors),
            'jobs' => $jobs,
            'generation_passes' => $generation['passes'],
            'bootstrap' => $bootstrapResult?->toArray(),
            'bootstrap_error' => $bootstrapError,
            'bootstrap_skipped' => $bootstrapSkipped,
            'file_writes' => [
                'comparator' => $scaffolder->writeComparatorName(),
                'class' => $classWriteStats->toArray(),
                'core' => $coreWriteStats->toArray(),
                'total' => $totalWriteStats->toArray(),
            ],
        ];

        file_put_contents($metadataDir . '/classmap.json', json_encode($classmap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/skipped_classes.json', json_encode($skippedClasses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/skipped_methods.json', json_encode($skippedMethods, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/build_summary.json', json_encode($summary + ['errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        foreach ($scaffoldFiles as $file) {
            $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $file));
        }
        $output->writeln(sprintf(
            '<comment>File writes:</comment> %d written (%d created, %d updated), %d unchanged [comparator: %s]',
            $totalWriteStats->written(),
            $totalWriteStats->created(),
            $totalWriteStats->updated(),
            $totalWriteStats->unchanged(),
            $scaffolder->writeComparatorName(),
        ));
        $output->writeln(sprintf('<info>Generated %d class wrapper(s); %d class(es) skipped; %d error(s).</info>', count($generatedClasses), count($skippedClasses), count($errors)));

        if ($generatedClasses === [] || $errors !== [] || $bootstrapError !== null) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function moduleBinaryExists(ExtensionBuildContext $context): bool
    {
        $path = $context->outputDir . '/modules/' . $context->extensionName . '.so';

        return is_file($path);
    }

    /**
     * @return list<string>
     */
    private function parseModules(string $modules): array
    {
        $parts = array_map('trim', explode(',', $modules));
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        return $parts === [] ? ['QtCore'] : $parts;
    }

    private function resolveJobs(mixed $jobsOption): int
    {
        if (is_string($jobsOption) && $jobsOption !== '') {
            return max(1, (int) $jobsOption);
        }

        $osFamily = $this->systemInformation->getOsFamily();
        $command = $osFamily === 'Darwin' ? 'sysctl -n hw.logicalcpu' : 'nproc';
        $detected = trim((string) shell_exec($command . ' 2>/dev/null'));

        return max(1, (int) $detected ?: 1);
    }

    private function namespaceForModule(string $module): string
    {
        return 'Qt\\' . preg_replace('/^Qt/', '', $module);
    }

    private function renderCacheUsage(OutputInterface $output, string $metadataDir): void
    {
        $output->writeln('<comment>Using cached build metadata:</comment>');

        foreach ([
            'discovery_cache.json',
            'accepted_candidates.json',
            'allowed_classes.json',
        ] as $filename) {
            $path = $metadataDir . '/' . $filename;
            if (is_file($path)) {
                $output->writeln(sprintf('  <comment>cache:</comment> %s', $path));
            }
        }
    }

    private function renderBootstrapResult(OutputInterface $output, BootstrapResult $bootstrapResult): void
    {
        foreach ($bootstrapResult->steps as $step) {
            $output->writeln(sprintf(
                '  <comment>%s:</comment> %s',
                $step->name,
                implode(' ', $step->command),
            ));
            $output->writeln(sprintf('    <comment>stdout:</comment> %s', $step->stdoutLogPath));
            $output->writeln(sprintf('    <comment>stderr:</comment> %s', $step->stderrLogPath));
        }
    }

    /**
     * @param array{
     *   type: string,
     *   step: string,
     *   command: list<string>,
     *   stdout_log: string|null,
     *   stderr_log: string|null,
     *   message: string|null
     * } $event
     */
    private function renderBootstrapEvent(OutputInterface $output, array $event): void
    {
        $step = $event['step'];
        $type = $event['type'];

        if ($type === 'step_started') {
            $output->writeln(sprintf('  <comment>%s:</comment> started', $step));
            return;
        }

        if ($type === 'step_succeeded') {
            $output->writeln(sprintf('  <info>%s:</info> succeeded', $step));
        } elseif ($type === 'step_failed') {
            $output->writeln(sprintf('  <error>%s:</error> failed', $step));
        } else {
            return;
        }

        if (is_string($event['stdout_log']) && $event['stdout_log'] !== '') {
            $output->writeln(sprintf('    <comment>stdout:</comment> %s', $event['stdout_log']));
        }
        if (is_string($event['stderr_log']) && $event['stderr_log'] !== '') {
            $output->writeln(sprintf('    <comment>stderr:</comment> %s', $event['stderr_log']));
        }
    }

    /**
     * @param list<string> $modules
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $skippedClasses
     */
    private function renderModuleAcceptance(
        OutputInterface $output,
        array $modules,
        array $acceptedCandidates,
        array $skippedClasses,
        array $moduleMethodTotals = [],
        array $moduleGeneratedMethodTotals = [],
    ): void
    {
        $rows = [];
        foreach ($this->discoveryService->moduleAcceptance($modules, $acceptedCandidates, $skippedClasses) as $row) {
            $module = $row['module'];
            $classAccepted = (int) $row['accepted'];
            $classTotal = (int) $row['total'];
            $classPercent = $classTotal > 0 ? ($classAccepted / $classTotal) * 100.0 : 0.0;

            $methodTotal = max(0, (int) ($moduleMethodTotals[$module] ?? 0));
            $methodAccepted = max(0, (int) ($moduleGeneratedMethodTotals[$module] ?? 0));
            if ($methodAccepted > $methodTotal) {
                $methodAccepted = $methodTotal;
            }
            $methodPercent = $methodTotal > 0 ? ($methodAccepted / $methodTotal) * 100.0 : 0.0;

            $rows[] = [
                $module,
                sprintf('%.1f%% (%d/%d)', $classPercent, $classAccepted, $classTotal),
                sprintf('%.1f%% (%d/%d)', $methodPercent, $methodAccepted, $methodTotal),
            ];
        }

        $output->writeln('<comment>Module acceptance:</comment>');
        $table = new Table($output);
        $table->setHeaders(['Module Name', 'Class Acceptance', 'Method Acceptance']);
        $table->setRows($rows);
        $table->render();
    }

    /**
     * @param list<string> $modules
     */
    private function runDiscoverCommand(
        InputInterface $input,
        OutputInterface $output,
        ?string $qtPath,
        array $modules,
        string $outputDir,
        int $jobs,
    ): int {
        $application = $this->getApplication();
        $usingApplicationCommand = $application?->has('build:discover') === true;
        $discoverCommand = $usingApplicationCommand
            ? $application->find('build:discover')
            : new BuildDiscoverCommand($this->systemInformation, $this->discoveryService);

        $arguments = [
            '--modules' => implode(',', $modules),
            '--output' => $outputDir,
            '--jobs' => (string) $jobs,
            '--no-acceptance-table' => true,
        ];
        if ($usingApplicationCommand) {
            $arguments['command'] = 'build:discover';
        }
        if ($qtPath !== null && $qtPath !== '') {
            $arguments['--qt-path'] = $qtPath;
        }

        $discoverInput = new ArrayInput($arguments);
        $discoverInput->setInteractive($input->isInteractive());

        return $discoverCommand->run($discoverInput, $output);
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $initialSkippedClasses
     * @param list<string> $initialAllowedClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @return array{
     *   accepted_candidates: list<HeaderCandidate>,
     *   generated_classes: list<string>,
     *   module_generated_method_totals: array<string, int>,
     *   file_write_stats: FileWriteStats,
     *   generated_class_parents: array<string, string|null>,
     *   generated_class_dependencies: array<string, list<string>>,
     *   skipped_classes: list<array<string, string|null>>,
     *   skipped_methods: list<array<string, string>>,
     *   errors: list<array<string, string|null>>,
     *   classmap: list<array{class: string, header: string, files: list<string>}>,
     *   passes: int,
     *   requires_signal_connection_support: bool
     * }
     */
    private function stabilizeGeneratedCandidates(
        array $acceptedCandidates,
        array $initialSkippedClasses,
        array $initialAllowedClasses,
        array $preparedClassDataByClass,
        string $outputDir,
        OutputInterface $output,
    ): array {
        $generationService = new ClassGenerationService();
        $generator = new ExtensionGenerator();
        $currentCandidates = array_values($acceptedCandidates);
        $currentAllowedClasses = array_values(array_unique($initialAllowedClasses));
        sort($currentAllowedClasses);

        /** @var array<string, array<string, string|null>> $skippedByClass */
        $skippedByClass = [];
        foreach ($initialSkippedClasses as $skippedClass) {
            $className = is_string($skippedClass['class'] ?? null) ? $skippedClass['class'] : null;
            if ($className === null || $className === '') {
                continue;
            }

            $skippedByClass[$className] = $skippedClass;
        }

        /** @var array<string, list<array<string, string>>> $skippedMethodsByClass */
        $skippedMethodsByClass = [];
        /** @var array<string, array<string, string|null>> $errorsByClass */
        $errorsByClass = [];
        /** @var list<array{class: string, header: string, files: list<string>}> $classmap */
        $classmap = [];
        /** @var list<string> $generatedClasses */
        $generatedClasses = [];
        /** @var array<string, string|null> $generatedClassParents */
        $generatedClassParents = [];
        /** @var array<string, list<string>> $generatedClassDependencies */
        $generatedClassDependencies = [];
        /** @var array<string, \QtBuilder\Definition\PhpClass> $generatedPhpClasses */
        $generatedPhpClasses = [];
        /** @var array<string, int> $moduleGeneratedMethodTotals */
        $moduleGeneratedMethodTotals = [];
        $fileWriteStats = new FileWriteStats();
        $passes = 0;
        $classNamespaces = $this->classNamespaces($acceptedCandidates);
        $candidateModules = [];
        foreach ($acceptedCandidates as $candidate) {
            $candidateModules[$candidate->className] = $candidate->module;
        }

        do {
            $passes++;
            if ($passes > 1) {
                $output->writeln(sprintf(
                    '<comment>Re-evaluating generated dependency set (pass %d, %d class(es)).</comment>',
                    $passes,
                    count($currentCandidates),
                ));
            }

            $progressBar = $this->createBuildProgressBar(
                $output,
                count($currentCandidates),
                'qt_generate_analysis',
                'Generate analysis pass ' . $passes,
            );
            $progressBar?->start();

            $generatedClasses = [];
            $generatedPhpClasses = [];

            foreach ($currentCandidates as $candidate) {
                $classData = $preparedClassDataByClass[$candidate->className] ?? null;
                if (!is_array($classData)) {
                    $errorsByClass[$candidate->className] = [
                        'module' => $candidate->module,
                        'class' => $candidate->className,
                        'header' => $candidate->parseHeader,
                        'reason_code' => 'missing_class_data',
                        'reason_message' => 'Prepared class data is missing from the class cache.',
                    ];
                    $progressBar?->advance();
                    continue;
                }

                $result = $generationService->generateFromPreparedData(
                    $classData,
                    $candidate->parseHeader,
                    $currentAllowedClasses,
                    $preparedClassDataByClass,
                );
                unset($errorsByClass[$result->className]);

                if ($result->status === 'ok' && $result->phpClass !== null) {
                    $generatedClasses[] = $result->className;
                    $generatedPhpClasses[$result->className] = $result->phpClass;
                    $payload = $result->toArray();
                    $generatedClassParents[$result->className] = is_string($payload['parent_class'] ?? null)
                        ? $payload['parent_class']
                        : null;
                    $generatedClassDependencies[$result->className] = array_values(array_filter(
                        array_map(
                            static fn(mixed $value): string => is_string($value) ? $value : '',
                            $payload['class_dependencies'] ?? [],
                        ),
                        static fn(string $value): bool => $value !== '',
                    ));
                    unset($skippedByClass[$result->className]);
                } elseif ($result->status === 'skipped') {
                    $skippedByClass[$result->className] = [
                        'module' => $candidateModules[$result->className] ?? null,
                        'class' => $result->className,
                        'header' => $result->headerPath,
                        'reason_code' => $result->reasonCode,
                        'reason_message' => $result->reasonMessage,
                    ];
                } else {
                    $errorsByClass[$candidate->className] = [
                        'module' => $candidate->module,
                        'class' => $candidate->className,
                        'header' => $candidate->parseHeader,
                        'reason_code' => 'generation_failed',
                        'reason_message' => 'Class generation analysis failed.',
                    ];
                }

                $skippedMethodsByClass[$result->className] = [];
                foreach ($result->skippedMethods as $skippedMethod) {
                    $skippedMethodsByClass[$result->className][] = ['class' => $result->className] + $skippedMethod;
                }

                $progressBar?->advance();
            }

            if ($progressBar !== null) {
                $progressBar->finish();
                $output->write(PHP_EOL);
            }

            sort($generatedClasses);
            $stable = $generatedClasses === $currentAllowedClasses;

            $nextCandidates = [];
            foreach ($currentCandidates as $candidate) {
                if (in_array($candidate->className, $generatedClasses, true)) {
                    $nextCandidates[] = $candidate;
                }
            }

            $currentCandidates = $nextCandidates;
            $currentAllowedClasses = $generatedClasses;
        } while (!$stable && $errorsByClass === [] && $currentCandidates !== []);

        if ($generatedClasses !== [] && $errorsByClass === []) {
            $output->writeln(sprintf('<info>Emitting %d generated class wrapper(s)...</info>', count($generatedClasses)));
            $emitProgressBar = $this->createBuildProgressBar(
                $output,
                count($generatedClasses),
                'qt_generate_emit',
                'Generate emit',
            );
            $emitProgressBar?->start();

            $classmap = [];
            foreach ($generatedClasses as $className) {
                $phpClass = $generatedPhpClasses[$className] ?? null;
                if ($phpClass === null) {
                    $errorsByClass[$className] = [
                        'module' => $candidateModules[$className] ?? null,
                        'class' => $className,
                        'header' => '',
                        'reason_code' => 'missing_php_class',
                        'reason_message' => 'Stable generation set is missing the PHP class definition.',
                    ];
                    $emitProgressBar?->advance();
                    continue;
                }

                $files = $generator->generate(
                    $phpClass,
                    $classNamespaces[$className] ?? 'Qt\\Core',
                    $outputDir . '/classes',
                    $classNamespaces,
                );
                $fileWriteStats->merge($generator->lastWriteStats());
                $classmap[] = [
                    'class' => $className,
                    'header' => $this->headerPathForClass($currentCandidates, $acceptedCandidates, $className),
                    'files' => $files,
                ];
                $emitProgressBar?->advance();
            }

            if ($emitProgressBar !== null) {
                $emitProgressBar->finish();
                $output->write(PHP_EOL);
            }
        }

        $skippedMethods = [];
        foreach ($skippedMethodsByClass as $items) {
            foreach ($items as $item) {
                $skippedMethods[] = $item;
            }
        }

        $requiresSignalConnectionSupport = false;
        foreach ($generatedPhpClasses as $phpClass) {
            if ($phpClass->signals !== []) {
                $requiresSignalConnectionSupport = true;
                break;
            }
        }

        $moduleGeneratedMethodTotals = [];
        foreach ($generatedPhpClasses as $className => $phpClass) {
            $module = $candidateModules[$className] ?? null;
            if ($module === null || $module === '') {
                continue;
            }

            $moduleGeneratedMethodTotals[$module] = ($moduleGeneratedMethodTotals[$module] ?? 0) + count($phpClass->methods);
        }

        return [
            'accepted_candidates' => $currentCandidates,
            'generated_classes' => $generatedClasses,
            'module_generated_method_totals' => $moduleGeneratedMethodTotals,
            'file_write_stats' => $fileWriteStats,
            'generated_class_parents' => $generatedClassParents,
            'generated_class_dependencies' => $generatedClassDependencies,
            'skipped_classes' => array_values($skippedByClass),
            'skipped_methods' => $skippedMethods,
            'errors' => array_values($errorsByClass),
            'classmap' => $classmap,
            'passes' => $passes,
            'requires_signal_connection_support' => $requiresSignalConnectionSupport,
        ];
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @return array<string, string>
     */
    private function classNamespaces(
        array $acceptedCandidates,
    ): array {
        $payload = [];
        foreach ($acceptedCandidates as $candidate) {
            $payload[$candidate->className] = $this->namespaceForModule($candidate->module);
        }

        return $payload;
    }

    private function headerPathForClass(array $currentCandidates, array $acceptedCandidates, string $className): string
    {
        foreach ($currentCandidates as $candidate) {
            if ($candidate->className === $className) {
                return $candidate->parseHeader;
            }
        }

        foreach ($acceptedCandidates as $candidate) {
            if ($candidate->className === $className) {
                return $candidate->parseHeader;
            }
        }

        return '';
    }

    private function createBuildProgressBar(OutputInterface $output, int $total, string $formatName, string $label): ?ProgressBar
    {
        if ($total <= 0) {
            return null;
        }

        ProgressBar::setFormatDefinition(
            $formatName,
            '%message% %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%',
        );

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat($formatName);
        $progressBar->setMessage($label);
        $progressBar->setRedrawFrequency(max(1, (int) ceil($total / 100)));
        $progressBar->minSecondsBetweenRedraws(0.1);
        $progressBar->maxSecondsBetweenRedraws(0.25);

        return $progressBar;
    }

}
