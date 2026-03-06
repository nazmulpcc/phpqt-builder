<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\CodeGen\ExtensionGenerator;
use QtBuilder\Containers\QListSpecializationResolver;
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

    public function analyze(BuildExecutionRequest $request, OutputInterface $output): BuildAnalysisResult
    {
        $metadataDir = $request->buildRootDir . '/generated';
        $this->ensureDirectory($request->outputDir);
        $this->ensureDirectory($request->outputDir . '/classes');
        $this->ensureDirectory($metadataDir);

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
                $output->writeln('<comment>Discovery cache bypassed; imported module ABI requires a fresh viability pass.</comment>');
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

                return new BuildAnalysisResult(
                    metadataDir: $metadataDir,
                    candidateCount: $discovery->candidateCount,
                    acceptedCandidates: [],
                    skippedClasses: $discovery->skippedClasses,
                    skippedMethods: [],
                    errors: $discovery->errors,
                    generatedClasses: [],
                    generatedPhpClasses: [],
                    generatedClassParents: [],
                    generatedClassDependencies: [],
                    generatedClassHeaders: [],
                    generatedClassModules: [],
                    classNamespaces: [],
                    moduleMethodTotals: [],
                    moduleAcceptedMethodTotals: [],
                    moduleGeneratedMethodTotals: [],
                    passes: 0,
                    requiresSignalConnectionSupport: false,
                );
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

            return new BuildAnalysisResult(
                metadataDir: $metadataDir,
                candidateCount: $candidateCount,
                acceptedCandidates: [],
                skippedClasses: [...$skippedClasses, ...$classStructures['skipped_classes']],
                skippedMethods: [],
                errors: $classStructures['errors'],
                generatedClasses: [],
                generatedPhpClasses: [],
                generatedClassParents: [],
                generatedClassDependencies: [],
                generatedClassHeaders: [],
                generatedClassModules: [],
                classNamespaces: [],
                moduleMethodTotals: $moduleMethodTotals,
                moduleAcceptedMethodTotals: $moduleAcceptedMethodTotals,
                moduleGeneratedMethodTotals: [],
                passes: 0,
                requiresSignalConnectionSupport: false,
            );
        }

        $acceptedCandidates = $classStructures['accepted_candidates'];
        $skippedClasses = [...$skippedClasses, ...$classStructures['skipped_classes']];

        $output->writeln('<info>Evaluating generated class set from cached class structures...</info>');
        $generation = $this->resolveGeneratedCandidates(
            $acceptedCandidates,
            $skippedClasses,
            $allowedClasses,
            $classStructures['prepared_class_data'],
            $output,
            $request->importedAbi,
        );

        $acceptedCandidates = $generation['accepted_candidates'];
        $generatedClasses = $generation['generated_classes'];
        $skippedClasses = $generation['skipped_classes'];
        $skippedMethods = $generation['skipped_methods'];
        $errors = $generation['errors'];

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

        return new BuildAnalysisResult(
            metadataDir: $metadataDir,
            candidateCount: $candidateCount,
            acceptedCandidates: $acceptedCandidates,
            skippedClasses: $skippedClasses,
            skippedMethods: $skippedMethods,
            errors: $errors,
            generatedClasses: $generatedClasses,
            generatedPhpClasses: $generation['generated_php_classes'],
            generatedClassParents: $generation['generated_class_parents'],
            generatedClassDependencies: $generation['generated_class_dependencies'],
            generatedClassHeaders: $generation['generated_class_headers'],
            generatedClassModules: $generation['generated_class_modules'],
            classNamespaces: array_replace(
                $this->classNamespaces($acceptedCandidates, $request->importedAbi),
                $generation['synthetic_class_namespaces'] ?? [],
            ),
            moduleMethodTotals: $moduleMethodTotals,
            moduleAcceptedMethodTotals: $moduleAcceptedMethodTotals,
            moduleGeneratedMethodTotals: $generation['module_generated_method_totals'] ?? [],
            passes: $generation['passes'],
            requiresSignalConnectionSupport: (bool) ($generation['requires_signal_connection_support'] ?? false),
        );
    }

    public function build(BuildExecutionRequest $request, OutputInterface $output): BuildExecutionResult
    {
        $analysis = $this->analyze($request, $output);
        $context = new ExtensionBuildContext(
            $request->extensionName,
            $request->extensionVersion,
            $request->buildRootDir,
            $request->outputDir,
            $request->installation,
            $request->modules,
            linkModules: $request->effectiveLinkModules(),
            importIncludeRoots: array_values(array_unique([
                ...$request->importIncludeRoots,
                ...($request->importedAbi?->includeDirs() ?? []),
            ])),
        );

        $context = $context->withGeneratedClasses(
            $analysis->generatedClasses,
            $analysis->generatedClassParents,
            $analysis->generatedClassDependencies,
            $analysis->requiresSignalConnectionSupport || $request->forceSignalConnectionSupport,
        );

        if ($analysis->errors !== []) {
            return new BuildExecutionResult(false, $context, [], $analysis->skippedClasses, $analysis->errors, []);
        }

        $scaffolder = new ExtensionScaffolder();
        $scaffolder->prepare($context);
        $metadataDir = $context->metadataDir();

        $emission = $this->emitGeneratedClasses(
            $request->outputDir . '/classes',
            $analysis->generatedClasses,
            $analysis->generatedPhpClasses,
            $analysis->classNamespaces,
            $analysis->generatedClassHeaders,
            $context->includeSignalConnectionSupport,
            $output,
        );
        $scaffoldFiles = $scaffolder->finalize($context);
        $coreWriteStats = $scaffolder->lastWriteStats();
        $totalWriteStats = new FileWriteStats();
        $totalWriteStats->merge($emission['file_write_stats']);
        $totalWriteStats->merge($coreWriteStats);

        $bootstrap = $this->bootstrapExtension(
            $context,
            $request->jobs,
            $request->bootstrapEnabled,
            $totalWriteStats,
            $output,
        );

        $summary = [
            'modules' => $request->modules,
            'candidate_classes' => $analysis->candidateCount,
            'generated_classes' => count($analysis->generatedClasses),
            'skipped_classes' => count($analysis->skippedClasses),
            'failed_classes' => count($analysis->errors),
            'jobs' => $request->jobs,
            'generation_passes' => $analysis->passes,
            'bootstrap' => $bootstrap['result']?->toArray(),
            'bootstrap_error' => $bootstrap['error'],
            'bootstrap_skipped' => $bootstrap['skipped'],
            'bootstrap_disabled' => $bootstrap['disabled'],
            'file_writes' => [
                'comparator' => $scaffolder->writeComparatorName(),
                'class' => $emission['file_write_stats']->toArray(),
                'core' => $coreWriteStats->toArray(),
                'total' => $totalWriteStats->toArray(),
            ],
        ];

        file_put_contents($metadataDir . '/classmap.json', json_encode($emission['classmap'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/skipped_classes.json', json_encode($analysis->skippedClasses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/skipped_methods.json', json_encode($analysis->skippedMethods, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/build_summary.json', json_encode($summary + ['errors' => $analysis->errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        foreach ($scaffoldFiles as $file) {
            $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $file));
        }

        $abiManifest = null;
        if ($request->writeAbiManifest && $analysis->errors === [] && $bootstrap['error'] === null) {
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
                sharedIncludeDirs: [],
                dependencyModules: [],
                classes: $analysis->generatedClasses,
                classNamespaces: $this->exportedClassNamespaces($analysis->acceptedCandidates, $analysis->generatedClasses),
                includesSignalConnectionSupport: $context->includeSignalConnectionSupport,
            );
            $abiManifest->write($metadataDir . '/module_abi.json');
            $summary['abi_manifest'] = $metadataDir . '/module_abi.json';
            file_put_contents($metadataDir . '/build_summary.json', json_encode($summary + ['errors' => $analysis->errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
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
            count($analysis->generatedClasses),
            count($analysis->skippedClasses),
            count($analysis->errors),
        ));

        $successful = $analysis->errors === [] && $bootstrap['error'] === null;

        return new BuildExecutionResult(
            $successful,
            $context,
            $analysis->generatedClasses,
            $analysis->skippedClasses,
            $analysis->errors,
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
     *   generated_php_classes: array<string, \QtBuilder\Definition\PhpClass>,
     *   module_generated_method_totals: array<string, int>,
     *   generated_class_parents: array<string, string|null>,
     *   generated_class_dependencies: array<string, list<string>>,
     *   generated_class_headers: array<string, string>,
     *   generated_class_modules: array<string, string>,
     *   synthetic_class_namespaces: array<string, string>,
     *   skipped_classes: list<array<string, string|null>>,
     *   skipped_methods: list<array<string, string>>,
     *   errors: list<array<string, string|null>>,
     *   passes: int,
     *   requires_signal_connection_support: bool
     * }
     */
    private function resolveGeneratedCandidates(
        array $acceptedCandidates,
        array $initialSkippedClasses,
        array $initialAllowedClasses,
        array $preparedClassDataByClass,
        OutputInterface $output,
        ?ImportedModuleAbi $importedAbi = null,
    ): array {
        $generationService = new ClassGenerationService();
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
        /** @var list<string> $generatedClasses */
        $generatedClasses = [];
        /** @var array<string, string|null> $generatedClassParents */
        $generatedClassParents = [];
        /** @var array<string, list<string>> $generatedClassDependencies */
        $generatedClassDependencies = [];
        /** @var array<string, string> $generatedClassHeaders */
        $generatedClassHeaders = [];
        /** @var array<string, string> $generatedClassModules */
        $generatedClassModules = [];
        /** @var array<string, \QtBuilder\Definition\PhpClass> $generatedPhpClasses */
        $generatedPhpClasses = [];
        /** @var array<string, int> $moduleGeneratedMethodTotals */
        $moduleGeneratedMethodTotals = [];
        $passes = 0;
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
                    $generatedClassHeaders[$result->className] = $candidate->parseHeader;
                    $generatedClassModules[$result->className] = $candidate->module;
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

        $syntheticClasses = $this->synthesizeListWrapperClasses(
            $generatedPhpClasses,
            $preparedClassDataByClass,
            $generatedClassHeaders,
        );
        foreach ($syntheticClasses['generated_php_classes'] as $className => $phpClass) {
            $generatedPhpClasses[$className] = $phpClass;
            $generatedClassParents[$className] = $syntheticClasses['generated_class_parents'][$className] ?? null;
            $generatedClassDependencies[$className] = $syntheticClasses['generated_class_dependencies'][$className] ?? [];
            $generatedClassHeaders[$className] = $syntheticClasses['generated_class_headers'][$className] ?? '';
            $generatedClassModules[$className] = $syntheticClasses['generated_class_modules'][$className] ?? 'QtCore';
            $generatedClasses[] = $className;
        }
        $generatedClasses = array_values(array_unique($generatedClasses));
        sort($generatedClasses);

        $requiresSignalConnectionSupport = false;
        foreach ($generatedPhpClasses as $phpClass) {
            if ($phpClass->signals !== []) {
                $requiresSignalConnectionSupport = true;
                break;
            }
        }

        $skippedMethods = [];
        foreach ($skippedMethodsByClass as $items) {
            foreach ($items as $item) {
                $skippedMethods[] = $item;
            }
        }

        $moduleGeneratedMethodTotals = [];
        foreach ($generatedPhpClasses as $className => $phpClass) {
            $module = $generatedClassModules[$className] ?? $candidateModules[$className] ?? null;
            if ($module === null || $module === '') {
                continue;
            }

            $moduleGeneratedMethodTotals[$module] = ($moduleGeneratedMethodTotals[$module] ?? 0) + count($phpClass->methods);
        }

        return [
            'accepted_candidates' => $currentCandidates,
            'generated_classes' => $generatedClasses,
            'generated_php_classes' => $generatedPhpClasses,
            'module_generated_method_totals' => $moduleGeneratedMethodTotals,
            'generated_class_parents' => $generatedClassParents,
            'generated_class_dependencies' => $generatedClassDependencies,
            'generated_class_headers' => $generatedClassHeaders,
            'generated_class_modules' => $generatedClassModules,
            'synthetic_class_namespaces' => $syntheticClasses['class_namespaces'],
            'skipped_classes' => array_values($skippedByClass),
            'skipped_methods' => $skippedMethods,
            'errors' => array_values($errorsByClass),
            'passes' => $passes,
            'requires_signal_connection_support' => $requiresSignalConnectionSupport,
        ];
    }

    /**
     * @param array<string, \QtBuilder\Definition\PhpClass> $generatedPhpClasses
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, string> $generatedClassHeaders
     * @return array{
     *   generated_php_classes: array<string, \QtBuilder\Definition\PhpClass>,
     *   generated_class_parents: array<string, string|null>,
     *   generated_class_dependencies: array<string, list<string>>,
     *   generated_class_headers: array<string, string>,
     *   generated_class_modules: array<string, string>,
     *   class_namespaces: array<string, string>
     * }
     */
    private function synthesizeListWrapperClasses(
        array $generatedPhpClasses,
        array $preparedClassDataByClass,
        array $generatedClassHeaders,
    ): array
    {
        $resolver = new QListSpecializationResolver();
        $availableClasses = array_keys($generatedPhpClasses);
        $syntheticPhpClasses = [];
        $syntheticParents = [];
        $syntheticDependencies = [];
        $syntheticHeaders = [];
        $syntheticModules = [];
        $syntheticNamespaces = [];

        foreach ($generatedPhpClasses as $className => $phpClass) {
            if (!$resolver->isSyntheticListClassName($phpClass->parent ?? '')) {
                continue;
            }

            $headerPath = $generatedClassHeaders[$className] ?? null;
            if (!is_string($headerPath) || $headerPath === '') {
                continue;
            }

            $sourceBases = $this->classBaseDeclarationsFromSource($headerPath, $className);
            $specialization = null;
            foreach ($sourceBases as $baseClass) {
                $specialization = $resolver->specializationFor($baseClass);
                if ($specialization !== null) {
                    break;
                }
            }
            if ($specialization === null || isset($syntheticPhpClasses[$specialization->className])) {
                continue;
            }

            $syntheticClass = $resolver->buildPhpClass($specialization, $availableClasses);
            $syntheticPhpClasses[$specialization->className] = $syntheticClass;
            $syntheticParents[$specialization->className] = null;
            $syntheticDependencies[$specialization->className] = $this->classDependenciesForPhpClass($syntheticClass);
            $syntheticHeaders[$specialization->className] = 'synthetic:' . $specialization->rawType;
            $syntheticModules[$specialization->className] = 'QtCore';
            $syntheticNamespaces[$specialization->className] = 'Qt\\Core';
            $availableClasses[] = $specialization->className;
            $availableClasses = array_values(array_unique($availableClasses));
        }

        return [
            'generated_php_classes' => $syntheticPhpClasses,
            'generated_class_parents' => $syntheticParents,
            'generated_class_dependencies' => $syntheticDependencies,
            'generated_class_headers' => $syntheticHeaders,
            'generated_class_modules' => $syntheticModules,
            'class_namespaces' => $syntheticNamespaces,
        ];
    }

    /**
     * @return list<string>
     */
    private function classBaseDeclarationsFromSource(string $headerPath, string $className): array
    {
        $contents = @file_get_contents($headerPath);
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $pattern = sprintf(
            '/(?:^|\n)\s*(?:class|struct)\s+(?:[A-Za-z_][A-Za-z0-9_]*\s+)*%s\b(?P<bases>\s*:[^{]+)?\s*\{/s',
            preg_quote($className, '/'),
        );
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return [];
        }

        $basesClause = is_string($matches['bases'] ?? null) ? trim($matches['bases']) : '';
        if ($basesClause === '' || !str_starts_with($basesClause, ':')) {
            return [];
        }

        $basesClause = trim(substr($basesClause, 1));
        if ($basesClause === '') {
            return [];
        }

        $bases = [];
        foreach ($this->splitTopLevelBaseList($basesClause) as $base) {
            $base = preg_replace('/\b(public|protected|private|virtual)\b/', ' ', $base) ?? $base;
            $base = trim(preg_replace('/\s+/', ' ', $base) ?? $base);
            if ($base !== '') {
                $bases[] = $base;
            }
        }

        return $bases;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelBaseList(string $basesClause): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($basesClause);

        for ($i = 0; $i < $length; $i++) {
            $char = $basesClause[$i];

            if ($char === '<') {
                $depth++;
                $current .= $char;
                continue;
            }

            if ($char === '>') {
                $depth = max(0, $depth - 1);
                $current .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * @return list<string>
     */
    private function classDependenciesForPhpClass(\QtBuilder\Definition\PhpClass $phpClass): array
    {
        $dependencies = [];

        foreach ($phpClass->methods as $method) {
            foreach ($this->phpTypeParts($method->returnType) as $type) {
                $dependencies[$type] = true;
            }

            foreach ($method->parameters as $parameter) {
                foreach ($this->phpTypeParts($parameter->phpType) as $type) {
                    $dependencies[$type] = true;
                }
            }
        }

        unset($dependencies[$phpClass->name]);

        $resolved = array_keys($dependencies);
        sort($resolved);

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function phpTypeParts(string $phpType): array
    {
        $parts = [];

        foreach (explode('|', $phpType) as $part) {
            $part = trim($part);
            if ($part === '' || $part === 'null' || $part === 'mixed') {
                continue;
            }

            if (in_array($part, ['int', 'float', 'string', 'bool', 'array', 'void'], true)) {
                continue;
            }

            $part = ltrim($part, '\\');
            if (str_contains($part, '\\')) {
                $segments = explode('\\', $part);
                $part = end($segments) ?: $part;
            }

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return array_values(array_unique($parts));
    }

    /**
     * @param list<string> $generatedClasses
     * @param array<string, \QtBuilder\Definition\PhpClass> $generatedPhpClasses
     * @param array<string, string> $classNamespaces
     * @param array<string, string> $classHeaders
     * @return array{
     *   file_write_stats: FileWriteStats,
     *   classmap: list<array{class: string, header: string, files: list<string>}>
     * }
     */
    public function emitGeneratedClasses(
        string $outputDir,
        array $generatedClasses,
        array $generatedPhpClasses,
        array $classNamespaces,
        array $classHeaders,
        bool $emitSignalConnectionSupport,
        OutputInterface $output,
    ): array {
        $generator = new ExtensionGenerator();
        $fileWriteStats = new FileWriteStats();
        $classmap = [];

        if ($generatedClasses !== []) {
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
                    throw new \RuntimeException(sprintf(
                        'Stable generation set is missing the PHP class definition for %s.',
                        $className,
                    ));
                }

                $files = $generator->generate(
                    $phpClass,
                    $classNamespaces[$className] ?? 'Qt\\Core',
                    $outputDir,
                    $classNamespaces,
                    false,
                );
                $fileWriteStats->merge($generator->lastWriteStats());
                $classmap[] = [
                    'class' => $className,
                    'header' => $classHeaders[$className] ?? '',
                    'files' => $files,
                ];
                $emitProgressBar?->advance();
            }

            if ($emitProgressBar !== null) {
                $emitProgressBar->finish();
                $output->write(PHP_EOL);
            }
        }

        if ($emitSignalConnectionSupport) {
            $generator->generateSignalConnectionSupport($outputDir);
            $fileWriteStats->merge($generator->lastWriteStats());
        }

        return [
            'file_write_stats' => $fileWriteStats,
            'classmap' => $classmap,
        ];
    }

    /**
     * @return array{
     *   result: BootstrapResult|null,
     *   error: string|null,
     *   skipped: bool,
     *   disabled: bool
     * }
     */
    public function bootstrapExtension(
        ExtensionBuildContext $context,
        int $jobs,
        bool $bootstrapEnabled,
        FileWriteStats $totalWriteStats,
        OutputInterface $output,
    ): array {
        $bootstrapResult = null;
        $bootstrapError = null;
        $bootstrapSkipped = false;
        $bootstrapDisabled = false;

        if (!$bootstrapEnabled) {
            $bootstrapDisabled = true;
            $output->writeln('<comment>Skipping bootstrap (--no-build).</comment>');

            return [
                'result' => null,
                'error' => null,
                'skipped' => false,
                'disabled' => true,
            ];
        }

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

        return [
            'result' => $bootstrapResult,
            'error' => $bootstrapError,
            'skipped' => $bootstrapSkipped,
            'disabled' => $bootstrapDisabled,
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
