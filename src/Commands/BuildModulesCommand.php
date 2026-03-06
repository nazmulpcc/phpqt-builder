<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\BuildAnalysisResult;
use QtBuilder\Build\BuildDirectoryCleaner;
use QtBuilder\Build\BuildExecutionRequest;
use QtBuilder\Build\BuildDiscoveryService;
use QtBuilder\Build\BuildLayout;
use QtBuilder\Build\BuildPipeline;
use QtBuilder\Build\Dependencies\ModuleDependencyResolver;
use QtBuilder\Build\Dependencies\ResolvedModuleGraph;
use QtBuilder\Build\Dependencies\StaticModuleDependencyResolver;
use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Build\ExtensionBootstrapper;
use QtBuilder\Build\ExtensionScaffolder;
use QtBuilder\Build\ModuleAbiManifest;
use QtBuilder\Build\ProcessExtensionBootstrapper;
use QtBuilder\Build\RuntimeManifest;
use QtBuilder\Build\RuntimeManifestBuilder;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\IO\FileWriteStats;
use QtBuilder\IO\SmartFileWriter;
use QtBuilder\Qt\QtInstallationResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('build:modules', 'Generate separate PHP extension source trees for Qt modules.')]
class BuildModulesCommand extends Command
{
    private readonly ExtensionBootstrapper $bootstrapper;
    private readonly ModuleDependencyResolver $dependencyResolver;

    public function __construct(
        private readonly SystemInformation $systemInformation,
        ?ExtensionBootstrapper $bootstrapper = null,
        private readonly BuildDiscoveryService $discoveryService = new BuildDiscoveryService(),
        private readonly BuildDirectoryCleaner $buildDirectoryCleaner = new BuildDirectoryCleaner(),
        ?ModuleDependencyResolver $dependencyResolver = null,
    ) {
        $this->bootstrapper = $bootstrapper ?? new ProcessExtensionBootstrapper($systemInformation);
        $this->dependencyResolver = $dependencyResolver ?? new StaticModuleDependencyResolver();

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('modules', InputArgument::OPTIONAL, 'Comma-separated Qt modules to build', 'QtCore')
            ->addOption('qt-path', null, InputOption::VALUE_REQUIRED, 'Path to the Qt installation root')
            ->addOption('ext-version', null, InputOption::VALUE_REQUIRED, 'Extension version', '0.1.0')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Base output directory; each module is written under <output>/<Module>', 'build')
            ->addOption('force', 'F', InputOption::VALUE_NONE, 'Clear each selected module build root before starting')
            ->addOption('no-build', null, InputOption::VALUE_NONE, 'Generate sources only and skip phpize/configure/make')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel discovery/bootstrap workers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $baseLayout = BuildLayout::fromCliOutput((string) $input->getOption('output'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }

        $requestedModules = $this->normalizeModules((string) $input->getArgument('modules'));
        try {
            $resolvedGraph = $this->dependencyResolver->resolve($requestedModules);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }

        $this->renderDependencyResolution($output, $resolvedGraph);

        $jobs = $this->resolveJobs($input->getOption('jobs'));
        $qtPath = $input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null;
        $extensionVersion = (string) $input->getOption('ext-version');
        $bootstrapEnabled = !(bool) $input->getOption('no-build');
        $pipeline = new BuildPipeline($this->bootstrapper, $this->discoveryService);
        $qtResolver = new QtInstallationResolver($this->systemInformation);

        if ((bool) $input->getOption('force')) {
            try {
                $this->clearSharedOutputs($baseLayout, $resolvedGraph->buildOrder, $output);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        }

        $analysisInstallation = $qtResolver->resolve($qtPath, $resolvedGraph->buildOrder);
        $analysis = $pipeline->analyze(
            new BuildExecutionRequest(
                installation: $analysisInstallation,
                buildRootDir: $baseLayout->buildRootDir,
                outputDir: $baseLayout->extensionDir(),
                modules: $resolvedGraph->buildOrder,
                requestedModules: $resolvedGraph->requestedModules,
                extensionName: 'qt',
                extensionVersion: $extensionVersion,
                jobs: $jobs,
                resolvedModuleGraph: $resolvedGraph,
                dependencySource: $resolvedGraph->dependencySource,
                reuseDiscoveryCache: true,
                bootstrapEnabled: false,
            ),
            $output,
        );

        if ($analysis->errors !== []) {
            return self::FAILURE;
        }

        $runtimeManifest = (new RuntimeManifestBuilder())->buildForModular(
            $resolvedGraph,
            $analysis,
            $extensionVersion,
            $analysisInstallation,
            $analysis->requiresSignalConnectionSupport,
        );

        $sharedRoot = $baseLayout->extensionDir();
        $sharedClassesDir = $sharedRoot . '/classes';
        $this->ensureDirectory($sharedClassesDir);

        $this->writeGlobalModuleGraph($baseLayout, $analysis, $resolvedGraph, $output);
        $runtimeManifestPath = $baseLayout->metadataDir() . '/runtime_manifest.json';
        $runtimeManifest->write($runtimeManifestPath);
        $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $runtimeManifestPath));

        $sharedWriter = new SmartFileWriter();
        $loadOrder = [];

        foreach ($resolvedGraph->buildOrder as $index => $module) {
            if ($index > 0) {
                $output->writeln('');
            }

            $moduleBuildRoot = $baseLayout->buildRootDir . '/' . $module;
            $moduleLayout = new BuildLayout($moduleBuildRoot);
            $extensionName = $resolvedGraph->extensionNameFor($module);
            $nativeModules = $this->nativeModulesForModule($resolvedGraph, $module);
            $installation = $qtResolver->resolve($qtPath, $nativeModules);
            $localClasses = $this->generatedClassesForModule($analysis, $module);

            $context = new ExtensionBuildContext(
                extensionName: $extensionName,
                extensionVersion: $extensionVersion,
                buildRootDir: $moduleLayout->buildRootDir,
                outputDir: $moduleLayout->extensionDir(),
                installation: $installation,
                modules: [$module],
                generatedClasses: $localClasses,
                generatedClassParents: $this->filterClassParents($analysis->generatedClassParents, $localClasses),
                generatedClassDependencies: $this->filterClassDependencies($analysis->generatedClassDependencies, $localClasses),
                includeSignalConnectionSupport: $module === 'QtCore' && $analysis->requiresSignalConnectionSupport,
                linkModules: $nativeModules,
                importIncludeRoots: [$sharedRoot, $sharedClassesDir],
                includeBuildInfoSupport: $module === 'QtCore',
                runtimeManifest: $runtimeManifest,
                currentQtModule: $module,
                buildMode: RuntimeManifest::MODE_MODULAR,
            );

            $output->writeln(sprintf('<info>Building %s as %s...</info>', $module, $extensionName));

            $scaffolder = new ExtensionScaffolder();
            $scaffolder->prepare($context);

            $emission = $pipeline->emitGeneratedClasses(
                $context,
                $localClasses,
                $analysis->generatedPhpClasses,
                $analysis->classNamespaces,
                $analysis->generatedClassHeaders,
                $output,
            );
            $scaffoldFiles = $scaffolder->finalize($context);
            $coreWriteStats = $scaffolder->lastWriteStats();
            $totalWriteStats = new FileWriteStats();
            $totalWriteStats->merge($emission['file_write_stats']);
            $totalWriteStats->merge($coreWriteStats);

            $this->publishSharedHeaders(
                $sharedWriter,
                $sharedClassesDir,
                $emission['classmap'],
                $context->includeSignalConnectionSupport,
                $context->includeBuildInfoSupport,
                $context->outputDir . '/classes',
            );

            $bootstrap = $pipeline->bootstrapExtension(
                $context,
                $jobs,
                $bootstrapEnabled,
                $totalWriteStats,
                $output,
            );

            $this->writeModuleArtifacts(
                module: $module,
                moduleLayout: $moduleLayout,
                context: $context,
                analysis: $analysis,
                classmap: $emission['classmap'],
                classWriteStats: $emission['file_write_stats'],
                coreWriteStats: $coreWriteStats,
                totalWriteStats: $totalWriteStats,
                scaffoldFiles: $scaffoldFiles,
                bootstrap: $bootstrap,
                jobs: $jobs,
                writeComparatorName: $scaffolder->writeComparatorName(),
                localClasses: $localClasses,
                dependencyModules: $resolvedGraph->dependencies[$module] ?? [],
                requestedModules: $resolvedGraph->requestedModules,
                expandedModules: $resolvedGraph->expandedModules(),
                dependencySource: $resolvedGraph->dependencySource,
                sharedIncludeDirs: [$sharedRoot, $sharedClassesDir],
                runtimeManifest: $runtimeManifest,
                runtimeManifestPath: $runtimeManifestPath,
                output: $output,
            );

            if ($bootstrap['error'] !== null) {
                return self::FAILURE;
            }

            $loadOrder[] = $extensionName;
        }

        $output->writeln('');
        $output->writeln(sprintf('<comment>Extension load order:</comment> %s', implode(', ', $loadOrder)));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function normalizeModules(string $modules): array
    {
        $parts = array_map('trim', explode(',', $modules));
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        return $parts === [] ? ['QtCore'] : array_values(array_unique($parts));
    }

    private function clearSharedOutputs(BuildLayout $baseLayout, array $modules, OutputInterface $output): void
    {
        foreach ([$baseLayout->extensionDir(), $baseLayout->metadataDir(), $baseLayout->classCacheDir()] as $path) {
            $this->buildDirectoryCleaner->clear($path);
            $output->writeln(sprintf('<comment>Cleared shared build path:</comment> %s', $path));
        }

        foreach ($modules as $module) {
            $moduleLayout = new BuildLayout($baseLayout->buildRootDir . '/' . $module);
            $this->buildDirectoryCleaner->clear($moduleLayout->buildRootDir);
            $output->writeln(sprintf('<comment>Cleared build root:</comment> %s', $moduleLayout->buildRootDir));
        }
    }

    /**
     * @return list<string>
     */
    private function generatedClassesForModule(BuildAnalysisResult $analysis, string $module): array
    {
        $classes = array_values(array_filter(
            $analysis->generatedClasses,
            static fn(string $className): bool => ($analysis->generatedClassModules[$className] ?? null) === $module,
        ));
        sort($classes);

        return $classes;
    }

    /**
     * @param array<string, string|null> $generatedClassParents
     * @param list<string> $localClasses
     * @return array<string, string|null>
     */
    private function filterClassParents(array $generatedClassParents, array $localClasses): array
    {
        $localSet = array_fill_keys($localClasses, true);
        $filtered = [];

        foreach ($generatedClassParents as $className => $parentClass) {
            if (!isset($localSet[$className])) {
                continue;
            }

            $filtered[$className] = $parentClass;
        }

        return $filtered;
    }

    /**
     * @param array<string, list<string>> $generatedClassDependencies
     * @param list<string> $localClasses
     * @return array<string, list<string>>
     */
    private function filterClassDependencies(array $generatedClassDependencies, array $localClasses): array
    {
        $localSet = array_fill_keys($localClasses, true);
        $filtered = [];

        foreach ($generatedClassDependencies as $className => $dependencies) {
            if (!isset($localSet[$className])) {
                continue;
            }

            $filtered[$className] = $dependencies;
        }

        return $filtered;
    }

    /**
     * @param list<array{class: string, header: string, files: list<string>}> $classmap
     */
    private function publishSharedHeaders(
        SmartFileWriter $writer,
        string $sharedClassesDir,
        array $classmap,
        bool $includeSignalConnectionSupport,
        bool $includeBuildInfoSupport,
        string $moduleClassesDir,
    ): void {
        foreach ($classmap as $entry) {
            foreach ($entry['files'] as $file) {
                if (!str_ends_with($file, '.h') || str_ends_with($file, '_arginfo.h')) {
                    continue;
                }

                $content = file_get_contents($file);
                if (!is_string($content)) {
                    throw new \RuntimeException(sprintf('Could not read generated header: %s', $file));
                }

                $writer->write($sharedClassesDir . '/' . basename($file), $content);
            }
        }

        if ($includeSignalConnectionSupport) {
            $signalHeader = $moduleClassesDir . '/qt_qmetaobjectconnection.h';
            if (!is_file($signalHeader)) {
                throw new \RuntimeException(sprintf('Generated signal support header not found: %s', $signalHeader));
            }

            $content = file_get_contents($signalHeader);
            if (!is_string($content)) {
                throw new \RuntimeException(sprintf('Could not read generated header: %s', $signalHeader));
            }

            $writer->write($sharedClassesDir . '/qt_qmetaobjectconnection.h', $content);
        }

        if (!$includeBuildInfoSupport) {
            return;
        }

        $buildInfoHeader = $moduleClassesDir . '/qt_buildinfo.h';
        if (!is_file($buildInfoHeader)) {
            throw new \RuntimeException(sprintf('Generated BuildInfo support header not found: %s', $buildInfoHeader));
        }

        $buildInfoContent = file_get_contents($buildInfoHeader);
        if (!is_string($buildInfoContent)) {
            throw new \RuntimeException(sprintf('Could not read generated header: %s', $buildInfoHeader));
        }

        $writer->write($sharedClassesDir . '/qt_buildinfo.h', $buildInfoContent);
    }

    /**
     * @param list<array{class: string, header: string, files: list<string>}> $classmap
     * @param list<string> $dependencyModules
     * @param list<string> $requestedModules
     * @param list<string> $expandedModules
     * @param list<string> $sharedIncludeDirs
     * @param array{result: \QtBuilder\Build\BootstrapResult|null, error: string|null, skipped: bool, disabled: bool} $bootstrap
     */
    private function writeModuleArtifacts(
        string $module,
        BuildLayout $moduleLayout,
        ExtensionBuildContext $context,
        BuildAnalysisResult $analysis,
        array $classmap,
        FileWriteStats $classWriteStats,
        FileWriteStats $coreWriteStats,
        FileWriteStats $totalWriteStats,
        array $scaffoldFiles,
        array $bootstrap,
        int $jobs,
        string $writeComparatorName,
        array $localClasses,
        array $dependencyModules,
        array $requestedModules,
        array $expandedModules,
        string $dependencySource,
        array $sharedIncludeDirs,
        RuntimeManifest $runtimeManifest,
        string $runtimeManifestPath,
        OutputInterface $output,
    ): void {
        $metadataDir = $moduleLayout->metadataDir();
        $this->ensureDirectory($metadataDir);

        $acceptedCandidates = array_values(array_filter(
            $analysis->acceptedCandidates,
            static fn($candidate): bool => $candidate instanceof \QtBuilder\Scanning\HeaderCandidate && $candidate->module === $module,
        ));
        $acceptedCandidatesPath = $metadataDir . '/accepted_candidates.json';
        file_put_contents($acceptedCandidatesPath, json_encode(array_map(
            static fn($candidate): array => [
                'module' => $candidate->module,
                'class' => $candidate->className,
                'public_header' => $candidate->publicHeader,
                'parse_header' => $candidate->parseHeader,
            ],
            $acceptedCandidates,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $acceptedCandidatesPath));

        $moduleSkippedClasses = array_values(array_filter(
            $analysis->skippedClasses,
            static fn(array $entry): bool => ($entry['module'] ?? null) === $module,
        ));

        $moduleClassSet = array_fill_keys($this->generatedClassesForModule($analysis, $module), true);
        foreach ($moduleSkippedClasses as $entry) {
            $className = is_string($entry['class'] ?? null) ? $entry['class'] : null;
            if ($className !== null && $className !== '') {
                $moduleClassSet[$className] = true;
            }
        }

        $moduleSkippedMethods = array_values(array_filter(
            $analysis->skippedMethods,
            static function (array $entry) use ($moduleClassSet, $analysis, $module): bool {
                $className = is_string($entry['class'] ?? null) ? $entry['class'] : null;
                if ($className === null || $className === '') {
                    return false;
                }

                if (isset($moduleClassSet[$className])) {
                    return true;
                }

                return ($analysis->generatedClassModules[$className] ?? null) === $module;
            },
        ));

        $moduleErrors = array_values(array_filter(
            $analysis->errors,
            static fn(array $entry): bool => ($entry['module'] ?? null) === $module,
        ));

        file_put_contents($metadataDir . '/classmap.json', json_encode($classmap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/skipped_classes.json', json_encode($moduleSkippedClasses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/skipped_methods.json', json_encode($moduleSkippedMethods, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');

        foreach ($scaffoldFiles as $file) {
            $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $file));
        }
        $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $metadataDir . '/classmap.json'));
        $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $metadataDir . '/skipped_classes.json'));
        $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $metadataDir . '/skipped_methods.json'));

        $summary = [
            'modules' => [$module],
            'requested_modules' => $requestedModules,
            'expanded_modules' => $expandedModules,
            'dependency_source' => $dependencySource,
            'candidate_classes' => count($localClasses) + count($moduleSkippedClasses),
            'generated_classes' => count($localClasses),
            'skipped_classes' => count($moduleSkippedClasses),
            'failed_classes' => count($moduleErrors),
            'jobs' => $jobs,
            'generation_passes' => $analysis->passes,
            'bootstrap' => $bootstrap['result']?->toArray(),
            'bootstrap_error' => $bootstrap['error'],
            'bootstrap_skipped' => $bootstrap['skipped'],
            'bootstrap_disabled' => $bootstrap['disabled'],
            'file_writes' => [
                'comparator' => $writeComparatorName,
                'class' => $classWriteStats->toArray(),
                'core' => $coreWriteStats->toArray(),
                'total' => $totalWriteStats->toArray(),
            ],
            'runtime_manifest' => $runtimeManifestPath,
        ];

        $abiManifest = new ModuleAbiManifest(
            module: $module,
            extensionName: $context->extensionName,
            buildRootDir: $moduleLayout->buildRootDir,
            outputDir: $context->outputDir,
            metadataDir: $metadataDir,
            acceptedCandidatesPath: $acceptedCandidatesPath,
            classCacheDir: dirname($moduleLayout->buildRootDir) . '/classes',
            includeDirs: [$context->outputDir, $context->outputDir . '/classes'],
            sharedIncludeDirs: $sharedIncludeDirs,
            dependencyModules: $dependencyModules,
            classes: $localClasses,
            classNamespaces: $this->exportedClassNamespacesForModule($analysis, $module),
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

        file_put_contents($metadataDir . '/build_summary.json', json_encode($summary + ['errors' => $moduleErrors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $metadataDir . '/module_abi.json'));
        $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $metadataDir . '/build_summary.json'));

        $output->writeln(sprintf(
            '<comment>File writes:</comment> %d written (%d created, %d updated), %d unchanged [comparator: %s]',
            $totalWriteStats->written(),
            $totalWriteStats->created(),
            $totalWriteStats->updated(),
            $totalWriteStats->unchanged(),
            $writeComparatorName,
        ));
        $output->writeln(sprintf(
            '<info>Generated %d class wrapper(s); %d class(es) skipped; %d error(s).</info>',
            count($localClasses),
            count($moduleSkippedClasses),
            count($moduleErrors),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function exportedClassNamespacesForModule(BuildAnalysisResult $analysis, string $module): array
    {
        $payload = [];
        foreach ($this->generatedClassesForModule($analysis, $module) as $className) {
            $namespace = $analysis->classNamespaces[$className] ?? null;
            if (!is_string($namespace) || $namespace === '') {
                continue;
            }

            $payload[$className] = $namespace;
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function nativeModulesForModule(ResolvedModuleGraph $graph, string $module): array
    {
        $required = [$module => true];
        $stack = [$module];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (!is_string($current) || $current === '') {
                continue;
            }

            foreach ($graph->dependencies[$current] ?? [] as $dependencyModule) {
                if (isset($required[$dependencyModule])) {
                    continue;
                }

                $required[$dependencyModule] = true;
                $stack[] = $dependencyModule;
            }
        }

        $ordered = array_values(array_filter(
            $graph->buildOrder,
            static fn(string $candidate): bool => isset($required[$candidate]),
        ));

        return array_values(array_unique($ordered));
    }

    private function writeGlobalModuleGraph(
        BuildLayout $baseLayout,
        BuildAnalysisResult $analysis,
        ResolvedModuleGraph $graph,
        OutputInterface $output,
    ): void {
        $path = $baseLayout->metadataDir() . '/module_graph.json';
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, json_encode([
            'modules' => $graph->buildOrder,
            'candidate_classes' => $analysis->candidateCount,
            'generated_classes' => count($analysis->generatedClasses),
            'skipped_classes' => count($analysis->skippedClasses),
            'generation_passes' => $analysis->passes,
        ] + $graph->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $path));
    }

    private function renderDependencyResolution(OutputInterface $output, ResolvedModuleGraph $graph): void
    {
        $output->writeln(sprintf(
            '<comment>Requested modules:</comment> %s',
            implode(', ', $graph->requestedModules),
        ));

        if ($graph->autoAddedModules() !== []) {
            $output->writeln(sprintf(
                '<comment>Auto-added dependency modules:</comment> %s',
                implode(', ', $graph->autoAddedModules()),
            ));
        }

        if ($graph->unmappedModules !== []) {
            $output->writeln(sprintf(
                '<comment>Manifest warning:</comment> %s %s no static dependency manifest entry; only the implicit QtCore dependency will be applied for %s.',
                implode(', ', $graph->unmappedModules),
                count($graph->unmappedModules) === 1 ? 'has' : 'have',
                count($graph->unmappedModules) === 1 ? 'that module' : 'those modules',
            ));
        }

        $output->writeln(sprintf(
            '<comment>Expanded modules:</comment> %s',
            implode(', ', $graph->expandedModules()),
        ));
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
}
