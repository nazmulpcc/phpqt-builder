<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Build\GenerateTask;
use QtBuilder\Build\GenerateWorkerPool;
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

#[AsCommand('build:discover', 'Scan Qt modules and write reusable build discovery metadata.')]
class BuildDiscoverCommand extends Command
{
    public function __construct(private readonly SystemInformation $systemInformation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('qt-path', null, InputOption::VALUE_REQUIRED, 'Path to the Qt installation root')
            ->addOption('modules', null, InputOption::VALUE_REQUIRED, 'Comma-separated Qt modules to scan', 'QtCore')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'build/ext')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel discovery workers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modules = $this->parseModules((string) $input->getOption('modules'));
        if ($modules !== ['QtCore']) {
            $output->writeln('<error>The first implementation only supports --modules=QtCore.</error>');

            return self::FAILURE;
        }

        $qtResolver = new QtInstallationResolver($this->systemInformation);
        $installation = $qtResolver->resolve($input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null, $modules);

        $outputDir = (string) $input->getOption('output');
        $jobs = $this->resolveJobs($input->getOption('jobs'));
        $context = new ExtensionBuildContext('qt', '0.1.0', $outputDir, $installation, $modules);
        $metadataDir = $context->metadataDir();

        @mkdir($context->buildRootDir(), 0755, true);
        @mkdir($metadataDir, 0755, true);

        [$acceptedCandidates, $initialSkippedClasses, $candidateCount] = $this->scanCandidates($installation, $modules);
        $output->writeln(sprintf(
            '<info>Scanning complete.</info> %d candidates queued, %d filtered before discovery.',
            count($acceptedCandidates),
            count($initialSkippedClasses),
        ));
        $output->writeln(sprintf('<info>Running %d parallel discovery worker(s)...</info>', $jobs));

        $viability = $this->resolveViableCandidates(
            $acceptedCandidates,
            $outputDir,
            $installation->includeRoots,
            $metadataDir,
            $jobs,
            $output,
        );

        if ($viability['errors'] !== []) {
            foreach ($viability['errors'] as $error) {
                $message = is_string($error['reason_message'] ?? null) ? $error['reason_message'] : 'Worker failed.';
                $output->writeln(sprintf('<error>%s</error>', $message));
            }

            return self::FAILURE;
        }

        $skippedClasses = [...$initialSkippedClasses, ...$viability['skipped_classes']];
        $acceptedCandidates = $viability['accepted_candidates'];
        $allowedClasses = $viability['allowed_classes'];

        $this->writeDiscoveryCache(
            $metadataDir,
            $modules,
            $installation->rootPath,
            $acceptedCandidates,
            $skippedClasses,
            $allowedClasses,
            $candidateCount,
        );
        $this->writeAllowedClassesManifest($metadataDir, $allowedClasses);

        foreach ([
            'discovery_cache.json',
            'accepted_candidates.json',
            'allowed_classes.json',
        ] as $filename) {
            $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $metadataDir . '/' . $filename));
        }

        $output->writeln(sprintf(
            '<info>Discovery cache ready.</info> %d viable class(es), %d skipped, %d pass(es).',
            count($acceptedCandidates),
            count($skippedClasses),
            $viability['passes'],
        ));

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

    /**
     * @param list<string> $modules
     * @return array{0: list<HeaderCandidate>, 1: list<array<string, string|null>>, 2: int}
     */
    private function scanCandidates(QtInstallation $installation, array $modules): array
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

        return [$acceptedCandidates, $skippedClasses, $candidateCount];
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<string> $includePaths
     * @return array{
     *   accepted_candidates: list<HeaderCandidate>,
     *   skipped_classes: list<array<string, string|null>>,
     *   allowed_classes: list<string>,
     *   passes: int,
     *   errors: list<array<string, string|null>>
     * }
     */
    private function resolveViableCandidates(
        array $acceptedCandidates,
        string $outputDir,
        array $includePaths,
        string $metadataDir,
        int $jobs,
        OutputInterface $output,
    ): array {
        $workerPool = new GenerateWorkerPool(dirname(__DIR__, 2));
        $viableCandidates = [];
        foreach ($acceptedCandidates as $candidate) {
            $viableCandidates[$candidate->className] = $candidate;
        }

        /** @var array<string, array{class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass */
        $skippedByClass = [];
        /** @var array<string, array{class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass */
        $errorsByClass = [];
        $passes = 0;

        $workerAllowedClassesFile = $this->workerAllowedClassesFile($metadataDir);

        try {
            do {
                $passes++;
                $allowedClasses = array_keys($viableCandidates);
                sort($allowedClasses);
                $this->writeJsonFile($workerAllowedClassesFile, $allowedClasses, '[]');

                if ($passes > 1) {
                    $output->writeln(sprintf(
                        '<comment>Rechecking discovery dependencies (pass %d, %d class(es)).</comment>',
                        $passes,
                        count($viableCandidates),
                    ));
                }

                $results = $workerPool->run(
                    $this->buildProbeTasks(
                        array_values($viableCandidates),
                        $outputDir,
                        $includePaths,
                        $workerAllowedClassesFile,
                    ),
                    $jobs,
                );

                $nextViableCandidates = [];
                foreach ($results as $result) {
                    if ($result->isOk()) {
                        $candidate = $viableCandidates[$result->className] ?? null;
                        if ($candidate !== null) {
                            $nextViableCandidates[$result->className] = $candidate;
                            unset($skippedByClass[$result->className], $errorsByClass[$result->className]);
                        }
                        continue;
                    }

                    if ($result->isSkipped()) {
                        $skippedByClass[$result->className] = [
                            'class' => $result->className,
                            'header' => $result->headerPath,
                            'reason_code' => $result->reasonCode,
                            'reason_message' => $result->reasonMessage,
                        ];
                        unset($errorsByClass[$result->className]);

                        continue;
                    }

                    $errorsByClass[$result->className] = [
                        'class' => $result->className,
                        'header' => $result->headerPath,
                        'reason_code' => $result->reasonCode,
                        'reason_message' => $result->reasonMessage,
                    ];
                }

                $changed = array_keys($nextViableCandidates) !== array_keys($viableCandidates);
                $viableCandidates = $nextViableCandidates;
            } while ($changed && $errorsByClass === [] && $viableCandidates !== []);
        } finally {
            @unlink($workerAllowedClassesFile);
        }

        $allowedClasses = array_keys($viableCandidates);
        sort($allowedClasses);

        return [
            'accepted_candidates' => array_values($viableCandidates),
            'skipped_classes' => array_values($skippedByClass),
            'allowed_classes' => $allowedClasses,
            'passes' => $passes,
            'errors' => array_values($errorsByClass),
        ];
    }

    /**
     * @param list<HeaderCandidate> $candidates
     * @param list<string> $includePaths
     * @return list<GenerateTask>
     */
    private function buildProbeTasks(
        array $candidates,
        string $outputDir,
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
                extensionName: 'qt',
                qtPath: null,
                includePaths: $includePaths,
                allowedClassesFile: $allowedClassesFile,
                workerMode: 'probe',
            );
        }

        return $tasks;
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

        $this->writeJsonFile($metadataDir . '/discovery_cache.json', $payload, '{}');
        $this->writeJsonFile($metadataDir . '/accepted_candidates.json', $payload['accepted_candidates'], '[]');
    }

    /**
     * @param list<string> $allowedClasses
     */
    private function writeAllowedClassesManifest(string $metadataDir, array $allowedClasses): void
    {
        $this->writeJsonFile($metadataDir . '/allowed_classes.json', array_values($allowedClasses), '[]');
    }

    private function workerAllowedClassesFile(string $metadataDir): string
    {
        $realMetadataDir = realpath($metadataDir);
        $baseDir = $realMetadataDir !== false ? $realMetadataDir : $metadataDir;

        return $baseDir . '/allowed_classes.worker.json';
    }

    private function writeJsonFile(string $path, mixed $payload, string $fallback): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $encoded !== false ? $encoded : $fallback);
    }
}
