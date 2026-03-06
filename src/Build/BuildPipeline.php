<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\CodeGen\ExtensionGenerator;
use QtBuilder\IO\FileWriteStats;
use QtBuilder\Scanning\HeaderCandidate;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

class BuildPipeline
{
    public function __construct(
        private readonly ExtensionBootstrapper $bootstrapper,
        private readonly BuildDiscoveryService $discoveryService = new BuildDiscoveryService(),
    ) {}

    public function build(BuildExecutionRequest $request, OutputInterface $output): BuildExecutionResult
    {
        $context = new ExtensionBuildContext(
            $request->extensionName,
            $request->extensionVersion,
            $request->buildRootDir,
            $request->outputDir,
            $request->installation,
            $request->modules,
            linkModules: $request->effectiveLinkModules(),
            importIncludeRoots: $request->importedAbi?->includeDirs() ?? [],
        );

        $scaffolder = new ExtensionScaffolder();
        $scaffolder->prepare($context);
        $metadataDir = $context->metadataDir();

        $cachedDiscovery = null;
        if ($request->reuseDiscoveryCache) {
            $cachedDiscovery = $this->discoveryService->loadCache(
                $metadataDir,
                $request->modules,
                $request->installation->rootPath,
            );
        }

        if ($cachedDiscovery !== null) {
            $acceptedCandidates = $cachedDiscovery->acceptedCandidates;
            $skippedClasses = $cachedDiscovery->skippedClasses;
            $allowedClasses = $cachedDiscovery->allowedClasses;
            $candidateCount = $cachedDiscovery->candidateCount;
            $moduleMethodTotals = $cachedDiscovery->moduleMethodTotals;
            $moduleAcceptedMethodTotals = $cachedDiscovery->moduleAcceptedMethodTotals;
            $this->renderCacheUsage($output, $metadataDir);
        } else {
            if ($request->reuseDiscoveryCache) {
                $output->writeln('<comment>Discovery cache miss; invoking build:discover.</comment>');
            } elseif ($request->importedAbi !== null) {
                $output->writeln('<comment>Discovery cache bypassed; imported QtCore ABI requires a fresh viability pass.</comment>');
            }

            $output->writeln(sprintf('<info>Running %d parallel discovery worker(s)...</info>', $request->jobs));
            $discovery = $this->discoveryService->discover(
                $request->installation,
                $request->modules,
                $request->outputDir,
                $metadataDir,
                $request->jobs,
                $output,
                $request->extensionName,
                $request->importedAbi,
            );

            if ($discovery->errors !== []) {
                foreach ($discovery->errors as $error) {
                    $message = is_string($error['reason_message'] ?? null) ? $error['reason_message'] : 'Worker failed.';
                    $output->writeln(sprintf('<error>%s</error>', $message));
                }

                return new BuildExecutionResult(false, $context, [], $discovery->skippedClasses, $discovery->errors, []);
            }

            $acceptedCandidates = $discovery->acceptedCandidates;
            $skippedClasses = $discovery->skippedClasses;
            $allowedClasses = $discovery->allowedClasses;
            $candidateCount = $discovery->candidateCount;
            $moduleMethodTotals = $discovery->moduleMethodTotals;
            $moduleAcceptedMethodTotals = $discovery->moduleAcceptedMethodTotals;

            $this->discoveryService->writeCache(
                $metadataDir,
                $request->modules,
                $request->installation->rootPath,
                $discovery,
            );
        }

        $output->writeln(sprintf(
            '<info>Scanning complete.</info> %d candidates queued, %d filtered before generation.',
            count($acceptedCandidates),
            count($skippedClasses),
        ));

        $classStructures = $this->discoveryService->prepareClassStructures(
            $acceptedCandidates,
            $request->outputDir,
            $request->installation->includeRoots,
            $metadataDir,
            $request->jobs,
            $output,
            $request->extensionName,
        );

        if ($classStructures['errors'] !== []) {
            foreach ($classStructures['errors'] as $error) {
                $message = is_string($error['reason_message'] ?? null) ? $error['reason_message'] : 'Class structure cache failed.';
                $output->writeln(sprintf('<error>%s</error>', $message));
            }

            return new BuildExecutionResult(false, $context, [], [...$skippedClasses, ...$classStructures['skipped_classes']], $classStructures['errors'], []);
        }

        $acceptedCandidates = $classStructures['accepted_candidates'];
        $skippedClasses = [...$skippedClasses, ...$classStructures['skipped_classes']];

        $output->writeln('<info>Evaluating generated class set from cached class structures...</info>');
        $generation = $this->stabilizeGeneratedCandidates(
            $acceptedCandidates,
            $skippedClasses,
            $allowedClasses,
            $classStructures['prepared_class_data'],
            $request->outputDir,
            $output,
            $request->importedAbi,
            $request->forceSignalConnectionSupport,
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
            $request->modules,
            $acceptedCandidates,
            $skippedClasses,
            $moduleMethodTotals,
            $generation['module_generated_method_totals'] ?? [],
        );

        $this->discoveryService->writeCache(
            $metadataDir,
            $request->modules,
            $request->installation->rootPath,
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
            (bool) ($generation['emits_signal_connection_support'] ?? false),
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
                    $bootstrapResult = $this->bootstrapper->bootstrap($context, $request->jobs, function (array $event) use ($output): void {
                        $this->renderBootstrapEvent($output, $event);
                    });
                } catch (\RuntimeException $e) {
                    $bootstrapError = $e->getMessage();
                    $output->writeln(sprintf('<error>%s</error>', $bootstrapError));
                }
            }
        }

        $summary = [
            'modules' => $request->modules,
            'candidate_classes' => $candidateCount,
            'generated_classes' => count($generatedClasses),
            'skipped_classes' => count($skippedClasses),
            'failed_classes' => count($errors),
            'jobs' => $request->jobs,
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

        $abiManifest = null;
        if ($request->writeAbiManifest && $generatedClasses !== [] && $errors === [] && $bootstrapError === null) {
            $abiManifest = new ModuleAbiManifest(
                module: $request->modules[0] ?? 'QtCore',
                extensionName: $request->extensionName,
                buildRootDir: $request->buildRootDir,
                outputDir: $request->outputDir,
                metadataDir: $metadataDir,
                acceptedCandidatesPath: $metadataDir . '/accepted_candidates.json',
                classCacheDir: $request->buildRootDir . '/classes',
                includeDirs: [
                    $request->outputDir,
                    $request->outputDir . '/classes',
                ],
                classes: $generatedClasses,
                classNamespaces: $this->exportedClassNamespaces($acceptedCandidates, $generatedClasses),
                includesSignalConnectionSupport: $context->includeSignalConnectionSupport,
            );
            $abiManifest->write($metadataDir . '/module_abi.json');
            $summary['abi_manifest'] = $metadataDir . '/module_abi.json';
            file_put_contents($metadataDir . '/build_summary.json', json_encode($summary + ['errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
            $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $metadataDir . '/module_abi.json'));
        }

        $output->writeln(sprintf(
            '<comment>File writes:</comment> %d written (%d created, %d updated), %d unchanged [comparator: %s]',
            $totalWriteStats->written(),
            $totalWriteStats->created(),
            $totalWriteStats->updated(),
            $totalWriteStats->unchanged(),
            $scaffolder->writeComparatorName(),
        ));
        $output->writeln(sprintf(
            '<info>Generated %d class wrapper(s); %d class(es) skipped; %d error(s).</info>',
            count($generatedClasses),
            count($skippedClasses),
            count($errors),
        ));

        $successful = $generatedClasses !== [] && $errors === [] && $bootstrapError === null;

        return new BuildExecutionResult(
            $successful,
            $context,
            $generatedClasses,
            $skippedClasses,
            $errors,
            $summary,
            $abiManifest,
        );
    }

    private function moduleBinaryExists(ExtensionBuildContext $context): bool
    {
        return is_file($context->outputDir . '/modules/' . $context->extensionName . '.so');
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
    ): void {
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
     *   requires_signal_connection_support: bool,
     *   emits_signal_connection_support: bool
     * }
     */
    private function stabilizeGeneratedCandidates(
        array $acceptedCandidates,
        array $initialSkippedClasses,
        array $initialAllowedClasses,
        array $preparedClassDataByClass,
        string $outputDir,
        OutputInterface $output,
        ?ImportedModuleAbi $importedAbi = null,
        bool $forceSignalConnectionSupport = false,
    ): array {
        $generationService = new ClassGenerationService();
        $generator = new ExtensionGenerator();
        $currentCandidates = array_values($acceptedCandidates);
        $currentAllowedClasses = array_values(array_unique($initialAllowedClasses));
        sort($currentAllowedClasses);
        $importedAvailableClasses = $importedAbi?->availableClasses ?? [];
        $allPreparedClassData = $importedAbi !== null
            ? $importedAbi->mergePreparedClassData($preparedClassDataByClass)
            : $preparedClassDataByClass;

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
        $classNamespaces = $this->classNamespaces($acceptedCandidates, $importedAbi);
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

                $availableClasses = array_values(array_unique([
                    ...$currentAllowedClasses,
                    ...$importedAvailableClasses,
                ]));
                sort($availableClasses);

                $result = $generationService->generateFromPreparedData(
                    $classData,
                    $candidate->parseHeader,
                    $availableClasses,
                    $allPreparedClassData,
                    $importedAbi !== null,
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
                    $importedAbi === null,
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

        $requiresSignalConnectionSupport = false;
        foreach ($generatedPhpClasses as $phpClass) {
            if ($phpClass->signals !== []) {
                $requiresSignalConnectionSupport = true;
                break;
            }
        }

        $emitsSignalConnectionSupport = $importedAbi === null && ($requiresSignalConnectionSupport || $forceSignalConnectionSupport);
        if ($emitsSignalConnectionSupport && !$requiresSignalConnectionSupport) {
            $generator->generateSignalConnectionSupport($outputDir . '/classes');
            $fileWriteStats->merge($generator->lastWriteStats());
        }

        $skippedMethods = [];
        foreach ($skippedMethodsByClass as $items) {
            foreach ($items as $item) {
                $skippedMethods[] = $item;
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
            'emits_signal_connection_support' => $emitsSignalConnectionSupport,
        ];
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @return array<string, string>
     */
    private function classNamespaces(array $acceptedCandidates, ?ImportedModuleAbi $importedAbi = null): array
    {
        $payload = $importedAbi?->classNamespaces() ?? [];

        foreach ($acceptedCandidates as $candidate) {
            $payload[$candidate->className] = $this->namespaceForModule($candidate->module);
        }

        return $payload;
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<string> $generatedClasses
     * @return array<string, string>
     */
    private function exportedClassNamespaces(array $acceptedCandidates, array $generatedClasses): array
    {
        $generatedSet = array_fill_keys($generatedClasses, true);
        $namespaces = [];

        foreach ($acceptedCandidates as $candidate) {
            if (!isset($generatedSet[$candidate->className])) {
                continue;
            }

            $namespaces[$candidate->className] = $this->namespaceForModule($candidate->module);
        }

        return $namespaces;
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

    private function namespaceForModule(string $module): string
    {
        return 'Qt\\' . preg_replace('/^Qt/', '', $module);
    }
}
