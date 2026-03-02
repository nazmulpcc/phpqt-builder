<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\BootstrapResult;
use QtBuilder\Build\BuildDiscoveryResult;
use QtBuilder\Build\BuildDiscoveryService;
use QtBuilder\Build\ExtensionBootstrapper;
use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Build\ExtensionScaffolder;
use QtBuilder\Build\GenerateTask;
use QtBuilder\Build\GenerateWorkerPool;
use QtBuilder\Build\ProcessExtensionBootstrapper;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Qt\QtInstallationResolver;
use QtBuilder\Scanning\HeaderCandidate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('build', 'Generate a PHP extension source tree from Qt modules.')]
class BuildCommand extends Command
{
    private readonly ExtensionBootstrapper $bootstrapper;

    public function __construct(
        private readonly SystemInformation $systemInformation,
        ?ExtensionBootstrapper $bootstrapper = null,
        private readonly BuildDiscoveryService $discoveryService = new BuildDiscoveryService(),
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
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'build/ext')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel generate workers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modules = $this->parseModules((string) $input->getOption('modules'));

        $qtResolver = new QtInstallationResolver($this->systemInformation);
        $installation = $qtResolver->resolve($input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null, $modules);

        $outputDir = (string) $input->getOption('output');
        $extensionName = (string) $input->getOption('name');
        $extensionVersion = (string) $input->getOption('ext-version');
        $jobs = $this->resolveJobs($input->getOption('jobs'));

        $context = new ExtensionBuildContext($extensionName, $extensionVersion, $outputDir, $installation, $modules);
        $scaffolder = new ExtensionScaffolder();
        $scaffolder->prepare($context);
        $metadataDir = $context->metadataDir();

        $cachedDiscovery = $this->discoveryService->loadCache($metadataDir, $modules, $installation->rootPath);
        if ($cachedDiscovery !== null) {
            $acceptedCandidates = $cachedDiscovery->acceptedCandidates;
            $skippedClasses = $cachedDiscovery->skippedClasses;
            $allowedClasses = $cachedDiscovery->allowedClasses;
            $candidateCount = $cachedDiscovery->candidateCount;
            $this->renderCacheUsage($output, $metadataDir);
        } else {
            $output->writeln('<comment>Discovery cache miss; invoking build:discover.</comment>');

            $discoverExitCode = $this->runDiscoverCommand(
                $input,
                $output,
                qtPath: $input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null,
                modules: $modules,
                outputDir: $outputDir,
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
        }

        $output->writeln(sprintf('<info>Scanning complete.</info> %d candidates queued, %d filtered before generation.', count($acceptedCandidates), count($skippedClasses)));
        $output->writeln(sprintf('<info>Running %d parallel generate worker(s)...</info>', $jobs));
        $generation = $this->stabilizeGeneratedCandidates(
            $acceptedCandidates,
            $skippedClasses,
            $allowedClasses,
            $outputDir,
            $extensionName,
            $installation->includeRoots,
            $metadataDir,
            $jobs,
            $output,
        );

        $acceptedCandidates = $generation['accepted_candidates'];
        $generatedClasses = $generation['generated_classes'];
        $generatedClassParents = $generation['generated_class_parents'];
        $skippedClasses = $generation['skipped_classes'];
        $skippedMethods = $generation['skipped_methods'];
        $errors = $generation['errors'];
        $classmap = $generation['classmap'];

        $this->discoveryService->writeCache(
            $metadataDir,
            $modules,
            $installation->rootPath,
            new BuildDiscoveryResult(
                acceptedCandidates: $acceptedCandidates,
                skippedClasses: $skippedClasses,
                allowedClasses: $generatedClasses,
                candidateCount: $candidateCount,
            ),
        );

        $context = $context->withGeneratedClasses(
            $generatedClasses,
            $generatedClassParents,
            $generation['generated_class_dependencies'],
        );
        $scaffoldFiles = $scaffolder->finalize($context);

        $bootstrapResult = null;
        $bootstrapError = null;

        if ($generatedClasses !== [] && $errors === []) {
            $output->writeln('<info>Bootstrapping extension build tree...</info>');

            try {
                $bootstrapResult = $this->bootstrapper->bootstrap($context, $jobs);
                $this->renderBootstrapResult($output, $bootstrapResult);
            } catch (\RuntimeException $e) {
                $bootstrapError = $e->getMessage();
                $output->writeln(sprintf('<error>%s</error>', $bootstrapError));
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
        ];

        file_put_contents($metadataDir . '/classmap.json', json_encode($classmap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/skipped_classes.json', json_encode($skippedClasses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/skipped_methods.json', json_encode($skippedMethods, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($metadataDir . '/build_summary.json', json_encode($summary + ['errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        foreach ($scaffoldFiles as $file) {
            $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $file));
        }
        $output->writeln(sprintf('<info>Generated %d class wrapper(s); %d class(es) skipped; %d error(s).</info>', count($generatedClasses), count($skippedClasses), count($errors)));

        if ($generatedClasses === [] || $errors !== [] || $bootstrapError !== null) {
            return self::FAILURE;
        }

        return self::SUCCESS;
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
     * @param list<string> $includePaths
     * @return array{
     *   accepted_candidates: list<HeaderCandidate>,
     *   generated_classes: list<string>,
     *   generated_class_parents: array<string, string|null>,
     *   generated_class_dependencies: array<string, list<string>>,
     *   skipped_classes: list<array<string, string|null>>,
     *   skipped_methods: list<array<string, string>>,
     *   errors: list<array<string, string|null>>,
     *   classmap: list<array{class: string, header: string, files: list<string>}>,
     *   passes: int
     * }
     */
    private function stabilizeGeneratedCandidates(
        array $acceptedCandidates,
        array $initialSkippedClasses,
        array $initialAllowedClasses,
        string $outputDir,
        string $extensionName,
        array $includePaths,
        string $metadataDir,
        int $jobs,
        OutputInterface $output,
    ): array {
        $workerPool = new GenerateWorkerPool(dirname(__DIR__, 2));
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
        $passes = 0;
        $classNamespacesFile = $this->writeClassNamespacesManifest($metadataDir, $acceptedCandidates);
        $classHeadersFile = $this->discoveryService->writeClassHeadersManifest($metadataDir, $acceptedCandidates);

        do {
            $passes++;
            $allowedClassesFile = $this->discoveryService->writeAllowedClassesManifest($metadataDir, $currentAllowedClasses);

            if ($passes > 1) {
                $output->writeln(sprintf(
                    '<comment>Regenerating against actual generated dependency set (pass %d, %d class(es)).</comment>',
                    $passes,
                    count($currentCandidates),
                ));
            }

            $results = $workerPool->run(
                $this->buildGenerateTasks(
                    $currentCandidates,
                    $outputDir,
                    $extensionName,
                    $includePaths,
                    $allowedClassesFile,
                    $classNamespacesFile,
                    $classHeadersFile,
                ),
                $jobs,
            );

            $generatedClasses = [];
            $classmap = [];

            foreach ($results as $result) {
                unset($errorsByClass[$result->className]);

                if ($result->isOk()) {
                    $generatedClasses[] = $result->className;
                    $generatedClassParents[$result->className] = $result->parentClassName;
                    $generatedClassDependencies[$result->className] = $result->classDependencies;
                    $classmap[] = [
                        'class' => $result->className,
                        'header' => $result->headerPath,
                        'files' => $result->generatedFiles,
                    ];
                    unset($skippedByClass[$result->className]);
                } elseif ($result->isSkipped()) {
                    $skippedByClass[$result->className] = [
                        'class' => $result->className,
                        'header' => $result->headerPath,
                        'reason_code' => $result->reasonCode,
                        'reason_message' => $result->reasonMessage,
                    ];
                } else {
                    $errorsByClass[$result->className] = [
                        'class' => $result->className,
                        'header' => $result->headerPath,
                        'reason_code' => $result->reasonCode,
                        'reason_message' => $result->reasonMessage,
                        'stderr' => $result->stderr,
                    ];
                }

                $skippedMethodsByClass[$result->className] = [];
                foreach ($result->skippedMethods as $skippedMethod) {
                    $skippedMethodsByClass[$result->className][] = ['class' => $result->className] + $skippedMethod;
                }
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

        $skippedMethods = [];
        foreach ($skippedMethodsByClass as $items) {
            foreach ($items as $item) {
                $skippedMethods[] = $item;
            }
        }

        return [
            'accepted_candidates' => $currentCandidates,
            'generated_classes' => $generatedClasses,
            'generated_class_parents' => $generatedClassParents,
            'generated_class_dependencies' => $generatedClassDependencies,
            'skipped_classes' => array_values($skippedByClass),
            'skipped_methods' => $skippedMethods,
            'errors' => array_values($errorsByClass),
            'classmap' => $classmap,
            'passes' => $passes,
        ];
    }

    /**
     * @param list<HeaderCandidate> $candidates
     * @param list<string> $includePaths
     * @return list<GenerateTask>
     */
    private function buildGenerateTasks(
        array $candidates,
        string $outputDir,
        string $extensionName,
        array $includePaths,
        string $allowedClassesFile,
        string $classNamespacesFile,
        string $classHeadersFile,
    ): array {
        $tasks = [];

        foreach ($candidates as $candidate) {
            $tasks[] = new GenerateTask(
                headerPath: $candidate->parseHeader,
                className: $candidate->className,
                module: $candidate->module,
                namespace: $this->namespaceForModule($candidate->module),
                outputDir: $outputDir,
                extensionName: $extensionName,
                qtPath: null,
                includePaths: $includePaths,
                allowedClassesFile: $allowedClassesFile,
                classNamespacesFile: $classNamespacesFile,
                classHeadersFile: $classHeadersFile,
            );
        }

        return $tasks;
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     */
    private function writeClassNamespacesManifest(string $metadataDir, array $acceptedCandidates): string
    {
        $payload = [];
        foreach ($acceptedCandidates as $candidate) {
            $payload[$candidate->className] = $this->namespaceForModule($candidate->module);
        }

        $manifestPath = $metadataDir . '/class_namespaces.json';
        file_put_contents(
            $manifestPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        );

        return $manifestPath;
    }

}
