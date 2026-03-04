<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Filtering\ClassExposurePolicy;
use QtBuilder\Qt\QtInstallation;
use QtBuilder\Scanning\HeaderCandidate;
use QtBuilder\Scanning\ModuleHeaderScanner;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class BuildDiscoveryService
{
    private const CLASS_CACHE_SCHEMA_VERSION = 2;

    public function __construct(
        private readonly GenerateWorkerPool $workerPool = new GenerateWorkerPool(__DIR__ . '/../..'),
        private readonly ModuleHeaderScanner $scanner = new ModuleHeaderScanner(),
        private readonly ClassExposurePolicy $classPolicy = new ClassExposurePolicy(),
        private readonly ClassGenerationService $generationService = new ClassGenerationService(),
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
        $this->ensureDirectory(dirname($metadataDir));
        $this->ensureDirectory($metadataDir);
        $this->ensureDirectory($this->classCacheDir($metadataDir));

        [$acceptedCandidates, $initialSkippedClasses, $candidateCount] = $this->scanCandidates($installation, $modules);
        $classStructures = $this->prepareClassStructures(
            $acceptedCandidates,
            $outputDir,
            $installation->includeRoots,
            $metadataDir,
            $jobs,
            $output,
            $extensionName,
        );

        if ($classStructures['errors'] !== []) {
            return new BuildDiscoveryResult(
                acceptedCandidates: [],
                skippedClasses: [...$initialSkippedClasses, ...$classStructures['skipped_classes']],
                allowedClasses: [],
                candidateCount: $candidateCount,
                errors: $classStructures['errors'],
            );
        }

        $viability = $this->resolveViableCandidates(
            $classStructures['accepted_candidates'],
            $classStructures['prepared_class_data'],
            $output,
        );

        return new BuildDiscoveryResult(
            acceptedCandidates: $viability['accepted_candidates'],
            skippedClasses: [...$initialSkippedClasses, ...$classStructures['skipped_classes'], ...$viability['skipped_classes']],
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
     * @param list<HeaderCandidate> $acceptedCandidates
     */
    public function writeClassHeadersManifest(
        string $metadataDir,
        array $acceptedCandidates,
        string $filename = 'class_headers.json',
    ): string {
        $payload = [];
        foreach ($acceptedCandidates as $candidate) {
            $payload[$candidate->className] = $candidate->parseHeader;
        }

        $manifestPath = $metadataDir . '/' . $filename;
        $this->writeJsonFile($manifestPath, $payload, '{}');

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
     *   prepared_class_data: array<string, array<string, mixed>>,
     *   skipped_classes: list<array<string, string|null>>,
     *   errors: list<array<string, string|null>>
     * }
     */
    public function prepareClassStructures(
        array $acceptedCandidates,
        string $outputDir,
        array $includePaths,
        string $metadataDir,
        int $jobs,
        OutputInterface $output,
        string $extensionName,
    ): array {
        $preparedCandidates = [];
        $preparedClassDataByClass = [];
        /** @var array<string, array{class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass */
        $skippedByClass = [];
        /** @var array<string, array{class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass */
        $errorsByClass = [];
        $cacheHits = 0;
        $cacheMisses = [];
        $cacheMissesByClass = [];

        foreach ($acceptedCandidates as $candidate) {
            $cachedPayload = $this->readClassStructureCache($metadataDir, $candidate, $includePaths);
            if ($cachedPayload === null) {
                $cacheMisses[] = $candidate;
                $cacheMissesByClass[$candidate->className] = $candidate;
                continue;
            }

            $cacheHits++;
            $this->recordClassStructurePayload(
                $candidate,
                $cachedPayload,
                $preparedCandidates,
                $preparedClassDataByClass,
                $skippedByClass,
                $errorsByClass,
            );
        }

        $output->writeln(sprintf(
            '<comment>Class structure cache:</comment> %d hit(s), %d miss(es).',
            $cacheHits,
            count($cacheMisses),
        ));

        if ($cacheMisses !== []) {
            $output->writeln(sprintf(
                '<info>Building cached class structures with %d parallel worker(s)...</info>',
                $jobs,
            ));

            $progressBar = $this->createProgressBar($output, count($cacheMisses), 'qt_class_cache', 'Class cache');
            $progressBar?->start();

            $results = $this->workerPool->run(
                $this->buildFactsTasks($cacheMisses, $outputDir, $includePaths, $extensionName),
                $jobs,
                static function (int $completed, int $total, GenerateResult $result) use ($progressBar): void {
                    if ($progressBar === null) {
                        return;
                    }

                    $progressBar->setProgress($completed);
                },
            );

            if ($progressBar !== null) {
                $progressBar->finish();
                $output->write(PHP_EOL);
            }

            foreach ($results as $result) {
                $candidate = $cacheMissesByClass[$result->className] ?? null;
                if ($candidate === null) {
                    continue;
                }

                if ($result->status === 'error') {
                    $errorsByClass[$result->className] = [
                        'class' => $result->className,
                        'header' => $result->headerPath,
                        'reason_code' => $result->reasonCode,
                        'reason_message' => $result->reasonMessage,
                    ];
                    continue;
                }

                $payload = [
                    'status' => $result->status,
                    'class' => $result->className,
                    'header' => $result->headerPath,
                    'class_data' => $result->classData,
                    'reason_code' => $result->reasonCode,
                    'reason_message' => $result->reasonMessage,
                ];

                $this->writeClassStructureCache($metadataDir, $candidate, $includePaths, $payload);
                $this->recordClassStructurePayload(
                    $candidate,
                    $payload,
                    $preparedCandidates,
                    $preparedClassDataByClass,
                    $skippedByClass,
                    $errorsByClass,
                );
            }
        }

        ksort($preparedCandidates);
        ksort($preparedClassDataByClass);

        return [
            'accepted_candidates' => array_values($preparedCandidates),
            'prepared_class_data' => $preparedClassDataByClass,
            'skipped_classes' => array_values($skippedByClass),
            'errors' => array_values($errorsByClass),
        ];
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
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
        array $preparedClassDataByClass,
        OutputInterface $output,
    ): array {
        if ($acceptedCandidates === []) {
            return [
                'accepted_candidates' => [],
                'skipped_classes' => [],
                'allowed_classes' => [],
                'passes' => 0,
                'errors' => [],
            ];
        }

        $viableCandidates = [];
        foreach ($acceptedCandidates as $candidate) {
            $viableCandidates[$candidate->className] = $candidate;
        }

        /** @var array<string, array{class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass */
        $skippedByClass = [];
        /** @var array<string, array{class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass */
        $errorsByClass = [];
        $passes = 0;

        do {
            $passes++;
            $allowedClasses = array_keys($viableCandidates);
            sort($allowedClasses);

            if ($passes > 1) {
                $output->writeln(sprintf(
                    '<comment>Rechecking discovery dependencies (pass %d, %d class(es)).</comment>',
                    $passes,
                    count($viableCandidates),
                ));
            }

            $progressBar = $this->createProgressBar(
                $output,
                count($viableCandidates),
                'qt_discovery',
                'Discovery pass ' . $passes,
            );
            $progressBar?->start();

            $nextViableCandidates = [];
            foreach ($viableCandidates as $className => $candidate) {
                $classData = $preparedClassDataByClass[$className] ?? null;
                if (!is_array($classData)) {
                    $errorsByClass[$className] = [
                        'class' => $className,
                        'header' => $candidate->parseHeader,
                        'reason_code' => 'missing_class_data',
                        'reason_message' => 'Prepared class data is missing from the class cache.',
                    ];
                    $progressBar?->advance();
                    continue;
                }

                $result = $this->generationService->generateFromPreparedData(
                    $classData,
                    $candidate->parseHeader,
                    $allowedClasses,
                    $preparedClassDataByClass,
                );

                if ($result->status === 'ok') {
                    $nextViableCandidates[$className] = $candidate;
                    unset($skippedByClass[$className], $errorsByClass[$className]);
                    $progressBar?->advance();
                    continue;
                }

                $skippedByClass[$className] = [
                    'class' => $result->className,
                    'header' => $result->headerPath,
                    'reason_code' => $result->reasonCode,
                    'reason_message' => $result->reasonMessage,
                ];
                unset($errorsByClass[$className]);
                $progressBar?->advance();
            }

            if ($progressBar !== null) {
                $progressBar->finish();
                $output->write(PHP_EOL);
            }

            $changed = array_keys($nextViableCandidates) !== array_keys($viableCandidates);
            $viableCandidates = $nextViableCandidates;
        } while ($changed && $errorsByClass === [] && $viableCandidates !== []);

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

    private function createProgressBar(OutputInterface $output, int $total, string $formatName, string $label): ?ProgressBar
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

    /**
     * @param list<HeaderCandidate> $candidates
     * @param list<string> $includePaths
     * @return list<GenerateTask>
     */
    private function buildFactsTasks(
        array $candidates,
        string $outputDir,
        array $includePaths,
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
                workerMode: 'facts',
            );
        }

        return $tasks;
    }

    private function namespaceForModule(string $module): string
    {
        return 'Qt\\' . preg_replace('/^Qt/', '', $module);
    }

    private function classCacheDir(string $metadataDir): string
    {
        $realMetadataDir = realpath($metadataDir);
        $baseDir = $realMetadataDir !== false ? dirname($realMetadataDir) : dirname($metadataDir);

        return $baseDir . '/classes';
    }

    private function classCachePath(string $metadataDir, HeaderCandidate $candidate): string
    {
        $safeClassName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $candidate->className) ?? $candidate->className;

        return $this->classCacheDir($metadataDir) . '/' . $safeClassName . '.json';
    }

    /**
     * @param list<string> $includePaths
     * @return array<string, mixed>|null
     */
    private function readClassStructureCache(string $metadataDir, HeaderCandidate $candidate, array $includePaths): ?array
    {
        $path = $this->classCachePath($metadataDir, $candidate);
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }

        if (($decoded['schema_version'] ?? null) !== self::CLASS_CACHE_SCHEMA_VERSION) {
            return null;
        }

        $cacheKey = (string) ($decoded['cache_key'] ?? '');
        if ($cacheKey === '' || !hash_equals($this->classStructureCacheKey($candidate, $includePaths), $cacheKey)) {
            return null;
        }

        $currentMtime = @filemtime($candidate->parseHeader);
        $currentSize = @filesize($candidate->parseHeader);
        if (($decoded['parse_header_mtime'] ?? null) !== ($currentMtime !== false ? $currentMtime : null)) {
            return null;
        }

        if (($decoded['parse_header_size'] ?? null) !== ($currentSize !== false ? $currentSize : null)) {
            return null;
        }

        $payload = $decoded['payload'] ?? null;

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param list<string> $includePaths
     * @param array<string, mixed> $payload
     */
    private function writeClassStructureCache(string $metadataDir, HeaderCandidate $candidate, array $includePaths, array $payload): void
    {
        $currentMtime = @filemtime($candidate->parseHeader);
        $currentSize = @filesize($candidate->parseHeader);
        $record = [
            'schema_version' => self::CLASS_CACHE_SCHEMA_VERSION,
            'cache_key' => $this->classStructureCacheKey($candidate, $includePaths),
            'class' => $candidate->className,
            'module' => $candidate->module,
            'public_header' => $candidate->publicHeader,
            'parse_header' => $candidate->parseHeader,
            'parse_header_mtime' => $currentMtime !== false ? $currentMtime : null,
            'parse_header_size' => $currentSize !== false ? $currentSize : null,
            'payload' => $payload,
        ];

        $this->writeJsonFile($this->classCachePath($metadataDir, $candidate), $record, '{}');
    }

    /**
     * @param list<string> $includePaths
     */
    private function classStructureCacheKey(HeaderCandidate $candidate, array $includePaths): string
    {
        $encoded = json_encode([
            'schema_version' => self::CLASS_CACHE_SCHEMA_VERSION,
            'class' => $candidate->className,
            'module' => $candidate->module,
            'public_header' => $candidate->publicHeader,
            'parse_header' => $candidate->parseHeader,
            'include_paths' => array_values($includePaths),
        ], JSON_UNESCAPED_SLASHES);

        return sha1($encoded !== false ? $encoded : $candidate->className);
    }

    /**
     * @param array<string, HeaderCandidate> $preparedCandidates
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, array{class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass
     * @param array<string, array{class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass
     * @param array<string, mixed> $payload
     */
    private function recordClassStructurePayload(
        HeaderCandidate $candidate,
        array $payload,
        array &$preparedCandidates,
        array &$preparedClassDataByClass,
        array &$skippedByClass,
        array &$errorsByClass,
    ): void {
        $status = (string) ($payload['status'] ?? 'error');
        if ($status === 'ok' && is_array($payload['class_data'] ?? null)) {
            $preparedCandidates[$candidate->className] = $candidate;
            $preparedClassDataByClass[$candidate->className] = $payload['class_data'];
            unset($skippedByClass[$candidate->className], $errorsByClass[$candidate->className]);

            return;
        }

        if ($status === 'skipped') {
            $skippedByClass[$candidate->className] = [
                'class' => $candidate->className,
                'header' => $candidate->parseHeader,
                'reason_code' => is_string($payload['reason_code'] ?? null) ? $payload['reason_code'] : null,
                'reason_message' => is_string($payload['reason_message'] ?? null) ? $payload['reason_message'] : null,
            ];
            unset($errorsByClass[$candidate->className]);

            return;
        }

        $errorsByClass[$candidate->className] = [
            'class' => $candidate->className,
            'header' => $candidate->parseHeader,
            'reason_code' => 'class_structure_error',
            'reason_message' => 'Class structure facts could not be loaded.',
        ];
    }

    private function writeJsonFile(string $path, mixed $payload, string $fallback): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $contents = $encoded !== false ? $encoded : $fallback;
        $directory = dirname($path);
        $this->ensureDirectory($directory);

        $tempPath = tempnam($directory, 'tmp-');
        if ($tempPath === false) {
            file_put_contents($path, $contents);

            return;
        }

        file_put_contents($tempPath, $contents);
        rename($tempPath, $path);
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
