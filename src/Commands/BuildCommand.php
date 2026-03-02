<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\BootstrapResult;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Build\ExtensionBootstrapper;
use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Build\ExtensionScaffolder;
use QtBuilder\Build\GenerateTask;
use QtBuilder\Build\GenerateWorkerPool;
use QtBuilder\Build\ProcessExtensionBootstrapper;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Filtering\ClassExposurePolicy;
use QtBuilder\Qt\QtInstallation;
use QtBuilder\Qt\QtInstallationResolver;
use QtBuilder\Scanning\HeaderCandidate;
use QtBuilder\Scanning\ModuleHeaderScanner;
use Symfony\Component\Console\Attribute\AsCommand;
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

        $cachedDiscovery = $this->loadDiscoveryCache($metadataDir, $modules, $installation->rootPath);
        if ($cachedDiscovery !== null) {
            $acceptedCandidates = $cachedDiscovery['accepted_candidates'];
            $skippedClasses = $cachedDiscovery['skipped_classes'];
            $allowedClasses = $cachedDiscovery['allowed_classes'];
            $candidateCount = $cachedDiscovery['candidate_count'];
            $this->renderCacheUsage($output, $metadataDir);
        } else {
            [$acceptedCandidates, $skippedClasses, $allowedClasses, $candidateCount] = $this->discoverCandidates($installation, $modules);
            $this->writeDiscoveryCache($metadataDir, $modules, $installation->rootPath, $acceptedCandidates, $skippedClasses, $allowedClasses, $candidateCount);
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
        $skippedClasses = $generation['skipped_classes'];
        $skippedMethods = $generation['skipped_methods'];
        $errors = $generation['errors'];
        $classmap = $generation['classmap'];

        $this->writeDiscoveryCache(
            $metadataDir,
            $modules,
            $installation->rootPath,
            $acceptedCandidates,
            $skippedClasses,
            $generatedClasses,
            $candidateCount,
        );
        $this->writeAllowedClassesManifest($metadataDir, $generatedClasses);

        $context = $context->withGeneratedClasses($generatedClasses);
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
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $initialSkippedClasses
     * @param list<string> $initialAllowedClasses
     * @param list<string> $includePaths
     * @return array{
     *   accepted_candidates: list<HeaderCandidate>,
     *   generated_classes: list<string>,
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
        $passes = 0;

        do {
            $passes++;
            $allowedClassesFile = $this->writeAllowedClassesManifest($metadataDir, $currentAllowedClasses);

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
                ),
                $jobs,
            );

            $generatedClasses = [];
            $classmap = [];

            foreach ($results as $result) {
                unset($errorsByClass[$result->className]);

                if ($result->isOk()) {
                    $generatedClasses[] = $result->className;
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
            );
        }

        return $tasks;
    }

    /**
     * @param list<string> $allowedClasses
     */
    private function writeAllowedClassesManifest(string $metadataDir, array $allowedClasses): string
    {
        $manifestPath = $metadataDir . '/allowed_classes.json';
        $realMetadataDir = realpath($metadataDir);
        if ($realMetadataDir !== false) {
            $manifestPath = $realMetadataDir . '/allowed_classes.json';
        }

        file_put_contents(
            $manifestPath,
            json_encode(array_values($allowedClasses), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
        );

        return $manifestPath;
    }

    /**
     * @param list<string> $modules
     * @return array{0: list<HeaderCandidate>, 1: list<array<string, string|null>>, 2: list<string>, 3: int}
     */
    private function discoverCandidates(QtInstallation $installation, array $modules): array
    {
        $scanner = new ModuleHeaderScanner();
        $classPolicy = new ClassExposurePolicy();

        $acceptedCandidates = [];
        $skippedClasses = [];
        $candidateCount = 0;

        foreach ($modules as $module) {
            foreach ($scanner->scan($installation, $module) as $candidate) {
                $candidateCount++;
                $decision = $classPolicy->decideCandidate($candidate);
                if (!$decision->accepted) {
                    $skippedClasses[] = [
                        'class' => $candidate->className,
                        'header' => $candidate->parseHeader,
                        'reason_code' => $decision->reasonCode,
                        'reason_message' => $decision->reasonMessage,
                    ];
                    continue;
                }

                $acceptedCandidates[] = $candidate;
            }
        }

        [$acceptedCandidates, $preflightSkippedClasses, $allowedClasses] = $this->resolveViableCandidates(
            $acceptedCandidates,
            $installation->includeRoots,
        );
        array_push($skippedClasses, ...$preflightSkippedClasses);

        return [$acceptedCandidates, $skippedClasses, $allowedClasses, $candidateCount];
    }

    /**
     * @param list<string> $modules
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $skippedClasses
     * @param list<string> $allowedClasses
     */
    private function writeDiscoveryCache(
        string $metadataDir,
        array $modules,
        string $qtRootPath,
        array $acceptedCandidates,
        array $skippedClasses,
        array $allowedClasses,
        int $candidateCount,
    ): void {
        $payload = [
            'modules' => array_values($modules),
            'qt_path' => $qtRootPath,
            'candidate_count' => $candidateCount,
            'accepted_candidates' => array_map(
                static fn(HeaderCandidate $candidate): array => [
                    'module' => $candidate->module,
                    'class' => $candidate->className,
                    'public_header' => $candidate->publicHeader,
                    'parse_header' => $candidate->parseHeader,
                ],
                $acceptedCandidates,
            ),
            'skipped_classes' => array_values($skippedClasses),
            'allowed_classes' => array_values($allowedClasses),
        ];

        file_put_contents(
            $metadataDir . '/discovery_cache.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
        file_put_contents(
            $metadataDir . '/accepted_candidates.json',
            json_encode($payload['accepted_candidates'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
        );
    }

    /**
     * @param list<string> $modules
     * @return array{
     *   accepted_candidates: list<HeaderCandidate>,
     *   skipped_classes: list<array<string, string|null>>,
     *   allowed_classes: list<string>,
     *   candidate_count: int
     * }|null
     */
    private function loadDiscoveryCache(string $metadataDir, array $modules, string $qtRootPath): ?array
    {
        $cacheFile = $metadataDir . '/discovery_cache.json';
        if (!is_file($cacheFile)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($cacheFile), true);
        if (!is_array($decoded)) {
            return null;
        }

        $cachedModules = array_values(array_filter(
            array_map(static fn(mixed $value): string => is_string($value) ? $value : '', $decoded['modules'] ?? []),
            static fn(string $value): bool => $value !== '',
        ));
        $cachedQtPath = is_string($decoded['qt_path'] ?? null) ? $decoded['qt_path'] : '';

        if ($cachedModules !== array_values($modules) || $cachedQtPath !== $qtRootPath) {
            return null;
        }

        $acceptedCandidatePayload = $decoded['accepted_candidates'] ?? [];
        $acceptedCandidatesFile = $metadataDir . '/accepted_candidates.json';
        if (is_file($acceptedCandidatesFile)) {
            $fromFile = json_decode((string) file_get_contents($acceptedCandidatesFile), true);
            if (is_array($fromFile)) {
                $acceptedCandidatePayload = $fromFile;
            }
        }

        $acceptedCandidates = [];
        foreach ($acceptedCandidatePayload as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $module = is_string($candidate['module'] ?? null) ? $candidate['module'] : null;
            $className = is_string($candidate['class'] ?? null) ? $candidate['class'] : null;
            $publicHeader = is_string($candidate['public_header'] ?? null) ? $candidate['public_header'] : null;
            $parseHeader = is_string($candidate['parse_header'] ?? null) ? $candidate['parse_header'] : null;

            if ($module === null || $className === null || $publicHeader === null || $parseHeader === null) {
                continue;
            }

            $acceptedCandidates[] = new HeaderCandidate($module, $className, $publicHeader, $parseHeader);
        }

        $skippedClasses = array_values(array_filter(
            $decoded['skipped_classes'] ?? [],
            static fn(mixed $value): bool => is_array($value),
        ));

        $allowedClassPayload = $decoded['allowed_classes'] ?? [];
        $allowedClassesFile = $metadataDir . '/allowed_classes.json';
        if (is_file($allowedClassesFile)) {
            $fromFile = json_decode((string) file_get_contents($allowedClassesFile), true);
            if (is_array($fromFile)) {
                $allowedClassPayload = $fromFile;
            }
        }

        $allowedClasses = array_values(array_filter(
            array_map(static fn(mixed $value): string => is_string($value) ? $value : '', $allowedClassPayload),
            static fn(string $value): bool => $value !== '',
        ));

        if ($acceptedCandidates === [] || $allowedClasses === []) {
            return null;
        }

        return [
            'accepted_candidates' => $acceptedCandidates,
            'skipped_classes' => $skippedClasses,
            'allowed_classes' => $allowedClasses,
            'candidate_count' => (int) ($decoded['candidate_count'] ?? count($acceptedCandidates) + count($skippedClasses)),
        ];
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<string> $includePaths
     * @return array{0: list<HeaderCandidate>, 1: list<array<string, string|null>>, 2: list<string>}
     */
    private function resolveViableCandidates(array $acceptedCandidates, array $includePaths): array
    {
        $service = new ClassGenerationService();
        $viableCandidates = [];
        foreach ($acceptedCandidates as $candidate) {
            $viableCandidates[$candidate->className] = $candidate;
        }

        /** @var array<string, array{class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass */
        $skippedByClass = [];

        do {
            $allowedClasses = array_keys($viableCandidates);
            $nextViableCandidates = [];

            foreach ($viableCandidates as $className => $candidate) {
                $result = $service->generate($candidate->parseHeader, $candidate->className, $includePaths, $allowedClasses);
                if ($result->status === 'ok') {
                    $nextViableCandidates[$className] = $candidate;
                    unset($skippedByClass[$className]);
                    continue;
                }

                $skippedByClass[$className] = [
                    'class' => $candidate->className,
                    'header' => $candidate->parseHeader,
                    'reason_code' => $result->reasonCode,
                    'reason_message' => $result->reasonMessage,
                ];
            }

            $changed = array_keys($nextViableCandidates) !== array_keys($viableCandidates);
            $viableCandidates = $nextViableCandidates;
        } while ($changed && $viableCandidates !== []);

        $finalCandidates = array_values($viableCandidates);
        $allowedClasses = array_keys($viableCandidates);
        $skippedClasses = array_values($skippedByClass);

        return [$finalCandidates, $skippedClasses, $allowedClasses];
    }
}
