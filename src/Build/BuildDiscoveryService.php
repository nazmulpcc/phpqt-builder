<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Filtering\ClassExposurePolicy;
use QtBuilder\Qt\QtInstallation;
use QtBuilder\Scanning\HeaderCandidate;
use QtBuilder\Scanning\ModuleHeaderScanner;
use Symfony\Component\Console\Output\OutputInterface;

class BuildDiscoveryService
{
    public function __construct(
        private readonly GenerateWorkerPool $workerPool = new GenerateWorkerPool(__DIR__ . '/../..'),
        private readonly ModuleHeaderScanner $scanner = new ModuleHeaderScanner(),
        private readonly ClassExposurePolicy $classPolicy = new ClassExposurePolicy(),
    ) {}

    /**
     * @param list<string> $modules
     */
    public function discover(
        QtInstallation $installation,
        array $modules,
        string $outputDir,
        string $metadataDir,
        int $jobs,
        OutputInterface $output,
        string $extensionName = 'qt',
    ): BuildDiscoveryResult {
        @mkdir(dirname($metadataDir), 0755, true);
        @mkdir($metadataDir, 0755, true);

        [$acceptedCandidates, $initialSkippedClasses, $candidateCount] = $this->scanCandidates($installation, $modules);
        $viability = $this->resolveViableCandidates(
            $acceptedCandidates,
            $outputDir,
            $installation->includeRoots,
            $metadataDir,
            $jobs,
            $output,
            $extensionName,
        );

        return new BuildDiscoveryResult(
            acceptedCandidates: $viability['accepted_candidates'],
            skippedClasses: [...$initialSkippedClasses, ...$viability['skipped_classes']],
            allowedClasses: $viability['allowed_classes'],
            candidateCount: $candidateCount,
            passes: $viability['passes'],
            errors: $viability['errors'],
        );
    }

    /**
     * @param list<string> $modules
     */
    public function writeCache(string $metadataDir, array $modules, string $qtRootPath, BuildDiscoveryResult $result): void
    {
        $payload = [
            'modules' => array_values($modules),
            'qt_path' => $qtRootPath,
            'candidate_count' => $result->candidateCount,
            'accepted_candidates' => array_map(
                static fn(HeaderCandidate $candidate): array => [
                    'module' => $candidate->module,
                    'class' => $candidate->className,
                    'public_header' => $candidate->publicHeader,
                    'parse_header' => $candidate->parseHeader,
                ],
                $result->acceptedCandidates,
            ),
            'skipped_classes' => array_values($result->skippedClasses),
            'allowed_classes' => array_values($result->allowedClasses),
        ];

        $this->writeJsonFile($metadataDir . '/discovery_cache.json', $payload, '{}');
        $this->writeJsonFile($metadataDir . '/accepted_candidates.json', $payload['accepted_candidates'], '[]');
        $this->writeAllowedClassesManifest($metadataDir, $result->allowedClasses);
    }

    /**
     * @param list<string> $modules
     */
    public function loadCache(string $metadataDir, array $modules, string $qtRootPath): ?BuildDiscoveryResult
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

        return new BuildDiscoveryResult(
            acceptedCandidates: $acceptedCandidates,
            skippedClasses: $skippedClasses,
            allowedClasses: $allowedClasses,
            candidateCount: (int) ($decoded['candidate_count'] ?? count($acceptedCandidates) + count($skippedClasses)),
        );
    }

    /**
     * @param list<string> $allowedClasses
     */
    public function writeAllowedClassesManifest(string $metadataDir, array $allowedClasses): string
    {
        $manifestPath = $metadataDir . '/allowed_classes.json';
        $realMetadataDir = realpath($metadataDir);
        if ($realMetadataDir !== false) {
            $manifestPath = $realMetadataDir . '/allowed_classes.json';
        }

        $this->writeJsonFile($manifestPath, array_values($allowedClasses), '[]');

        return $manifestPath;
    }

    /**
     * @param list<string> $modules
     * @return array{0: list<HeaderCandidate>, 1: list<array<string, string|null>>, 2: int}
     */
    private function scanCandidates(QtInstallation $installation, array $modules): array
    {
        $acceptedCandidates = [];
        $skippedClasses = [];
        $candidateCount = 0;

        foreach ($modules as $module) {
            foreach ($this->scanner->scan($installation, $module) as $candidate) {
                $candidateCount++;
                $decision = $this->classPolicy->decideCandidate($candidate);
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
        string $extensionName,
    ): array {
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

                $results = $this->workerPool->run(
                    $this->buildProbeTasks(
                        array_values($viableCandidates),
                        $outputDir,
                        $includePaths,
                        $allowedClassesFile = $workerAllowedClassesFile,
                        $extensionName,
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
        string $extensionName,
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
                workerMode: 'probe',
            );
        }

        return $tasks;
    }

    private function namespaceForModule(string $module): string
    {
        return 'Qt\\' . preg_replace('/^Qt/', '', $module);
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
