<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\CodeGen\ExtensionGenerator;
use QtBuilder\Containers\QListSpecializationResolver;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\IO\FileWriteStats;
use QtBuilder\IO\SmartFileWriter;
use QtBuilder\Scanning\HeaderCandidate;
use QtBuilder\Support\CppName;
use QtBuilder\Support\GeneratedTypeIdentity;
use QtBuilder\Support\ModuleNamespace;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class BuildPipeline
{
    public function __construct(
        private readonly ExtensionBootstrapper $bootstrapper,
        private readonly BuildDiscoveryService $discoveryService = new BuildDiscoveryService(),
        private readonly FixedPointEngine $fixedPointEngine = new FixedPointEngine(),
    ) {}

    public function analyze(BuildExecutionRequest $request, OutputInterface $output): BuildAnalysisResult
    {
        $analysisStartedAt = microtime(true);
        $timings = [];
        $metadataDir = $request->buildRootDir . '/generated';
        $this->ensureDirectory($request->outputDir);
        $this->ensureDirectory($request->outputDir . '/classes');
        $this->ensureDirectory($metadataDir);

        $discoveryStartedAt = microtime(true);
        $cachedDiscovery = null;
        if ($request->reuseDiscoveryCache) {
            $cachedDiscovery = $this->discoveryService->loadCache(
                $metadataDir,
                $request->modules,
                $request->installation->rootPath,
            );
        }
        $preparedClassDataByClass = [];
        $supplementalCandidates = [];

        if ($cachedDiscovery !== null) {
            $acceptedCandidates = $cachedDiscovery->acceptedCandidates;
            $skippedClasses = $cachedDiscovery->skippedClasses;
            $allowedClasses = $cachedDiscovery->allowedClasses;
            $candidateCount = $cachedDiscovery->candidateCount;
            $moduleMethodTotals = $cachedDiscovery->moduleMethodTotals;
            $moduleAcceptedMethodTotals = $cachedDiscovery->moduleAcceptedMethodTotals;
            $supplementalCandidates = $cachedDiscovery->supplementalCandidates;
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
                resolveViability: false,
            );

            if ($discovery->errors !== []) {
                foreach ($discovery->errors as $error) {
                    $message = is_string($error['reason_message'] ?? null) ? $error['reason_message'] : 'Worker failed.';
                    $output->writeln(sprintf('<error>%s</error>', $message));
                }

                $timings['discovery'] = microtime(true) - $discoveryStartedAt;
                $timings['analysis_total'] = microtime(true) - $analysisStartedAt;

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
                    enumHolders: [],
                    moduleMethodTotals: [],
                    moduleAcceptedMethodTotals: [],
                    moduleGeneratedMethodTotals: [],
                    passes: 0,
                    requiresSignalConnectionSupport: false,
                    timings: $timings,
                );
            }

            $acceptedCandidates = $discovery->acceptedCandidates;
            $skippedClasses = $discovery->skippedClasses;
            $allowedClasses = $discovery->allowedClasses;
            $candidateCount = $discovery->candidateCount;
            $moduleMethodTotals = $discovery->moduleMethodTotals;
            $moduleAcceptedMethodTotals = $discovery->moduleAcceptedMethodTotals;
            $preparedClassDataByClass = $discovery->preparedClassData;
            $supplementalCandidates = $discovery->supplementalCandidates;

            $this->discoveryService->writeCache(
                $metadataDir,
                $request->modules,
                $request->installation->rootPath,
                $discovery,
            );
        }
        $timings['discovery'] = microtime(true) - $discoveryStartedAt;
        $this->renderPhaseTiming($output, 'Discovery', $timings['discovery']);

        $output->writeln(sprintf(
            '<info>Scanning complete.</info> %d candidates queued, %d filtered before generation.',
            count($acceptedCandidates),
            count($skippedClasses),
        ));

        if ($cachedDiscovery === null && $preparedClassDataByClass !== []) {
            $timings['class_structure_cache'] = 0.0;
            $this->renderPhaseTiming($output, 'Class structure cache', $timings['class_structure_cache']);
            $timings['supplemental_discovery'] = 0.0;
            $this->renderPhaseTiming($output, 'Supplemental discovery', $timings['supplemental_discovery']);
        } else {
            $classStructureStartedAt = microtime(true);
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
                    enumHolders: [],
                    moduleMethodTotals: $moduleMethodTotals,
                    moduleAcceptedMethodTotals: $moduleAcceptedMethodTotals,
                    moduleGeneratedMethodTotals: [],
                    passes: 0,
                    requiresSignalConnectionSupport: false,
                    timings: $timings + ['class_structure_cache' => microtime(true) - $classStructureStartedAt, 'analysis_total' => microtime(true) - $analysisStartedAt],
                );
            }
            $timings['class_structure_cache'] = microtime(true) - $classStructureStartedAt;
            $this->renderPhaseTiming($output, 'Class structure cache', $timings['class_structure_cache']);

            $supplementalStartedAt = microtime(true);
            $acceptedCandidates = $classStructures['accepted_candidates'];
            $skippedClasses = [...$skippedClasses, ...$classStructures['skipped_classes']];
            $supplemental = $this->discoveryService->augmentWithSupplementalCandidates(
                $acceptedCandidates,
                $classStructures['prepared_class_data'],
                $request->modules,
                $request->installation->includeRoots,
                $request->outputDir,
                $metadataDir,
                $request->jobs,
                $output,
                $request->extensionName,
                $request->importedAbi?->availableClasses ?? [],
            );

            if ($supplemental['errors'] !== []) {
                foreach ($supplemental['errors'] as $error) {
                    $message = is_string($error['reason_message'] ?? null) ? $error['reason_message'] : 'Supplemental class discovery failed.';
                    $output->writeln(sprintf('<error>%s</error>', $message));
                }

                return new BuildAnalysisResult(
                    metadataDir: $metadataDir,
                    candidateCount: $candidateCount,
                    acceptedCandidates: [],
                    skippedClasses: [...$skippedClasses, ...$supplemental['skipped_classes']],
                    skippedMethods: [],
                    errors: $supplemental['errors'],
                    generatedClasses: [],
                    generatedPhpClasses: [],
                    generatedClassParents: [],
                    generatedClassDependencies: [],
                    generatedClassHeaders: [],
                    generatedClassModules: [],
                    classNamespaces: [],
                    enumHolders: [],
                    moduleMethodTotals: $moduleMethodTotals,
                    moduleAcceptedMethodTotals: $moduleAcceptedMethodTotals,
                    moduleGeneratedMethodTotals: [],
                    passes: 0,
                    requiresSignalConnectionSupport: false,
                    timings: $timings + ['supplemental_discovery' => microtime(true) - $supplementalStartedAt, 'analysis_total' => microtime(true) - $analysisStartedAt],
                );
            }

            $acceptedCandidates = $supplemental['accepted_candidates'];
            $skippedClasses = [...$skippedClasses, ...$supplemental['skipped_classes']];
            $preparedClassDataByClass = $supplemental['prepared_class_data'];
            if ($supplemental['supplemental_candidates'] !== []) {
                $supplementalCandidates = $supplemental['supplemental_candidates'];
            }
            $timings['supplemental_discovery'] = microtime(true) - $supplementalStartedAt;
            $this->renderPhaseTiming($output, 'Supplemental discovery', $timings['supplemental_discovery']);
        }
        file_put_contents(
            $metadataDir . '/supplemental_candidates.json',
            json_encode($supplementalCandidates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
        );

        $enumStartedAt = microtime(true);
        $classNamespaces = $this->classNamespaces($acceptedCandidates, $request->importedAbi);
        $enumCandidateHeaders = (new EnumCandidateHeaderCollector())->collect(
            $request->installation->includeRoots,
            $acceptedCandidates,
            $preparedClassDataByClass,
        );
        file_put_contents(
            $metadataDir . '/enum_candidate_headers.json',
            json_encode(array_map(
                static fn(EnumCandidateHeader $entry): array => $entry->toArray(),
                $enumCandidateHeaders,
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
        );
        $enumHolderCache = new EnumHolderCache();
        $enumExtractor = new EnumHolderExtractor();
        $enumRegistry = $enumHolderCache->load(
            $metadataDir,
            $request->installation->includeRoots,
            $acceptedCandidates,
            $skippedClasses,
            $classNamespaces,
            $enumCandidateHeaders,
        );

        if ($enumRegistry instanceof EnumHolderRegistry) {
            $output->writeln(sprintf(
                '<comment>Enum holder cache:</comment> hit (%s).',
                $enumHolderCache->path($metadataDir),
            ));
        } else {
            $output->writeln('<comment>Enum holder cache:</comment> miss.');
            $queuedEnumHeaderCount = count($enumCandidateHeaders);
            $enumHeaderCount = $enumExtractor->namespaceHeaderCount($acceptedCandidates, $skippedClasses, $enumCandidateHeaders);
            if ($queuedEnumHeaderCount > 0) {
                $output->writeln(sprintf(
                    '<comment>Queued enum candidate headers:</comment> %d (scanning %d total header(s)).',
                    $queuedEnumHeaderCount,
                    $enumHeaderCount,
                ));
            }
            if ($enumHeaderCount > 0 && $request->jobs > 1) {
                $output->writeln(sprintf(
                    '<info>Extracting enum holders with %d parallel worker(s)...</info>',
                    $request->jobs,
                ));
            }
            $enumProgressBar = $this->createBuildProgressBar(
                $output,
                $enumHeaderCount,
                'qt_enum_discovery',
                'Enum discovery',
            );
            $enumProgressBar?->start();
            $enumRegistry = $enumExtractor->extract(
                $request->installation->includeRoots,
                $acceptedCandidates,
                $skippedClasses,
                $preparedClassDataByClass,
                $classNamespaces,
                $enumCandidateHeaders,
                static function (int $completed, int $total) use ($enumProgressBar): void {
                    if ($enumProgressBar === null) {
                        return;
                    }

                    $enumProgressBar->setMaxSteps(max(1, $total));
                    $enumProgressBar->setProgress($completed);
                },
                $request->jobs,
                $metadataDir,
            );
            if ($enumProgressBar !== null) {
                $enumProgressBar->finish();
                $output->write(PHP_EOL);
            }
            $enumHolderCache->write(
                $metadataDir,
                $request->installation->includeRoots,
                $acceptedCandidates,
                $skippedClasses,
                $classNamespaces,
                $enumRegistry,
                $enumCandidateHeaders,
            );
        }
        $timings['enum_discovery'] = microtime(true) - $enumStartedAt;
        $this->renderPhaseTiming($output, 'Enum discovery', $timings['enum_discovery']);

        $output->writeln('<info>Evaluating generated class set from cached class structures...</info>');
        $generationAnalysisStartedAt = microtime(true);
        $generation = $this->resolveGeneratedCandidates(
            $acceptedCandidates,
            $skippedClasses,
            $preparedClassDataByClass,
            $output,
            $request->importedAbi,
            $enumRegistry,
        );

        $acceptedCandidates = $generation['accepted_candidates'];
        $generatedClasses = $generation['generated_classes'];
        $skippedClasses = $generation['skipped_classes'];
        $skippedMethods = $generation['skipped_methods'];
        $errors = $generation['errors'];

        $enumCacheCandidates = $acceptedCandidates;
        $enumCachePreparedClassData = $preparedClassDataByClass;
        $enumCacheSkippedClasses = $skippedClasses;

        $enumCacheWarmShape = $this->discoveryService->prepareClassStructures(
            $acceptedCandidates,
            $request->outputDir,
            $request->installation->includeRoots,
            $metadataDir,
            $request->jobs,
            new NullOutput(),
            $request->extensionName,
        );
        if ($enumCacheWarmShape['errors'] === []) {
            $enumCacheCandidates = $enumCacheWarmShape['accepted_candidates'];
            $enumCachePreparedClassData = $enumCacheWarmShape['prepared_class_data'];
            $enumCacheSkippedClasses = [...$skippedClasses, ...$enumCacheWarmShape['skipped_classes']];
        }

        $enumCacheClassNamespaces = $this->classNamespaces($enumCacheCandidates, $request->importedAbi);
        $enumCandidateHeaders = (new EnumCandidateHeaderCollector())->collect(
            $request->installation->includeRoots,
            $enumCacheCandidates,
            $enumCachePreparedClassData,
        );
        file_put_contents(
            $metadataDir . '/enum_candidate_headers.json',
            json_encode(array_map(
                static fn(EnumCandidateHeader $entry): array => $entry->toArray(),
                $enumCandidateHeaders,
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
        );
        $enumHolderCache->write(
            $metadataDir,
            $request->installation->includeRoots,
            $enumCacheCandidates,
            $enumCacheSkippedClasses,
            $enumCacheClassNamespaces,
            $enumRegistry,
            $enumCandidateHeaders,
        );

        $this->renderModuleAcceptance(
            $output,
            $request->modules,
            $acceptedCandidates,
            $skippedClasses,
            $moduleMethodTotals,
            $generation['module_generated_method_totals'] ?? [],
        );
        $timings['generation_analysis'] = microtime(true) - $generationAnalysisStartedAt;
        $this->renderPhaseTiming($output, 'Generation analysis', $timings['generation_analysis']);

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
                supplementalCandidates: $supplementalCandidates,
            ),
        );

        $timings['analysis_total'] = microtime(true) - $analysisStartedAt;
        $this->renderPhaseTiming($output, 'Analysis total', $timings['analysis_total']);

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
                $classNamespaces,
                $generation['synthetic_class_namespaces'] ?? [],
            ),
            enumHolders: $enumRegistry->holdersForModules($request->modules),
            moduleMethodTotals: $moduleMethodTotals,
            moduleAcceptedMethodTotals: $moduleAcceptedMethodTotals,
            moduleGeneratedMethodTotals: $generation['module_generated_method_totals'] ?? [],
            passes: $generation['passes'],
            requiresSignalConnectionSupport: (bool) ($generation['requires_signal_connection_support'] ?? false),
            timings: $timings,
        );
    }

    public function build(BuildExecutionRequest $request, OutputInterface $output): BuildExecutionResult
    {
        $buildStartedAt = microtime(true);
        $analysis = $this->analyze($request, $output);
        $timings = $analysis->timings;
        $runtimeManifest = (new RuntimeManifestBuilder())->buildForMonolithic(
            $request,
            $analysis,
            $analysis->requiresSignalConnectionSupport || $request->forceSignalConnectionSupport,
        );
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
            includeBuildInfoSupport: true,
            includeThreadRuntimeSupport: true,
            runtimeManifest: $runtimeManifest,
            buildMode: RuntimeManifest::MODE_MONOLITHIC,
        );

        $context = $context->withGeneratedClasses(
            $analysis->generatedClasses,
            $analysis->generatedClassParents,
            $analysis->generatedClassDependencies,
            $this->generatedClassIds($analysis->generatedPhpClasses),
            $analysis->enumHolders,
            $analysis->requiresSignalConnectionSupport || $request->forceSignalConnectionSupport,
        );

        if ($analysis->errors !== []) {
            return new BuildExecutionResult(false, $context, [], $analysis->skippedClasses, $analysis->errors, []);
        }

        $scaffolder = new ExtensionScaffolder();
        $scaffolder->prepare($context);
        $metadataDir = $context->metadataDir();
        $runtimeManifestPath = $metadataDir . '/runtime_manifest.json';
        $runtimeManifest->write($runtimeManifestPath);
        $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $runtimeManifestPath));

        $emissionStartedAt = microtime(true);
        $emission = $this->emitGeneratedClasses(
            $context,
            $analysis->generatedClasses,
            $analysis->generatedPhpClasses,
            $analysis->classNamespaces,
            $analysis->generatedClassHeaders,
            $output,
        );
        if ($context->installation->osFamily === 'Windows') {
            $this->relocateWindowsSourceBuckets($context);
        }
        $timings['emission'] = microtime(true) - $emissionStartedAt;
        $this->renderPhaseTiming($output, 'Emission', $timings['emission']);
        $scaffoldFiles = $scaffolder->finalize($context);
        $coreWriteStats = $scaffolder->lastWriteStats();
        $totalWriteStats = new FileWriteStats();
        $totalWriteStats->merge($emission['file_write_stats']);
        $totalWriteStats->merge($coreWriteStats);

        $bootstrapStartedAt = microtime(true);
        $bootstrap = $this->bootstrapExtension(
            $context,
            $request->jobs,
            $request->bootstrapEnabled,
            $request->useCcache,
            $totalWriteStats,
            $output,
        );
        $timings['bootstrap'] = microtime(true) - $bootstrapStartedAt;
        $this->renderPhaseTiming($output, 'Bootstrap', $timings['bootstrap']);
        $timings['build_total'] = microtime(true) - $buildStartedAt;
        $this->renderPhaseTiming($output, 'Build total', $timings['build_total']);

        $summary = [
            'modules' => $request->modules,
            'requested_modules' => $request->effectiveRequestedModules(),
            'expanded_modules' => $request->resolvedModuleGraph?->expandedModules() ?? $request->modules,
            'dependency_source' => $request->resolvedModuleGraph?->dependencySource ?? $request->dependencySource,
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
            'ccache_enabled' => $request->useCcache,
            'timings' => $timings,
            'file_writes' => [
                'comparator' => $scaffolder->writeComparatorName(),
                'class' => $emission['file_write_stats']->toArray(),
                'core' => $coreWriteStats->toArray(),
                'total' => $totalWriteStats->toArray(),
            ],
            'runtime_manifest' => $runtimeManifestPath,
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
                module: $request->effectiveRequestedModules()[0] ?? ($request->modules[0] ?? 'QtCore'),
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
                dependencyModules: $runtimeManifest->module($request->effectiveRequestedModules()[0] ?? ($request->modules[0] ?? 'QtCore'))?->dependencies ?? [],
                classes: $analysis->generatedClasses,
                classNamespaces: $this->exportedClassNamespaces($analysis->acceptedCandidates, $analysis->generatedClasses),
                includesSignalConnectionSupport: $context->includeSignalConnectionSupport,
                buildMode: $runtimeManifest->buildMode,
                qtVersion: $runtimeManifest->qtVersion,
                qtVersionMajor: $runtimeManifest->qtVersionMajor,
                qtVersionMinor: $runtimeManifest->qtVersionMinor,
                qtVersionPatch: $runtimeManifest->qtVersionPatch,
                extensionVersion: $runtimeManifest->extensionVersion,
                builderAbiVersion: $runtimeManifest->builderAbiVersion,
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
            'enum_holders_cache.json',
            'enum_candidate_headers.json',
            'supplemental_candidates.json',
        ] as $filename) {
            $path = $metadataDir . '/' . $filename;
            if (is_file($path)) {
                $output->writeln(sprintf('  <comment>cache:</comment> %s', $path));
            }
        }
    }

    private function renderPhaseTiming(OutputInterface $output, string $label, float $seconds): void
    {
        $output->writeln(sprintf(
            '  <comment>timing:</comment> %s %s',
            $label,
            $this->formatDurationSeconds($seconds),
        ));
    }

    private function formatDurationSeconds(float $seconds): string
    {
        if ($seconds < 1.0) {
            return sprintf('%.0f ms', $seconds * 1000);
        }

        return sprintf('%.2f s', $seconds);
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
            $output->writeln(sprintf(
                '  <info>%s:</info> succeeded (%s)',
                $step,
                $this->formatDurationSeconds((float) ($event['duration_seconds'] ?? 0.0)),
            ));
        } elseif ($type === 'step_failed') {
            $output->writeln(sprintf(
                '  <error>%s:</error> failed (%s)',
                $step,
                $this->formatDurationSeconds((float) ($event['duration_seconds'] ?? 0.0)),
            ));
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
        array $preparedClassDataByClass,
        OutputInterface $output,
        ?ImportedModuleAbi $importedAbi = null,
        ?EnumHolderRegistry $enumRegistry = null,
    ): array {
        $generationService = new ClassGenerationService();
        $currentCandidates = array_values($acceptedCandidates);
        $importedAvailableClasses = $importedAbi?->availableClasses ?? [];
        $allPreparedClassData = $importedAbi !== null
            ? $importedAbi->mergePreparedClassData($preparedClassDataByClass)
            : $preparedClassDataByClass;
        /** @var array<string, HeaderCandidate> $candidateMap */
        $candidateMap = [];
        foreach ($currentCandidates as $candidate) {
            $candidateMap[$candidate->identityKey()] = $candidate;
        }
        $currentAllowedSet = array_fill_keys(array_keys($candidateMap), true);
        $currentAllowedClasses = array_keys($currentAllowedSet);
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
        /** @var array<string, string|null> $rawGeneratedClassParents */
        $rawGeneratedClassParents = [];
        /** @var array<string, list<string>> $rawGeneratedClassDependencies */
        $rawGeneratedClassDependencies = [];
        /** @var array<string, int> $moduleGeneratedMethodTotals */
        $moduleGeneratedMethodTotals = [];
        $candidateModules = [];
        foreach ($acceptedCandidates as $candidate) {
            $candidateModules[$candidate->identityKey()] = $candidate->module;
        }
        $dependencyMap = $this->generatedCandidateDependencyMap($currentCandidates, $preparedClassDataByClass);
        $reverseDependencyMap = $this->generatedReverseDependencyMap($dependencyMap);
        $dirtyCandidates = array_fill_keys(array_keys($candidateMap), true);
        $passes = 0;

        while (true) {
            $dirtyKeys = array_values(array_filter(
                array_keys($dirtyCandidates),
                static fn(string $key): bool => isset($candidateMap[$key]),
            ));
            sort($dirtyKeys);
            if ($dirtyKeys === []) {
                break;
            }

            $passes++;
            if ($passes > 1) {
                $output->writeln(sprintf(
                    '<comment>Re-evaluating generated dependency set (pass %d, %d class(es)).</comment>',
                    $passes,
                    count($dirtyKeys),
                ));
            }

            $progressBar = $this->createBuildProgressBar(
                $output,
                count($dirtyKeys),
                'qt_generate_analysis',
                'Generate analysis pass ' . $passes,
            );
            $progressBar?->start();

            $availableClasses = array_values(array_unique([
                ...array_keys($currentAllowedSet),
                ...$importedAvailableClasses,
            ]));
            sort($availableClasses);

            $removedCandidates = [];
            foreach ($dirtyKeys as $candidateKey) {
                $candidate = $candidateMap[$candidateKey] ?? null;
                if (!$candidate instanceof HeaderCandidate) {
                    $progressBar?->advance();
                    continue;
                }

                $classData = $preparedClassDataByClass[$candidateKey] ?? null;
                if (!is_array($classData)) {
                    $errorsByClass[$candidateKey] = [
                        'module' => $candidate->module,
                        'class' => $candidate->className,
                        'header' => $candidate->parseHeader,
                        'reason_code' => 'missing_class_data',
                        'reason_message' => 'Prepared class data is missing from the class cache.',
                    ];
                    unset($skippedMethodsByClass[$candidateKey]);
                    unset(
                        $candidateMap[$candidateKey],
                        $generatedPhpClasses[$candidateKey],
                        $rawGeneratedClassParents[$candidateKey],
                        $rawGeneratedClassDependencies[$candidateKey],
                        $generatedClassHeaders[$candidateKey],
                        $generatedClassModules[$candidateKey],
                        $currentAllowedSet[$candidateKey],
                    );
                    $removedCandidates[$candidateKey] = true;
                    $progressBar?->advance();
                    continue;
                }

                $result = $generationService->generateFromPreparedData(
                    $classData,
                    $candidate->parseHeader,
                    $availableClasses,
                    $allPreparedClassData,
                    $importedAbi !== null,
                    $enumRegistry,
                );
                unset($errorsByClass[$candidateKey]);

                if ($result->status === 'ok' && $result->phpClass !== null) {
                    $generatedPhpClasses[$candidateKey] = $this->withResolvedNativeIncludes(
                        $result->phpClass,
                        $candidate,
                    );
                    $payload = $result->toArray();
                    $rawGeneratedClassParents[$candidateKey] = is_string($payload['parent_class'] ?? null)
                        ? $payload['parent_class']
                        : null;
                    $rawGeneratedClassDependencies[$candidateKey] = array_values(array_filter(
                        array_map(
                            static fn(mixed $value): string => is_string($value) ? $value : '',
                            $payload['class_dependencies'] ?? [],
                        ),
                        static fn(string $value): bool => $value !== '',
                    ));
                    $generatedClassHeaders[$candidateKey] = $candidate->parseHeader;
                    $generatedClassModules[$candidateKey] = $candidate->module;
                    $currentAllowedSet[$candidateKey] = true;
                    unset($skippedByClass[$candidateKey]);
                } elseif ($result->status === 'skipped') {
                    $skippedByClass[$candidateKey] = [
                        'module' => $candidateModules[$candidateKey] ?? null,
                        'class' => $result->className,
                        'header' => $result->headerPath,
                        'reason_code' => $result->reasonCode,
                        'reason_message' => $result->reasonMessage,
                    ];
                    unset(
                        $candidateMap[$candidateKey],
                        $generatedPhpClasses[$candidateKey],
                        $rawGeneratedClassParents[$candidateKey],
                        $rawGeneratedClassDependencies[$candidateKey],
                        $generatedClassHeaders[$candidateKey],
                        $generatedClassModules[$candidateKey],
                        $currentAllowedSet[$candidateKey],
                    );
                    $removedCandidates[$candidateKey] = true;
                } else {
                    $errorsByClass[$candidateKey] = [
                        'module' => $candidate->module,
                        'class' => $candidate->className,
                        'header' => $candidate->parseHeader,
                        'reason_code' => 'generation_failed',
                        'reason_message' => 'Class generation analysis failed.',
                    ];
                    unset(
                        $candidateMap[$candidateKey],
                        $generatedPhpClasses[$candidateKey],
                        $rawGeneratedClassParents[$candidateKey],
                        $rawGeneratedClassDependencies[$candidateKey],
                        $generatedClassHeaders[$candidateKey],
                        $generatedClassModules[$candidateKey],
                        $currentAllowedSet[$candidateKey],
                    );
                    $removedCandidates[$candidateKey] = true;
                }

                if ($result->status === 'ok') {
                    $skippedMethodsByClass[$candidateKey] = [];
                    foreach ($result->skippedMethods as $skippedMethod) {
                        $skippedMethodsByClass[$candidateKey][] = [
                            'module' => $candidateModules[$candidateKey] ?? null,
                            'class' => $result->className,
                        ] + $skippedMethod;
                    }
                } else {
                    unset($skippedMethodsByClass[$candidateKey]);
                }

                $progressBar?->advance();
            }

            if ($progressBar !== null) {
                $progressBar->finish();
                $output->write(PHP_EOL);
            }

            $currentCandidates = array_values(array_filter(
                $currentCandidates,
                static fn(HeaderCandidate $candidate): bool => isset($candidateMap[$candidate->identityKey()]),
            ));
            $currentAllowedSet = array_fill_keys(array_keys($candidateMap), true);
            $currentAllowedClasses = array_keys($currentAllowedSet);
            sort($currentAllowedClasses);

            if ($errorsByClass !== [] || $currentCandidates === [] || $removedCandidates === []) {
                break;
            }

            $dirtyCandidates = $this->generatedImpactedDependents(
                array_keys($removedCandidates),
                $reverseDependencyMap,
                $candidateMap,
            );
        }

        $generatedClasses = array_keys($candidateMap);
        sort($generatedClasses);

        $syntheticClasses = $this->synthesizeListWrapperClasses(
            $generatedPhpClasses,
            $preparedClassDataByClass,
            $generatedClassHeaders,
        );
        foreach ($syntheticClasses['generated_php_classes'] as $className => $phpClass) {
            $generatedPhpClasses[$className] = $phpClass;
            $rawGeneratedClassParents[$className] = $syntheticClasses['generated_class_parents'][$className] ?? null;
            $rawGeneratedClassDependencies[$className] = $syntheticClasses['generated_class_dependencies'][$className] ?? [];
            $generatedClassHeaders[$className] = $syntheticClasses['generated_class_headers'][$className] ?? '';
            $generatedClassModules[$className] = $syntheticClasses['generated_class_modules'][$className] ?? 'QtCore';
            $generatedClasses[] = $className;
        }
        $generatedClasses = array_values(array_unique($generatedClasses));
        sort($generatedClasses);
        $generatedClassParents = $this->resolveGeneratedClassParents($rawGeneratedClassParents, $generatedPhpClasses, $generatedClassModules);
        $generatedClassDependencies = $this->resolveGeneratedClassDependencies($rawGeneratedClassDependencies, $generatedPhpClasses, $generatedClassModules, $generatedClassParents);
        $generatedPhpClasses = $this->normalizeGeneratedPhpClassesAgainstParentContracts($generatedPhpClasses);

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
     * @param array<string, PhpClass> $generatedPhpClasses
     * @return array<string, PhpClass>
     */
    private function normalizeGeneratedPhpClassesAgainstParentContracts(array $generatedPhpClasses): array
    {
        $cache = [];

        foreach ($generatedPhpClasses as $className => $phpClass) {
            $parentMethods = $this->collectAbstractPublicParentMethods($className, $generatedPhpClasses, $cache);
            if ($parentMethods === []) {
                continue;
            }

            $methods = [];
            $changed = false;
            foreach ($phpClass->methods as $method) {
                $parentMethod = $parentMethods[$method->name] ?? null;
                if (
                    $method->name !== '__construct'
                    && $parentMethod instanceof PhpMethod
                    && $parentMethod->isAbstractMethod
                    && $parentMethod->access === 'public'
                    && $method->access !== 'public'
                ) {
                    $methods[] = new PhpMethod(
                        name: $method->name,
                        access: 'public',
                        isStatic: $method->isStatic,
                        isSignal: $method->isSignal,
                        isSlot: $method->isSlot,
                        isAbstractMethod: $method->isAbstractMethod,
                        returnType: $method->returnType,
                        parameters: $method->parameters,
                        overloads: $method->overloads,
                        cppName: $method->cppName,
                    );
                    $changed = true;
                    continue;
                }

                $methods[] = $method;
            }

            if (!$changed) {
                continue;
            }

            $generatedPhpClasses[$className] = new PhpClass(
                name: $phpClass->name,
                parent: $phpClass->parent,
                isAbstract: $phpClass->isAbstract,
                isCopyConstructible: $phpClass->isCopyConstructible,
                hasPublicConstructor: $phpClass->hasPublicConstructor,
                hasPublicDestructor: $phpClass->hasPublicDestructor,
                isQObjectDerived: $phpClass->isQObjectDerived,
                properties: $phpClass->properties,
                methods: $methods,
                signals: $phpClass->signals,
                classConstants: $phpClass->classConstants,
                nativeIncludes: $phpClass->nativeIncludes,
                nativeAliasOf: $phpClass->nativeAliasOf,
                nativeCppType: $phpClass->nativeCppType,
                generationId: $phpClass->generationId,
                smartPointerAliases: $phpClass->smartPointerAliases,
            );
        }

        return $generatedPhpClasses;
    }

    /**
     * @param array<string, PhpClass> $generatedPhpClasses
     * @param array<string, array<string, PhpMethod>> $cache
     * @return array<string, PhpMethod>
     */
    private function collectAbstractPublicParentMethods(string $className, array $generatedPhpClasses, array &$cache): array
    {
        if (isset($cache[$className])) {
            return $cache[$className];
        }

        $phpClass = $generatedPhpClasses[$className] ?? null;
        if (!$phpClass instanceof PhpClass || $phpClass->parent === null || $phpClass->parent === '') {
            return $cache[$className] = [];
        }

        $parentKey = $this->resolveGeneratedClassKey($phpClass->parent, $generatedPhpClasses);
        $parentPhpClass = $parentKey !== null ? ($generatedPhpClasses[$parentKey] ?? null) : null;
        if (!$parentPhpClass instanceof PhpClass) {
            return $cache[$className] = [];
        }

        $methods = $this->collectAbstractPublicParentMethods($parentKey, $generatedPhpClasses, $cache);
        foreach ($parentPhpClass->methods as $method) {
            if ($method->isAbstractMethod && $method->access === 'public') {
                $methods[$method->name] = $method;
            }
        }

        return $cache[$className] = $methods;
    }

    /**
     * @param array<string, PhpClass> $generatedPhpClasses
     * @param array<string, string> $generatedClassModules
     * @param array<string, string|null> $rawParents
     * @return array<string, string|null>
     */
    private function resolveGeneratedClassParents(array $rawParents, array $generatedPhpClasses, array $generatedClassModules): array
    {
        $resolved = [];
        foreach ($rawParents as $classKey => $parentType) {
            $resolved[$classKey] = is_string($parentType)
                ? $this->resolveGeneratedClassKey($parentType, $generatedPhpClasses, $classKey, $generatedClassModules)
                : null;
        }

        return $resolved;
    }

    /**
     * @param array<string, PhpClass> $generatedPhpClasses
     * @param array<string, string> $generatedClassModules
     * @param array<string, list<string>> $rawDependencies
     * @param array<string, string|null> $resolvedParents
     * @return array<string, list<string>>
     */
    private function resolveGeneratedClassDependencies(array $rawDependencies, array $generatedPhpClasses, array $generatedClassModules, array $resolvedParents): array
    {
        $resolved = [];

        foreach ($rawDependencies as $classKey => $dependencyTypes) {
            $dependencies = [];
            foreach ($dependencyTypes as $dependencyType) {
                $resolvedKey = $this->resolveGeneratedClassKey($dependencyType, $generatedPhpClasses, $classKey, $generatedClassModules);
                if ($resolvedKey === null || $resolvedKey === $classKey || $resolvedKey === ($resolvedParents[$classKey] ?? null)) {
                    continue;
                }

                $dependencies[$resolvedKey] = true;
            }

            $resolved[$classKey] = array_keys($dependencies);
            sort($resolved[$classKey]);
        }

        return $resolved;
    }

    /**
     * @param array<string, PhpClass> $generatedPhpClasses
     * @param array<string, string> $generatedClassModules
     */
    private function resolveGeneratedClassKey(string $type, array $generatedPhpClasses, ?string $ownerKey = null, array $generatedClassModules = []): ?string
    {
        $trimmed = ltrim(trim($type), '\\');
        if ($trimmed === '') {
            return null;
        }

        if (isset($generatedPhpClasses[$trimmed])) {
            return $trimmed;
        }

        $ownerPhpClass = $ownerKey !== null ? ($generatedPhpClasses[$ownerKey] ?? null) : null;
        $ownerNamespace = $ownerKey !== null ? $this->classPhpNamespaceForKey($ownerKey, $generatedClassModules) : null;
        $matches = [];

        foreach ($generatedPhpClasses as $classKey => $phpClass) {
            $qualifiedCpp = $phpClass->nativeCppType ?? $phpClass->name;
            $phpNamespace = $this->classPhpNamespaceForKey($classKey, $generatedClassModules);
            $phpFqn = $phpNamespace . '\\' . $phpClass->name;

            if ($trimmed === ltrim($phpFqn, '\\') || $trimmed === ($phpClass->nativeCppType ?? '') || $trimmed === $phpClass->name) {
                $matches[$classKey] = true;
                continue;
            }

            if ($ownerPhpClass instanceof PhpClass) {
                $ownerQualified = $ownerPhpClass->nativeCppType ?? $ownerPhpClass->name;
                if (
                    ($phpClass->nativeCppType ?? '') !== ''
                    && str_contains($ownerQualified, '::')
                    && str_contains($phpClass->nativeCppType ?? '', '::')
                    && \QtBuilder\Support\TypeResolutionContext::moduleForQualifiedName($ownerQualified) === \QtBuilder\Support\TypeResolutionContext::moduleForQualifiedName($phpClass->nativeCppType ?? '')
                    && $trimmed === $phpClass->name
                ) {
                    $matches = [$classKey => true];
                    break;
                }
            }
        }

        $resolvedKeys = array_keys($matches);
        if (count($resolvedKeys) === 1) {
            return $resolvedKeys[0];
        }

        return null;
    }

    /**
     * @param array<string, string> $generatedClassModules
     */
    private function classPhpNamespaceForKey(string $classKey, array $generatedClassModules): string
    {
        $module = $generatedClassModules[$classKey] ?? \QtBuilder\Support\TypeResolutionContext::moduleForQualifiedName($classKey) ?? 'QtCore';

        return ModuleNamespace::forQualifiedCppClass($module, $classKey);
    }

    private function withResolvedNativeIncludes(PhpClass $phpClass, HeaderCandidate $candidate): PhpClass
    {
        $resolvedInclude = $this->qtIncludeForHeaderCandidate($candidate);
        if ($resolvedInclude === null || $phpClass->nativeIncludes === [$resolvedInclude]) {
            return $phpClass;
        }

        return new PhpClass(
            name: $phpClass->name,
            parent: $phpClass->parent,
            isAbstract: $phpClass->isAbstract,
            isCopyConstructible: $phpClass->isCopyConstructible,
            hasPublicConstructor: $phpClass->hasPublicConstructor,
            hasPublicDestructor: $phpClass->hasPublicDestructor,
            isQObjectDerived: $phpClass->isQObjectDerived,
            properties: $phpClass->properties,
            methods: $phpClass->methods,
            signals: $phpClass->signals,
            classConstants: $phpClass->classConstants,
            nativeIncludes: [$resolvedInclude],
            nativeAliasOf: $phpClass->nativeAliasOf,
            nativeCppType: $phpClass->nativeCppType,
            generationId: $phpClass->generationId,
            smartPointerAliases: $phpClass->smartPointerAliases,
        );
    }

    private function qtIncludeForHeaderCandidate(HeaderCandidate $candidate): ?string
    {
        foreach ([$candidate->publicHeader, $candidate->parseHeader] as $headerPath) {
            $relativeHeader = $this->relativeQtHeaderPath($headerPath);
            if ($relativeHeader !== null) {
                return sprintf('<%s>', $relativeHeader);
            }
        }

        return null;
    }

    private function relativeQtHeaderPath(string $headerPath): ?string
    {
        $normalized = str_replace('\\', '/', $headerPath);

        if (preg_match('~/(Qt[^/]+)\.framework(?:/Versions/[^/]+)?/Headers/(.+)$~', $normalized, $matches) === 1) {
            return is_string($matches[1] ?? null) && is_string($matches[2] ?? null) && $matches[1] !== '' && $matches[2] !== ''
                ? $matches[1] . '/' . $matches[2]
                : null;
        }

        if (preg_match('~/include/(Qt[^/]+/.+)$~', $normalized, $matches) === 1) {
            return is_string($matches[1]) && $matches[1] !== '' ? $matches[1] : null;
        }

        $basename = basename($normalized);

        return $basename !== '' ? $basename : null;
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

            $sourceBases = $this->classBaseDeclarationsFromPreparedData($preparedClassDataByClass[$className] ?? null);
            if ($sourceBases === []) {
                $sourceBases = $this->classBaseDeclarationsFromSource($headerPath, $className);
            }
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
     * @param array<string, mixed>|null $classData
     * @return list<string>
     */
    private function classBaseDeclarationsFromPreparedData(?array $classData): array
    {
        if (!is_array($classData)) {
            return [];
        }

        $baseSpecifiers = is_array($classData['base_specifiers'] ?? null) ? $classData['base_specifiers'] : [];
        $bases = [];
        foreach ($baseSpecifiers as $specifier) {
            if (!is_array($specifier)) {
                continue;
            }

            $type = is_string($specifier['type'] ?? null) ? trim($specifier['type']) : '';
            if ($type !== '') {
                $bases[] = $type;
            }
        }

        if ($bases === []) {
            return [];
        }

        $bases = array_values(array_unique($bases));

        return $bases;
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
                $bases[] = CppName::unqualify($base);
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
     *   classmap: list<array{class: string, qualified_name: string, generation_id: string, header: string, files: list<string>}>
     * }
     */
    public function emitGeneratedClasses(
        ExtensionBuildContext $context,
        array $generatedClasses,
        array $generatedPhpClasses,
        array $classNamespaces,
        array $classHeaders,
        OutputInterface $output,
    ): array {
        $generator = new ExtensionGenerator();
        $supportsRuntimeNotifyFunctorConnect = $context->installation->osFamily !== 'Windows';
        $fileWriteStats = new FileWriteStats();
        $classmap = [];
        $outputDir = $context->outputDir . '/classes';
        $classNativeTypes = [];
        $classMetadata = [];
        foreach ($generatedPhpClasses as $name => $phpClass) {
            if ($phpClass->nativeCppType !== null && $phpClass->nativeCppType !== '') {
                $classNativeTypes[$name] = $phpClass->nativeCppType;
            }
            $classMetadata[$name] = [
                'name' => $phpClass->name,
                'namespace' => $classNamespaces[$name] ?? 'Qt\\Core',
                'generation_id' => $phpClass->resolvedGenerationId(),
                'qualified_name' => $phpClass->nativeCppType ?? $phpClass->name,
                'module' => $this->moduleFromPhpNamespace($classNamespaces[$name] ?? 'Qt\\Core'),
                'is_qobject_derived' => $phpClass->isQObjectDerived,
            ];
        }
        $this->removeStaleEnumHolderFiles($outputDir, $context->enumHolders);
        $this->removeStaleGeneratedClassFiles($context, $outputDir);

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
                    $classNativeTypes,
                    $classMetadata,
                    false,
                    $supportsRuntimeNotifyFunctorConnect,
                );
                $fileWriteStats->merge($generator->lastWriteStats());
                $classmap[] = [
                    'class' => $phpClass->name,
                    'qualified_name' => $phpClass->nativeCppType ?? $phpClass->name,
                    'generation_id' => $phpClass->resolvedGenerationId(),
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

        if ($context->includeBuildInfoSupport) {
            $generator->generateBuildInfoSupport($outputDir, $context);
            $fileWriteStats->merge($generator->lastWriteStats());
        }

        foreach ($context->enumHolders as $holder) {
            $generator->generateEnumHolderSupport($outputDir, $holder);
            $fileWriteStats->merge($generator->lastWriteStats());
        }

        if ($context->includeSignalConnectionSupport) {
            $generator->generateSignalConnectionSupport($outputDir);
            $fileWriteStats->merge($generator->lastWriteStats());
        }

        if ($context->includeThreadRuntimeSupport) {
            $generator->generateThreadRuntimeSupport($outputDir);
            $fileWriteStats->merge($generator->lastWriteStats());
        }

        return [
            'file_write_stats' => $fileWriteStats,
            'classmap' => $classmap,
        ];
    }

    /**
     * @param list<EnumHolderDefinition> $enumHolders
     */
    private function removeStaleEnumHolderFiles(string $outputDir, array $enumHolders): void
    {
        if (!is_dir($outputDir)) {
            return;
        }

        $activePrefixes = array_fill_keys(
            array_map(
                static fn(EnumHolderDefinition $holder): string => $holder->filePrefix(),
                $enumHolders,
            ),
            true,
        );

        $removedAny = false;

        foreach (glob($outputDir . '/qt_enum_*') ?: [] as $path) {
            $basename = basename($path);
            $prefix = null;

            if (preg_match('/^(qt_enum_[^.]+)\.(?:h|cpp|stub\.php|dep|lo)$/', $basename, $matches) === 1) {
                $prefix = $matches[1];
            } elseif (preg_match('/^(qt_enum_[^_]+(?:_[^_]+)*)_arginfo\.h$/', $basename, $matches) === 1) {
                $prefix = $matches[1];
            }

            if ($prefix === null || isset($activePrefixes[$prefix])) {
                continue;
            }

            if (!@unlink($path) && file_exists($path)) {
                throw new \RuntimeException(sprintf('Could not remove stale enum holder file: %s', $path));
            }

            $removedAny = true;
        }

        $libsDir = $outputDir . '/.libs';
        if (is_dir($libsDir)) {
            foreach (glob($libsDir . '/qt_enum_*') ?: [] as $path) {
                $basename = basename($path);
                if (preg_match('/^(qt_enum_[^.]+)\.(?:o|obj)$/', $basename, $matches) !== 1) {
                    continue;
                }

                $prefix = $matches[1];
                if (isset($activePrefixes[$prefix])) {
                    continue;
                }

                if (!@unlink($path) && file_exists($path)) {
                    throw new \RuntimeException(sprintf('Could not remove stale enum holder object file: %s', $path));
                }

                $removedAny = true;
            }
        }

        if ($removedAny) {
            $qtDepPath = dirname($outputDir) . '/qt.dep';
            if (is_file($qtDepPath) && !@unlink($qtDepPath) && file_exists($qtDepPath)) {
                throw new \RuntimeException(sprintf('Could not remove stale extension dependency file: %s', $qtDepPath));
            }
        }
    }

    private function removeStaleGeneratedClassFiles(ExtensionBuildContext $context, string $outputDir): void
    {
        if (!is_dir($outputDir)) {
            return;
        }

        $activePrefixes = [];
        foreach ($context->classMinits() as $minitName) {
            if ($minitName !== '') {
                $activePrefixes[$minitName] = true;
            }
        }

        $removedAny = false;

        foreach (glob($outputDir . '/qt_*') ?: [] as $path) {
            $basename = basename($path);
            $prefix = null;

            if (preg_match('/^(qt_[^.]+)\.(?:h|cpp|stub\.php|dep|lo)$/', $basename, $matches) === 1) {
                $prefix = $matches[1];
            } elseif (preg_match('/^(qt_[^_]+(?:_[^_]+)*)_arginfo\.h$/', $basename, $matches) === 1) {
                $prefix = $matches[1];
            }

            if ($prefix === null || str_starts_with($prefix, 'qt_enum_') || isset($activePrefixes[$prefix])) {
                continue;
            }

            if (!@unlink($path) && file_exists($path)) {
                throw new \RuntimeException(sprintf('Could not remove stale generated class file: %s', $path));
            }

            $removedAny = true;
        }

        $libsDir = $outputDir . '/.libs';
        if (is_dir($libsDir)) {
            foreach (glob($libsDir . '/qt_*') ?: [] as $path) {
                $basename = basename($path);
                if (preg_match('/^(qt_[^.]+)\.(?:o|obj)$/', $basename, $matches) !== 1) {
                    continue;
                }

                $prefix = $matches[1];
                if (str_starts_with($prefix, 'qt_enum_') || isset($activePrefixes[$prefix])) {
                    continue;
                }

                if (!@unlink($path) && file_exists($path)) {
                    throw new \RuntimeException(sprintf('Could not remove stale generated class object file: %s', $path));
                }

                $removedAny = true;
            }
        }

        if ($removedAny) {
            $qtDepPath = dirname($outputDir) . '/qt.dep';
            if (is_file($qtDepPath) && !@unlink($qtDepPath) && file_exists($qtDepPath)) {
                throw new \RuntimeException(sprintf('Could not remove stale extension dependency file: %s', $qtDepPath));
            }
        }
    }

    private function relocateWindowsSourceBuckets(ExtensionBuildContext $context): void
    {
        $classesDir = $context->outputDir . '/classes';
        $writer = new SmartFileWriter();
        if (!is_dir($classesDir)) {
            return;
        }

        $unitySources = $context->windowsUnitySourceFiles();
        $activeBucketDirs = array_fill_keys(array_keys($unitySources), true);
        foreach (glob($context->outputDir . '/src_*', GLOB_ONLYDIR) ?: [] as $existingBucketDir) {
            $bucketName = basename($existingBucketDir);
            if (!isset($activeBucketDirs[$bucketName])) {
                $this->removeDirectory($existingBucketDir);
                continue;
            }

            $expectedUnityFile = $unitySources[$bucketName] ?? null;
            foreach (scandir($existingBucketDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $existingBucketDir . '/' . $entry;
                if (is_dir($path)) {
                    $this->removeDirectory($path);
                    continue;
                }

                if ($entry === $expectedUnityFile) {
                    continue;
                }

                if (!@unlink($path) && file_exists($path)) {
                    throw new \RuntimeException(sprintf('Could not remove stale Windows unity bucket file: %s', $path));
                }
            }
        }

        foreach ($context->windowsSourceBuckets() as $bucketDir => $files) {
            $targetDir = $context->outputDir . '/' . $bucketDir;
            $this->ensureDirectory($targetDir);
            foreach ($files as $filename) {
                $sourcePath = $classesDir . '/' . $filename;
                if (!is_file($sourcePath)) {
                    throw new \RuntimeException(sprintf('Expected generated source file not found for Windows unity bucket: %s', $sourcePath));
                }
            }

            $unityFilename = $unitySources[$bucketDir] ?? null;
            if (!is_string($unityFilename) || $unityFilename === '') {
                throw new \RuntimeException(sprintf('Missing Windows unity source filename for bucket: %s', $bucketDir));
            }

            $lines = [
                '/**',
                ' * Auto-generated by phpqt-builder -- DO NOT EDIT.',
                ' *',
                ' * Windows unity compilation bucket to keep linker response files below',
                ' * the MSVC per-line limit when many Qt wrappers are generated.',
                ' */',
                '',
            ];

            foreach ($files as $filename) {
                $lines[] = sprintf('#include "../classes/%s"', $filename);
            }

            $contents = implode("\n", $lines) . "\n";
            $targetPath = $targetDir . '/' . $unityFilename;
            $writer->write($targetPath, $contents);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            if (!@unlink($path) && file_exists($path)) {
                throw new \RuntimeException(sprintf('Could not remove file: %s', $path));
            }
        }

        if (!@rmdir($directory) && is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not remove directory: %s', $directory));
        }
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
        bool $useCcache,
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
                $bootstrapResult = $this->bootstrapper->bootstrap($context, $jobs, $useCcache, function (array $event) use ($output): void {
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
            $payload[$candidate->identityKey()] = ModuleNamespace::forQualifiedCppClass(
                $candidate->module,
                $candidate->qualifiedClassName ?? $candidate->className,
            );
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
            if (!isset($generatedSet[$candidate->identityKey()])) {
                continue;
            }

            $namespaces[$candidate->identityKey()] = ModuleNamespace::forQualifiedCppClass(
                $candidate->module,
                $candidate->qualifiedClassName ?? $candidate->className,
            );
        }

        return $namespaces;
    }

    /**
     * @param array<string, PhpClass> $generatedPhpClasses
     * @return array<string, string>
     */
    private function generatedClassIds(array $generatedPhpClasses): array
    {
        $ids = [];
        foreach ($generatedPhpClasses as $classKey => $phpClass) {
            $ids[$classKey] = $phpClass->resolvedGenerationId();
        }

        return $ids;
    }

    /**
     * @param list<HeaderCandidate> $candidates
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @return array<string, list<string>>
     */
    private function generatedCandidateDependencyMap(array $candidates, array $preparedClassDataByClass): array
    {
        $candidateKeys = array_map(
            static fn(HeaderCandidate $candidate): string => $candidate->identityKey(),
            $candidates,
        );
        $candidateSet = array_fill_keys($candidateKeys, true);

        /** @var array<string, list<string>> $shortNameIndex */
        $shortNameIndex = [];
        foreach ($candidateKeys as $candidateKey) {
            $shortName = CppName::unqualify($candidateKey);
            $shortNameIndex[$shortName] ??= [];
            $shortNameIndex[$shortName][] = $candidateKey;
        }

        /** @var array<string, list<string>> $dependencyMap */
        $dependencyMap = [];
        foreach ($candidateKeys as $candidateKey) {
            $classData = $preparedClassDataByClass[$candidateKey] ?? null;
            if (!is_array($classData)) {
                $dependencyMap[$candidateKey] = [];
                continue;
            }

            $dependencies = $this->generatedExtractCandidateDependencies(
                $candidateKey,
                $classData,
                $candidateSet,
                $shortNameIndex,
            );
            sort($dependencies);
            $dependencyMap[$candidateKey] = $dependencies;
        }

        return $dependencyMap;
    }

    /**
     * @param array<string, mixed> $classData
     * @param array<string, bool> $candidateSet
     * @param array<string, list<string>> $shortNameIndex
     * @return list<string>
     */
    private function generatedExtractCandidateDependencies(
        string $candidateKey,
        array $classData,
        array $candidateSet,
        array $shortNameIndex,
    ): array {
        $dependencies = [];
        $typeHints = [];

        foreach ((array) ($classData['bases'] ?? []) as $baseType) {
            if (is_string($baseType) && trim($baseType) !== '') {
                $typeHints[] = $baseType;
            }
        }

        foreach ((array) ($classData['methods'] ?? []) as $method) {
            if (!is_array($method)) {
                continue;
            }

            $returnType = is_string($method['return_type'] ?? null)
                ? trim((string) $method['return_type'])
                : '';
            if ($returnType === '' && is_string($method['type'] ?? null)) {
                $returnType = trim((string) $method['type']);
            }
            if ($returnType !== '') {
                $typeHints[] = $returnType;
            }

            foreach ((array) ($method['parameters'] ?? []) as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }

                $parameterType = is_string($parameter['type'] ?? null) ? trim((string) $parameter['type']) : '';
                if ($parameterType !== '') {
                    $typeHints[] = $parameterType;
                }
            }
        }

        foreach ((array) ($classData['properties'] ?? []) as $property) {
            if (!is_array($property)) {
                continue;
            }

            $propertyType = is_string($property['type'] ?? null) ? trim((string) $property['type']) : '';
            if ($propertyType !== '') {
                $typeHints[] = $propertyType;
            }
        }

        foreach ($typeHints as $typeHint) {
            foreach ($this->generatedTypeIdentifierHints($typeHint) as $identifier) {
                if (isset($candidateSet[$identifier])) {
                    $dependencies[$identifier] = true;
                }

                foreach (($shortNameIndex[$identifier] ?? []) as $resolvedKey) {
                    $dependencies[$resolvedKey] = true;
                }

                if (str_contains($identifier, '::')) {
                    $shortName = CppName::unqualify($identifier);
                    foreach (($shortNameIndex[$shortName] ?? []) as $resolvedKey) {
                        $dependencies[$resolvedKey] = true;
                    }
                }
            }
        }

        unset($dependencies[$candidateKey]);

        return array_keys($dependencies);
    }

    /**
     * @return list<string>
     */
    private function generatedTypeIdentifierHints(string $type): array
    {
        $matchCount = preg_match_all(
            '/\b[A-Za-z_][A-Za-z0-9_]*(?:::[A-Za-z_][A-Za-z0-9_]*)*\b/',
            $type,
            $matches,
        );
        if (!is_int($matchCount) || $matchCount === 0) {
            return [];
        }

        $hints = [];
        foreach ((array) ($matches[0] ?? []) as $token) {
            if (!is_string($token)) {
                continue;
            }

            $token = trim($token);
            if ($token === '' || in_array($token, ['const', 'volatile', 'unsigned', 'signed', 'short', 'long'], true)) {
                continue;
            }
            if (in_array($token, ['void', 'bool', 'char', 'int', 'float', 'double', 'qreal', 'size_t'], true)) {
                continue;
            }

            $hints[$token] = true;
        }

        return array_keys($hints);
    }

    /**
     * @param array<string, list<string>> $dependencyMap
     * @return array<string, list<string>>
     */
    private function generatedReverseDependencyMap(array $dependencyMap): array
    {
        /** @var array<string, array<string, bool>> $reverse */
        $reverse = [];

        foreach ($dependencyMap as $className => $dependencies) {
            foreach ($dependencies as $dependency) {
                $reverse[$dependency] ??= [];
                $reverse[$dependency][$className] = true;
            }
        }

        $resolved = [];
        foreach ($reverse as $dependency => $dependents) {
            $resolved[$dependency] = array_keys($dependents);
        }

        return $resolved;
    }

    /**
     * @param list<string> $removedCandidates
     * @param array<string, list<string>> $reverseDependencyMap
     * @param array<string, HeaderCandidate> $candidateMap
     * @return array<string, bool>
     */
    private function generatedImpactedDependents(array $removedCandidates, array $reverseDependencyMap, array $candidateMap): array
    {
        $dirty = [];
        foreach ($removedCandidates as $removedClass) {
            foreach (($reverseDependencyMap[$removedClass] ?? []) as $dependentClass) {
                if (!isset($candidateMap[$dependentClass])) {
                    continue;
                }
                $dirty[$dependentClass] = true;
            }
        }

        return $dirty;
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
        return ModuleNamespace::forQtModule($module);
    }

    private function moduleFromPhpNamespace(string $phpNamespace): string
    {
        $parts = array_values(array_filter(
            explode('\\', ltrim($phpNamespace, '\\')),
            static fn(string $part): bool => $part !== '',
        ));
        if (count($parts) < 2 || $parts[0] !== 'Qt') {
            return 'QtCore';
        }

        $suffix = $parts[1];
        if ($suffix === '') {
            return 'QtCore';
        }

        return str_starts_with($suffix, 'Qt') ? $suffix : ('Qt' . $suffix);
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
