<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Filtering\ClassExposurePolicy;
use QtBuilder\Qt\QtInstallation;
use QtBuilder\Scanning\HeaderCandidate;
use QtBuilder\Support\CppName;
use QtBuilder\Scanning\ModuleHeaderScanner;
use QtBuilder\Support\GeneratedTypeIdentity;
use QtBuilder\Support\ModuleNamespace;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class BuildDiscoveryService
{
    private const CLASS_CACHE_SCHEMA_VERSION = 14;
    private const DISCOVERY_CACHE_SCHEMA_VERSION = 1;

    public function __construct(
        private readonly GenerateWorkerPool $workerPool = new GenerateWorkerPool(__DIR__ . '/../..'),
        private readonly ModuleHeaderScanner $scanner = new ModuleHeaderScanner(),
        private readonly ClassExposurePolicy $classPolicy = new ClassExposurePolicy(),
        private readonly ClassGenerationService $generationService = new ClassGenerationService(),
        private readonly SupplementalClassCandidateResolver $supplementalResolver = new SupplementalClassCandidateResolver(),
        private readonly FixedPointEngine $fixedPointEngine = new FixedPointEngine(),
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
        ?ImportedModuleAbi $importedAbi = null,
        bool $resolveViability = true,
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
                moduleMethodTotals: [],
                moduleAcceptedMethodTotals: [],
                errors: $classStructures['errors'],
                preparedClassData: $classStructures['prepared_class_data'],
            );
        }

        $supplemental = $this->augmentWithSupplementalCandidates(
            $classStructures['accepted_candidates'],
            $classStructures['prepared_class_data'],
            $modules,
            $installation->includeRoots,
            $outputDir,
            $metadataDir,
            $jobs,
            $output,
            $extensionName,
            $importedAbi?->availableClasses ?? [],
        );

        if ($supplemental['errors'] !== []) {
            return new BuildDiscoveryResult(
                acceptedCandidates: [],
                skippedClasses: [...$initialSkippedClasses, ...$classStructures['skipped_classes'], ...$supplemental['skipped_classes']],
                allowedClasses: [],
                candidateCount: $candidateCount,
                moduleMethodTotals: [],
                moduleAcceptedMethodTotals: [],
                errors: $supplemental['errors'],
                supplementalCandidates: $supplemental['supplemental_candidates'],
                preparedClassData: $supplemental['prepared_class_data'],
            );
        }

        if (!$resolveViability) {
            $acceptedCandidatesWithoutViability = $supplemental['accepted_candidates'];
            $allowedClassesWithoutViability = array_values(array_unique(array_map(
                static fn(HeaderCandidate $candidate): string => $candidate->identityKey(),
                $acceptedCandidatesWithoutViability,
            )));
            sort($allowedClassesWithoutViability);

            return new BuildDiscoveryResult(
                acceptedCandidates: $acceptedCandidatesWithoutViability,
                skippedClasses: [...$initialSkippedClasses, ...$classStructures['skipped_classes'], ...$supplemental['skipped_classes']],
                allowedClasses: $allowedClassesWithoutViability,
                candidateCount: $candidateCount,
                moduleMethodTotals: $this->moduleMethodTotals(
                    $modules,
                    $acceptedCandidatesWithoutViability,
                    $supplemental['prepared_class_data'],
                ),
                moduleAcceptedMethodTotals: $this->moduleMethodTotals(
                    $modules,
                    $acceptedCandidatesWithoutViability,
                    $supplemental['prepared_class_data'],
                ),
                passes: 0,
                errors: [],
                supplementalCandidates: $supplemental['supplemental_candidates'],
                preparedClassData: $supplemental['prepared_class_data'],
            );
        }

        $viability = $this->resolveViableCandidates(
            $supplemental['accepted_candidates'],
            $importedAbi !== null
                ? $importedAbi->mergePreparedClassData($supplemental['prepared_class_data'])
                : $supplemental['prepared_class_data'],
            $output,
            $importedAbi?->availableClasses ?? [],
            $importedAbi !== null,
        );

        return new BuildDiscoveryResult(
            acceptedCandidates: $viability['accepted_candidates'],
            skippedClasses: [...$initialSkippedClasses, ...$classStructures['skipped_classes'], ...$supplemental['skipped_classes'], ...$viability['skipped_classes']],
            allowedClasses: $viability['allowed_classes'],
            candidateCount: $candidateCount,
            moduleMethodTotals: $this->moduleMethodTotals(
                $modules,
                $supplemental['accepted_candidates'],
                $supplemental['prepared_class_data'],
            ),
            moduleAcceptedMethodTotals: $this->moduleMethodTotals(
                $modules,
                $viability['accepted_candidates'],
                $supplemental['prepared_class_data'],
            ),
            passes: $viability['passes'],
            errors: $viability['errors'],
            supplementalCandidates: $supplemental['supplemental_candidates'],
            preparedClassData: $supplemental['prepared_class_data'],
        );
    }

    /**
     * @param list<string> $modules
     */
    public function writeCache(string $metadataDir, array $modules, string $qtRootPath, BuildDiscoveryResult $result): void
    {
        $payload = [
            'schema_version' => self::DISCOVERY_CACHE_SCHEMA_VERSION,
            'class_cache_schema_version' => self::CLASS_CACHE_SCHEMA_VERSION,
            'modules' => array_values($modules),
            'qt_path' => $qtRootPath,
            'candidate_count' => $result->candidateCount,
            'accepted_candidates' => array_map(
                static fn(HeaderCandidate $candidate): array => [
                    'module' => $candidate->module,
                    'class' => $candidate->className,
                    'qualified_name' => $candidate->qualifiedClassName,
                    'generation_id' => $candidate->resolvedGenerationId(),
                    'public_header' => $candidate->publicHeader,
                    'parse_header' => $candidate->parseHeader,
                ],
                $result->acceptedCandidates,
            ),
            'skipped_classes' => array_values($result->skippedClasses),
            'allowed_classes' => array_values($result->allowedClasses),
            'module_method_totals' => $result->moduleMethodTotals,
            'module_accepted_method_totals' => $result->moduleAcceptedMethodTotals,
            'supplemental_candidates' => array_values($result->supplementalCandidates),
        ];

        $this->writeJsonFile($metadataDir . '/discovery_cache.json', $payload, '{}');
        $this->writeJsonFile($metadataDir . '/accepted_candidates.json', $payload['accepted_candidates'], '[]');
        $this->writeAllowedClassesManifest($metadataDir, $result->allowedClasses);
        $this->writeJsonFile($metadataDir . '/supplemental_candidates.json', $payload['supplemental_candidates'], '[]');
    }

    /**
     * @param list<string> $modules
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $skippedClasses
     * @return list<array{module: string, accepted: int, skipped: int, total: int, percent: float}>
     */
    public function moduleAcceptance(array $modules, array $acceptedCandidates, array $skippedClasses): array
    {
        /** @var array<string, array{accepted: int, skipped: int}> $stats */
        $stats = [];
        foreach ($modules as $module) {
            $stats[$module] = ['accepted' => 0, 'skipped' => 0];
        }

        foreach ($acceptedCandidates as $candidate) {
            $stats[$candidate->module] ??= ['accepted' => 0, 'skipped' => 0];
            $stats[$candidate->module]['accepted']++;
        }

        foreach ($skippedClasses as $skippedClass) {
            $module = is_string($skippedClass['module'] ?? null) ? $skippedClass['module'] : null;
            if ($module === null || $module === '') {
                continue;
            }

            $stats[$module] ??= ['accepted' => 0, 'skipped' => 0];
            $stats[$module]['skipped']++;
        }

        $rows = [];
        foreach ($modules as $module) {
            $accepted = $stats[$module]['accepted'] ?? 0;
            $skipped = $stats[$module]['skipped'] ?? 0;
            $total = $accepted + $skipped;
            $rows[] = [
                'module' => $module,
                'accepted' => $accepted,
                'skipped' => $skipped,
                'total' => $total,
                'percent' => $total > 0 ? ($accepted / $total) * 100.0 : 0.0,
            ];
        }

        return $rows;
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

        if ((int) ($decoded['schema_version'] ?? 0) !== self::DISCOVERY_CACHE_SCHEMA_VERSION) {
            return null;
        }

        if ((int) ($decoded['class_cache_schema_version'] ?? 0) !== self::CLASS_CACHE_SCHEMA_VERSION) {
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
            $qualifiedName = is_string($candidate['qualified_name'] ?? null) ? $candidate['qualified_name'] : null;
            $generationId = is_string($candidate['generation_id'] ?? null) ? $candidate['generation_id'] : null;

            if ($module === null || $className === null || $publicHeader === null || $parseHeader === null) {
                continue;
            }

            $acceptedCandidates[] = new HeaderCandidate($module, $className, $publicHeader, $parseHeader, $qualifiedName, $generationId);
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

        $moduleMethodTotals = [];
        $moduleMethodTotalsPayload = $decoded['module_method_totals'] ?? [];
        if (is_array($moduleMethodTotalsPayload)) {
            foreach ($moduleMethodTotalsPayload as $module => $value) {
                if (!is_string($module)) {
                    continue;
                }

                $moduleMethodTotals[$module] = max(0, (int) $value);
            }
        }

        $moduleAcceptedMethodTotals = [];
        $moduleAcceptedMethodTotalsPayload = $decoded['module_accepted_method_totals'] ?? [];
        if (is_array($moduleAcceptedMethodTotalsPayload)) {
            foreach ($moduleAcceptedMethodTotalsPayload as $module => $value) {
                if (!is_string($module)) {
                    continue;
                }

                $moduleAcceptedMethodTotals[$module] = max(0, (int) $value);
            }
        }

        $supplementalCandidatePayload = $decoded['supplemental_candidates'] ?? [];
        $supplementalCandidatesFile = $metadataDir . '/supplemental_candidates.json';
        if (is_file($supplementalCandidatesFile)) {
            $fromFile = json_decode((string) file_get_contents($supplementalCandidatesFile), true);
            if (is_array($fromFile)) {
                $supplementalCandidatePayload = $fromFile;
            }
        }

        $supplementalCandidates = array_values(array_filter(
            $supplementalCandidatePayload,
            static fn(mixed $value): bool => is_array($value),
        ));

        return new BuildDiscoveryResult(
            acceptedCandidates: $acceptedCandidates,
            skippedClasses: $skippedClasses,
            allowedClasses: $allowedClasses,
            candidateCount: (int) ($decoded['candidate_count'] ?? count($acceptedCandidates) + count($skippedClasses)),
            moduleMethodTotals: $moduleMethodTotals,
            moduleAcceptedMethodTotals: $moduleAcceptedMethodTotals,
            supplementalCandidates: $supplementalCandidates,
        );
    }

    /**
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param list<string> $modules
     * @param list<string> $includePaths
     * @param list<string> $importedAvailableClasses
     * @return array{
     *   accepted_candidates: list<HeaderCandidate>,
     *   prepared_class_data: array<string, array<string, mixed>>,
     *   skipped_classes: list<array<string, string|null>>,
     *   errors: list<array<string, string|null>>,
     *   supplemental_candidates: list<array<string, string>>
     * }
     */
    public function augmentWithSupplementalCandidates(
        array $acceptedCandidates,
        array $preparedClassDataByClass,
        array $modules,
        array $includePaths,
        string $outputDir,
        string $metadataDir,
        int $jobs,
        OutputInterface $output,
        string $extensionName,
        array $importedAvailableClasses = [],
    ): array {
        /** @var array<string, HeaderCandidate> $candidateMap */
        $candidateMap = [];
        foreach ($acceptedCandidates as $candidate) {
            $candidateMap[$candidate->identityKey()] = $candidate;
        }

        /** @var array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass */
        $skippedByClass = [];
        /** @var array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass */
        $errorsByClass = [];
        /** @var array<string, SupplementalClassCandidate> $supplementalCandidates */
        $supplementalCandidates = [];
        $pass = 0;

        do {
            $pass++;
            $passStartedAt = microtime(true);
            $knownClassNames = array_merge(
                array_keys($candidateMap),
                array_keys($preparedClassDataByClass),
                array_values($importedAvailableClasses),
                array_keys($supplementalCandidates),
            );
            foreach ($candidateMap as $candidate) {
                $knownClassNames[] = $candidate->className;
                if ($candidate->qualifiedClassName !== null) {
                    $knownClassNames[] = $candidate->qualifiedClassName;
                }
            }
            $knownClasses = array_fill_keys(array_values(array_unique($knownClassNames)), true);

            /** @var array<string, SupplementalClassCandidate> $queuedThisPass */
            $queuedThisPass = [];
            $candidateLoopStartedAt = microtime(true);
            $candidateCount = count($candidateMap);
            $processedCandidates = 0;
            $progressBar = $this->createProgressBar(
                $output,
                $candidateCount,
                'qt_supplemental_discovery',
                'Supplemental discovery pass ' . $pass,
            );
            $progressBar?->start();
            foreach ($candidateMap as $className => $candidate) {
                $processedCandidates++;
                if ($this->debugTimingEnabled() && ($processedCandidates === 1 || $processedCandidates % 100 === 0 || $processedCandidates === $candidateCount)) {
                    $output->writeln(sprintf(
                        '  <comment>debug:</comment> supplemental.pass_%d progress %d/%d (%s)',
                        $pass,
                        $processedCandidates,
                        $candidateCount,
                        $candidate->identityKey(),
                    ));
                }
                $progressBar?->advance();
                $classData = $preparedClassDataByClass[$className] ?? null;
                if (!is_array($classData)) {
                    continue;
                }

                $allowedClasses = array_values(array_unique(array_merge(
                    array_keys($candidateMap),
                    array_values($importedAvailableClasses),
                )));
                sort($allowedClasses);

                $result = $this->generationService->generateFromPreparedData(
                    $classData,
                    $candidate->parseHeader,
                    $allowedClasses,
                    $preparedClassDataByClass,
                    $importedAvailableClasses !== [],
                );

                $missingClass = $this->supplementalMissingClass($result->reasonCode, $result->reasonMessage);
                if ($missingClass === null || isset($knownClasses[$missingClass])) {
                    continue;
                }

                $supplemental = $this->supplementalResolver->resolve(
                    $missingClass,
                    $candidate,
                    $modules,
                    $includePaths,
                    $knownClasses,
                    (string) ($result->reasonCode ?? 'missing_dependency'),
                );
                if ($supplemental === null) {
                    continue;
                }

                $queuedThisPass[$supplemental->candidate->identityKey()] = $supplemental;
                $knownClasses[$supplemental->candidate->identityKey()] = true;
            }
            if ($progressBar !== null) {
                $progressBar->finish();
                $output->write(PHP_EOL);
            }
            $this->renderDebugTiming(
                $output,
                sprintf('supplemental.pass_%d.generate_from_prepared_data_loop', $pass),
                microtime(true) - $candidateLoopStartedAt,
            );

            if ($queuedThisPass === []) {
                $this->renderDebugTiming(
                    $output,
                    sprintf('supplemental.pass_%d.total', $pass),
                    microtime(true) - $passStartedAt,
                );
                break;
            }

            $output->writeln(sprintf(
                '<comment>Supplemental class discovery:</comment> queued %d new candidate(s).',
                count($queuedThisPass),
            ));

            $prepareStartedAt = microtime(true);
            $prepared = $this->prepareClassStructures(
                array_values(array_map(
                    static fn(SupplementalClassCandidate $candidate): HeaderCandidate => $candidate->candidate,
                    $queuedThisPass,
                )),
                $outputDir,
                $includePaths,
                $metadataDir,
                $jobs,
                $output,
                $extensionName,
            );
            $this->renderDebugTiming(
                $output,
                sprintf('supplemental.pass_%d.prepare_class_structures', $pass),
                microtime(true) - $prepareStartedAt,
            );

            foreach ($prepared['accepted_candidates'] as $candidate) {
                $candidateMap[$candidate->identityKey()] = $candidate;
            }

            foreach ($prepared['prepared_class_data'] as $className => $classData) {
                $preparedClassDataByClass[$className] = $classData;
            }

            foreach ($prepared['skipped_classes'] as $entry) {
                $className = (string) ($entry['class'] ?? '');
                if ($className !== '') {
                    $skippedByClass[$className] = $entry;
                }
            }

            foreach ($prepared['errors'] as $entry) {
                $className = (string) ($entry['class'] ?? '');
                if ($className !== '') {
                    $errorsByClass[$className] = $entry;
                }
            }

            foreach ($queuedThisPass as $className => $candidate) {
                $supplementalCandidates[$candidate->identityKey()] = $candidate;
            }
            $this->renderDebugTiming(
                $output,
                sprintf('supplemental.pass_%d.total', $pass),
                microtime(true) - $passStartedAt,
            );
        } while ($errorsByClass === []);

        ksort($candidateMap);
        ksort($preparedClassDataByClass);
        ksort($supplementalCandidates);

        return [
            'accepted_candidates' => array_values($candidateMap),
            'prepared_class_data' => $preparedClassDataByClass,
            'skipped_classes' => array_values($skippedByClass),
            'errors' => array_values($errorsByClass),
            'supplemental_candidates' => array_values(array_map(
                static fn(SupplementalClassCandidate $candidate): array => $candidate->toArray(),
                $supplementalCandidates,
            )),
        ];
    }

    private function renderDebugTiming(OutputInterface $output, string $label, float $seconds): void
    {
        if (!$this->debugTimingEnabled()) {
            return;
        }

        $output->writeln(sprintf(
            '  <comment>debug:</comment> %s %s',
            $label,
            $this->formatDurationSeconds($seconds),
        ));
    }

    private function debugTimingEnabled(): bool
    {
        $value = getenv('QTB_DEBUG_TIMING');

        return is_string($value) && $value !== '' && $value !== '0';
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
            $payload[$candidate->identityKey()] = $candidate->parseHeader;
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
                        'module' => $candidate->module,
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
     *   errors: list<array<string, string|null>>,
     *   cache_hit_count: int,
     *   cache_miss_count: int
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
        /** @var array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass */
        $skippedByClass = [];
        /** @var array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass */
        $errorsByClass = [];
        $cacheHits = 0;
        $cacheMisses = [];
        $cacheMissesByClass = [];

        foreach ($acceptedCandidates as $candidate) {
            $cachedPayload = $this->readClassStructureCache($metadataDir, $candidate, $includePaths);
            if ($cachedPayload === null) {
                $cacheMisses[] = $candidate;
                $cacheMissesByClass[$candidate->identityKey()] = $candidate;
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

            $factsBatch = $this->buildFactsBatchTasks($cacheMisses, $outputDir, $includePaths, $extensionName, $metadataDir);
            $results = $this->workerPool->run(
                $factsBatch['tasks'],
                $jobs,
                static function (int $completed, int $total, GenerateResult $result) use ($progressBar): void {
                    if ($progressBar === null) {
                        return;
                    }

                    $progressBar->setProgress($completed);
                },
                $factsBatch['class_total'],
            );

            if ($progressBar !== null) {
                $progressBar->finish();
                $output->write(PHP_EOL);
            }

            $processedMisses = [];
            foreach ($results as $result) {
                $candidate = $result->candidateKey !== null
                    ? ($cacheMissesByClass[$result->candidateKey] ?? null)
                    : null;
                if ($candidate === null) {
                    foreach ($cacheMissesByClass as $cachedCandidate) {
                        if ($cachedCandidate->className === $result->className && $cachedCandidate->parseHeader === $result->headerPath) {
                            $candidate = $cachedCandidate;
                            break;
                        }
                    }
                }
                if ($candidate === null) {
                    continue;
                }
                $processedMisses[$candidate->identityKey()] = true;

                if ($result->status === 'error') {
                    $errorsByClass[$result->className] = [
                        'module' => $candidate->module,
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
                    'task_key' => $result->candidateKey,
                    'class_data' => $result->classData,
                    'referenced_nested_class_data' => $result->referencedNestedClassData,
                    'reason_code' => $result->reasonCode,
                    'reason_message' => $result->reasonMessage,
                ];

                $cacheCandidate = $this->cacheCandidateForPayload($candidate, $payload);
                $this->writeClassStructureCache($metadataDir, $cacheCandidate, $includePaths, $payload);
                $this->writeReferencedNestedClassStructureCaches($metadataDir, $candidate, $includePaths, $payload);
                $this->recordClassStructurePayload(
                    $candidate,
                    $payload,
                    $preparedCandidates,
                    $preparedClassDataByClass,
                    $skippedByClass,
                    $errorsByClass,
                );
            }

            foreach ($cacheMissesByClass as $identityKey => $candidate) {
                if (isset($processedMisses[$identityKey])) {
                    continue;
                }

                $errorsByClass[$identityKey] = [
                    'module' => $candidate->module,
                    'class' => $candidate->className,
                    'header' => $candidate->parseHeader,
                    'reason_code' => 'worker_result_missing',
                    'reason_message' => 'Class facts worker did not return a result for this candidate.',
                ];
            }
        }

        ksort($preparedCandidates);
        ksort($preparedClassDataByClass);

        return [
            'accepted_candidates' => array_values($preparedCandidates),
            'prepared_class_data' => $preparedClassDataByClass,
            'skipped_classes' => array_values($skippedByClass),
            'errors' => array_values($errorsByClass),
            'cache_hit_count' => $cacheHits,
            'cache_miss_count' => count($cacheMisses),
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
        array $importedAvailableClasses = [],
        bool $preferExternalDependencyReasons = false,
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
            $viableCandidates[$candidate->identityKey()] = $candidate;
        }

        /** @var array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass */
        $skippedByClass = [];
        /** @var array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass */
        $errorsByClass = [];
        $dependencyMap = $this->candidateDependencyMap($viableCandidates, $preparedClassDataByClass);
        $reverseDependencyMap = $this->reverseDependencyMap($dependencyMap);
        $dirtyCandidates = array_fill_keys(array_keys($viableCandidates), true);
        $passes = 0;

        while (true) {
            $dirtyKeys = array_values(array_filter(
                array_keys($dirtyCandidates),
                static fn(string $key): bool => isset($viableCandidates[$key]),
            ));
            sort($dirtyKeys);

            if ($dirtyKeys === []) {
                break;
            }
            $passes++;

            $allowedClasses = array_keys($viableCandidates);
            $allowedClasses = array_values(array_unique([...$allowedClasses, ...$importedAvailableClasses]));
            sort($allowedClasses);

            if ($passes > 1) {
                $output->writeln(sprintf(
                    '<comment>Rechecking discovery dependencies (pass %d, %d class(es)).</comment>',
                    $passes,
                    count($dirtyKeys),
                ));
            }

            $progressBar = $this->createProgressBar(
                $output,
                count($dirtyKeys),
                'qt_discovery',
                'Discovery pass ' . $passes,
            );
            $progressBar?->start();

            $removedCandidates = [];
            foreach ($dirtyKeys as $className) {
                $candidate = $viableCandidates[$className] ?? null;
                if (!$candidate instanceof HeaderCandidate) {
                    $progressBar?->advance();
                    continue;
                }

                $classData = $preparedClassDataByClass[$className] ?? null;
                if (!is_array($classData)) {
                    $errorsByClass[$className] = [
                        'module' => $candidate->module,
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
                    $preferExternalDependencyReasons,
                );

                if ($result->status === 'ok') {
                    unset($skippedByClass[$className], $errorsByClass[$className]);
                    $progressBar?->advance();
                    continue;
                }

                unset($viableCandidates[$className], $errorsByClass[$className]);
                $removedCandidates[] = $className;
                $skippedByClass[$className] = [
                    'module' => $candidate->module,
                    'class' => $result->className,
                    'header' => $result->headerPath,
                    'reason_code' => $result->reasonCode,
                    'reason_message' => $result->reasonMessage,
                ];
                $progressBar?->advance();
            }

            if ($progressBar !== null) {
                $progressBar->finish();
                $output->write(PHP_EOL);
            }

            if ($errorsByClass !== [] || $viableCandidates === [] || $removedCandidates === []) {
                break;
            }

            $dirtyCandidates = $this->impactedDependents($removedCandidates, $reverseDependencyMap, $viableCandidates);
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
     * @param array<string, HeaderCandidate> $candidates
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @return array<string, list<string>>
     */
    private function candidateDependencyMap(array $candidates, array $preparedClassDataByClass): array
    {
        $candidateKeys = array_keys($candidates);
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

            $dependencies = $this->extractCandidateDependencies($candidateKey, $classData, $candidateSet, $shortNameIndex);
            sort($dependencies);
            $dependencyMap[$candidateKey] = $dependencies;
        }

        return $dependencyMap;
    }

    /**
     * @param array<string, bool> $candidateSet
     * @param array<string, list<string>> $shortNameIndex
     * @param array<string, mixed> $classData
     * @return list<string>
     */
    private function extractCandidateDependencies(
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

            $returnType = is_string($method['type'] ?? null) ? trim((string) $method['type']) : '';
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
            foreach ($this->typeIdentifierHints($typeHint) as $identifier) {
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
    private function typeIdentifierHints(string $type): array
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
    private function reverseDependencyMap(array $dependencyMap): array
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
     * @param array<string, HeaderCandidate> $viableCandidates
     * @return array<string, bool>
     */
    private function impactedDependents(array $removedCandidates, array $reverseDependencyMap, array $viableCandidates): array
    {
        $dirty = [];
        foreach ($removedCandidates as $removedClass) {
            foreach (($reverseDependencyMap[$removedClass] ?? []) as $dependentClass) {
                if (!isset($viableCandidates[$dependentClass])) {
                    continue;
                }
                $dirty[$dependentClass] = true;
            }
        }

        return $dirty;
    }

    private function supplementalMissingClass(?string $reasonCode, ?string $reasonMessage): ?string
    {
        if (!in_array($reasonCode, ['unsupported_parent_class', 'unsupported_external_module_dependency'], true)) {
            return null;
        }

        if (!is_string($reasonMessage) || preg_match('/^Parent class\s+(Q[A-Z][A-Za-z0-9_]*)\b/', $reasonMessage, $matches) !== 1) {
            return null;
        }

        return $matches[1];
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

    private function formatDurationSeconds(float $seconds): string
    {
        if ($seconds < 1.0) {
            return sprintf('%.0f ms', $seconds * 1000);
        }

        return sprintf('%.2f s', $seconds);
    }

    /**
     * @param list<HeaderCandidate> $candidates
     * @param list<string> $includePaths
     * @return array{tasks: list<GenerateTask>, class_total: int}
     */
    private function buildFactsBatchTasks(
        array $candidates,
        string $outputDir,
        array $includePaths,
        string $extensionName,
        string $metadataDir,
    ): array {
        $batchDir = $metadataDir . '/facts-batches';
        $this->ensureDirectory($batchDir);

        /** @var array<string, list<HeaderCandidate>> $groups */
        $groups = [];
        foreach ($candidates as $candidate) {
            $groupKey = $candidate->module . "\0" . $candidate->parseHeader;
            $groups[$groupKey] ??= [];
            $groups[$groupKey][] = $candidate;
        }

        $tasks = [];
        foreach ($groups as $groupKey => $groupCandidates) {
            $firstCandidate = $groupCandidates[0] ?? null;
            if (!$firstCandidate instanceof HeaderCandidate) {
                continue;
            }

            $batchEntries = array_map(
                static fn(HeaderCandidate $candidate): array => [
                    'class' => $candidate->className,
                    'task_key' => $candidate->identityKey(),
                ],
                $groupCandidates,
            );
            $batchHash = sha1($groupKey . ':' . json_encode($batchEntries, JSON_UNESCAPED_SLASHES));
            $batchFile = $batchDir . '/' . $batchHash . '.json';
            $this->writeJsonFile($batchFile, $batchEntries, '[]');

            $tasks[] = new GenerateTask(
                headerPath: $firstCandidate->parseHeader,
                className: $firstCandidate->className,
                module: $firstCandidate->module,
                namespace: $this->namespaceForModule($firstCandidate->module),
                outputDir: $outputDir,
                extensionName: $extensionName,
                qtPath: null,
                candidateKey: null,
                includePaths: $includePaths,
                classBatchFile: $batchFile,
                workerMode: 'facts-batch',
            );
        }

        return [
            'tasks' => $tasks,
            'class_total' => count($candidates),
        ];
    }

    private function namespaceForModule(string $module): string
    {
        return ModuleNamespace::forQtModule($module);
    }

    private function classCacheDir(string $metadataDir): string
    {
        $realMetadataDir = realpath($metadataDir);
        $baseDir = $realMetadataDir !== false ? dirname($realMetadataDir) : dirname($metadataDir);

        return $baseDir . '/classes';
    }

    private function classCachePath(string $metadataDir, HeaderCandidate $candidate): string
    {
        $safeClassName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $candidate->resolvedGenerationId()) ?? $candidate->resolvedGenerationId();
        $stableSuffix = substr(sha1(implode('|', [
            $candidate->module,
            $candidate->className,
            $candidate->parseHeader,
        ])), 0, 12);

        return $this->classCacheDir($metadataDir) . '/' . $safeClassName . '__' . $stableSuffix . '.json';
    }

    private function legacyClassCachePath(string $metadataDir, HeaderCandidate $candidate): string
    {
        $safeClassName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $candidate->resolvedGenerationId()) ?? $candidate->resolvedGenerationId();

        return $this->classCacheDir($metadataDir) . '/' . $safeClassName . '.json';
    }

    /**
     * @param list<string> $includePaths
     * @return array<string, mixed>|null
     */
    private function readClassStructureCache(string $metadataDir, HeaderCandidate $candidate, array $includePaths): ?array
    {
        foreach ($this->classStructureCacheLookupCandidates($candidate) as $lookupCandidate) {
            $paths = array_values(array_unique([
                $this->classCachePath($metadataDir, $lookupCandidate),
                $this->legacyClassCachePath($metadataDir, $lookupCandidate),
            ]));

            foreach ($paths as $path) {
                if (!is_file($path)) {
                    continue;
                }

                $decoded = json_decode((string) file_get_contents($path), true);
                if (!is_array($decoded)) {
                    continue;
                }

                if (($decoded['schema_version'] ?? null) !== self::CLASS_CACHE_SCHEMA_VERSION) {
                    continue;
                }

                $cacheKey = (string) ($decoded['cache_key'] ?? '');
                if ($cacheKey === '') {
                    continue;
                }

                $cacheRecordCandidate = $this->classStructureCacheRecordCandidate($decoded);
                $expectedCacheKeys = [$this->classStructureCacheKey($lookupCandidate, $includePaths)];
                if ($cacheRecordCandidate instanceof HeaderCandidate) {
                    $expectedCacheKeys[] = $this->classStructureCacheKey($cacheRecordCandidate, $includePaths);
                }

                $cacheKeyMatched = false;
                foreach (array_values(array_unique($expectedCacheKeys)) as $expectedCacheKey) {
                    if (hash_equals($expectedCacheKey, $cacheKey)) {
                        $cacheKeyMatched = true;
                        break;
                    }
                }

                if (!$cacheKeyMatched) {
                    continue;
                }

                $headerPath = $cacheRecordCandidate?->parseHeader ?? $lookupCandidate->parseHeader;
                $currentMtime = @filemtime($headerPath);
                $currentSize = @filesize($headerPath);
                if (($decoded['parse_header_mtime'] ?? null) !== ($currentMtime !== false ? $currentMtime : null)) {
                    continue;
                }

                if (($decoded['parse_header_size'] ?? null) !== ($currentSize !== false ? $currentSize : null)) {
                    continue;
                }

                $payload = $decoded['payload'] ?? null;
                if (is_array($payload)) {
                    return $payload;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function classStructureCacheRecordCandidate(array $record): ?HeaderCandidate
    {
        $module = is_string($record['module'] ?? null) ? $record['module'] : '';
        $className = is_string($record['class'] ?? null) ? $record['class'] : '';
        $publicHeader = is_string($record['public_header'] ?? null) ? $record['public_header'] : '';
        $parseHeader = is_string($record['parse_header'] ?? null) ? $record['parse_header'] : '';
        if ($module === '' || $className === '' || $publicHeader === '' || $parseHeader === '') {
            return null;
        }

        return new HeaderCandidate(
            module: $module,
            className: $className,
            publicHeader: $publicHeader,
            parseHeader: $parseHeader,
            qualifiedClassName: is_string($record['qualified_name'] ?? null) ? $record['qualified_name'] : null,
            generationId: is_string($record['generation_id'] ?? null) ? $record['generation_id'] : null,
        );
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
            'qualified_name' => $candidate->qualifiedClassName,
            'generation_id' => $candidate->resolvedGenerationId(),
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
            'qualified_name' => $candidate->qualifiedClassName,
            'generation_id' => $candidate->resolvedGenerationId(),
            'module' => $candidate->module,
            'public_header' => $candidate->publicHeader,
            'parse_header' => $candidate->parseHeader,
            'include_paths' => array_values($includePaths),
        ], JSON_UNESCAPED_SLASHES);

        return sha1($encoded !== false ? $encoded : $candidate->identityKey());
    }

    /**
     * @return list<HeaderCandidate>
     */
    private function classStructureCacheLookupCandidates(HeaderCandidate $candidate): array
    {
        $candidates = [$candidate];

        $canonicalByClassName = $candidate->withQualifiedClassName($candidate->className);
        if ($canonicalByClassName !== $candidate) {
            $candidates[] = $canonicalByClassName;
        }

        if ($candidate->qualifiedClassName !== null || $candidate->generationId !== null) {
            $candidates[] = new HeaderCandidate(
                module: $candidate->module,
                className: $candidate->className,
                publicHeader: $candidate->publicHeader,
                parseHeader: $candidate->parseHeader,
            );
        }

        /** @var array<string, HeaderCandidate> $unique */
        $unique = [];
        foreach ($candidates as $item) {
            $unique[$item->resolvedGenerationId()] = $item;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cacheCandidateForPayload(HeaderCandidate $candidate, array $payload): HeaderCandidate
    {
        if (($payload['status'] ?? null) !== 'ok') {
            return $candidate;
        }

        $classData = $payload['class_data'] ?? null;
        if (!is_array($classData)) {
            return $candidate;
        }

        $qualifiedName = is_string($classData['qualified_name'] ?? null)
            ? trim((string) $classData['qualified_name'])
            : '';

        return $candidate->withQualifiedClassName($qualifiedName !== '' ? $qualifiedName : null);
    }

    /**
     * @param list<string> $includePaths
     * @param array<string, mixed> $payload
     */
    private function writeReferencedNestedClassStructureCaches(
        string $metadataDir,
        HeaderCandidate $ownerCandidate,
        array $includePaths,
        array $payload,
    ): void {
        foreach ((array) ($payload['referenced_nested_class_data'] ?? []) as $nestedClassData) {
            if (!is_array($nestedClassData)) {
                continue;
            }

            $nestedName = is_string($nestedClassData['name'] ?? null)
                ? trim((string) $nestedClassData['name'])
                : '';
            $nestedQualifiedName = is_string($nestedClassData['qualified_name'] ?? null)
                ? trim((string) $nestedClassData['qualified_name'])
                : '';
            if ($nestedName === '' || $nestedQualifiedName === '') {
                continue;
            }

            $identity = GeneratedTypeIdentity::fromNames($nestedName, $nestedQualifiedName, $ownerCandidate->module);
            $nestedCandidate = new HeaderCandidate(
                module: $ownerCandidate->module,
                className: $nestedName,
                publicHeader: $ownerCandidate->publicHeader,
                parseHeader: $ownerCandidate->parseHeader,
                qualifiedClassName: $identity->canonicalKey,
                generationId: $identity->generationId,
            );

            $nestedPayload = [
                'status' => 'ok',
                'class' => $nestedName,
                'header' => $ownerCandidate->parseHeader,
                'task_key' => $nestedCandidate->identityKey(),
                'class_data' => $this->withDiscoveryModule($nestedClassData, $ownerCandidate->module),
                'referenced_nested_class_data' => [],
                'reason_code' => null,
                'reason_message' => null,
            ];
            $this->writeClassStructureCache($metadataDir, $nestedCandidate, $includePaths, $nestedPayload);
        }
    }

    /**
     * @param array<string, HeaderCandidate> $preparedCandidates
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass
     * @param array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass
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
            $classData = $this->withDiscoveryModule((array) $payload['class_data'], $candidate->module);
            $resolvedCandidate = $candidate->withQualifiedClassName(
                is_string($classData['qualified_name'] ?? null)
                    ? (string) $classData['qualified_name']
                    : null,
            );
            $candidateKey = $resolvedCandidate->identityKey();
            $preparedCandidates[$candidateKey] = $resolvedCandidate;
            $preparedClassDataByClass[$candidateKey] = $classData;
            unset($skippedByClass[$candidateKey], $errorsByClass[$candidateKey]);
            $this->recordReferencedNestedClassPayloads(
                $candidate,
                $payload,
                $preparedCandidates,
                $preparedClassDataByClass,
                $skippedByClass,
                $errorsByClass,
            );

            return;
        }

        if ($status === 'skipped') {
            $skippedByClass[$candidate->identityKey()] = [
                'module' => $candidate->module,
                'class' => $candidate->className,
                'header' => $candidate->parseHeader,
                'reason_code' => is_string($payload['reason_code'] ?? null) ? $payload['reason_code'] : null,
                'reason_message' => is_string($payload['reason_message'] ?? null) ? $payload['reason_message'] : null,
            ];
            unset($errorsByClass[$candidate->identityKey()]);

            return;
        }

        $errorsByClass[$candidate->identityKey()] = [
            'module' => $candidate->module,
            'class' => $candidate->className,
            'header' => $candidate->parseHeader,
            'reason_code' => 'class_structure_error',
            'reason_message' => 'Class structure facts could not be loaded.',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, HeaderCandidate> $preparedCandidates
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass
     * @param array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass
     */
    private function recordReferencedNestedClassPayloads(
        HeaderCandidate $ownerCandidate,
        array $payload,
        array &$preparedCandidates,
        array &$preparedClassDataByClass,
        array &$skippedByClass,
        array &$errorsByClass,
    ): void {
        foreach ((array) ($payload['referenced_nested_class_data'] ?? []) as $nestedClassData) {
            if (!is_array($nestedClassData)) {
                continue;
            }

            $nestedName = is_string($nestedClassData['name'] ?? null)
                ? trim((string) $nestedClassData['name'])
                : '';
            $nestedQualifiedName = is_string($nestedClassData['qualified_name'] ?? null)
                ? trim((string) $nestedClassData['qualified_name'])
                : '';
            if ($nestedName === '' || $nestedQualifiedName === '') {
                continue;
            }

            $identity = GeneratedTypeIdentity::fromNames($nestedName, $nestedQualifiedName, $ownerCandidate->module);
            $nestedCandidate = new HeaderCandidate(
                module: $ownerCandidate->module,
                className: $nestedName,
                publicHeader: $ownerCandidate->publicHeader,
                parseHeader: $ownerCandidate->parseHeader,
                qualifiedClassName: $identity->canonicalKey,
                generationId: $identity->generationId,
            );
            $nestedKey = $nestedCandidate->identityKey();
            $preparedCandidates[$nestedKey] = $nestedCandidate;
            $preparedClassDataByClass[$nestedKey] = $this->withDiscoveryModule($nestedClassData, $ownerCandidate->module);
            unset($skippedByClass[$nestedKey], $errorsByClass[$nestedKey]);
        }
    }

    /**
     * @param array<string, mixed> $classData
     * @return array<string, mixed>
     */
    private function withDiscoveryModule(array $classData, string $module): array
    {
        if ($module === '') {
            return $classData;
        }

        $classData['module'] = $module;

        return $classData;
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

    /**
     * @param list<string> $modules
     * @param list<HeaderCandidate> $acceptedCandidates
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @return array<string, int>
     */
    private function moduleMethodTotals(array $modules, array $acceptedCandidates, array $preparedClassDataByClass): array
    {
        $totals = [];
        foreach ($modules as $module) {
            $totals[$module] = 0;
        }

        foreach ($acceptedCandidates as $candidate) {
            $totals[$candidate->module] ??= 0;
            $classData = $preparedClassDataByClass[$candidate->identityKey()] ?? null;
            if (!is_array($classData)) {
                continue;
            }

            $methods = $classData['methods'] ?? null;
            if (!is_array($methods)) {
                continue;
            }

            $totals[$candidate->module] += count($methods);
        }

        return $totals;
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
