<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Filtering\ClassExposurePolicy;
use QtBuilder\Qt\QtInstallation;
use QtBuilder\Scanning\HeaderCandidate;
use QtBuilder\Scanning\ModuleHeaderScanner;
use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\CppName;
use QtBuilder\Support\ModuleNamespace;
use QtBuilder\Support\TypeResolutionContext;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class BuildDiscoveryService
{
    private const CLASS_CACHE_SCHEMA_VERSION = 7;

    public function __construct(
        private readonly GenerateWorkerPool $workerPool = new GenerateWorkerPool(__DIR__ . '/../..'),
        private readonly ModuleHeaderScanner $scanner = new ModuleHeaderScanner(),
        private readonly ClassExposurePolicy $classPolicy = new ClassExposurePolicy(),
        private readonly ClassGenerationService $generationService = new ClassGenerationService(),
        private readonly SupplementalClassCandidateResolver $supplementalResolver = new SupplementalClassCandidateResolver(),
        private readonly SignatureDependencyCollector $signatureDependencyCollector = new SignatureDependencyCollector(),
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
            $classStructures['signature_dependency_types_by_class'],
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

        foreach ($acceptedCandidates as $candidate) {
            $qualified = is_string($candidate->qualifiedClassName) ? trim($candidate->qualifiedClassName) : '';
            if ($qualified !== '' && str_starts_with($qualified, 'QtPrivate::')) {
                return null;
            }
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
     * @param array<string, list<string>> $signatureDependencyTypesByClass
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
        array $signatureDependencyTypesByClass = [],
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
        /** @var array<string, list<string>> $dependencyTypeCache */
        $dependencyTypeCache = $signatureDependencyTypesByClass;
        /** @var array<string, bool> $pendingParentScan */
        $pendingParentScan = array_fill_keys(array_keys($candidateMap), true);
        /** @var array<string, bool> $pendingTypeScan */
        $pendingTypeScan = array_fill_keys(array_keys($candidateMap), true);
        $classTypeResolver = CppClassTypeResolver::fromPreparedClassData($preparedClassDataByClass);
        $parentScanPass = 0;
        $typeScanPass = 0;

        do {
            $knownClassNames = array_merge(
                array_keys($candidateMap),
                array_keys($preparedClassDataByClass),
                array_values($importedAvailableClasses),
                array_keys($supplementalCandidates),
            );
            foreach ($supplementalCandidates as $supplementalCandidate) {
                $knownClassNames[] = $supplementalCandidate->candidate->className;
                if ($supplementalCandidate->candidate->qualifiedClassName !== null) {
                    $knownClassNames[] = $supplementalCandidate->candidate->qualifiedClassName;
                }
            }
            foreach ($candidateMap as $candidate) {
                $knownClassNames[] = $candidate->className;
                if ($candidate->qualifiedClassName !== null) {
                    $knownClassNames[] = $candidate->qualifiedClassName;
                }
            }
            $knownClasses = array_fill_keys(array_values(array_unique($knownClassNames)), true);

            /** @var array<string, SupplementalClassCandidate> $queuedThisPass */
            $queuedThisPass = [];
            $allowedClassLookup = array_fill_keys(array_values(array_unique(array_merge(
                array_keys($candidateMap),
                array_values($importedAvailableClasses),
            ))), true);

            if ($pendingParentScan !== []) {
                $parentScanPass++;
                /** @var array<string, HeaderCandidate> $parentScanCandidates */
                $parentScanCandidates = [];
                foreach (array_keys($pendingParentScan) as $candidateKey) {
                    unset($pendingParentScan[$candidateKey]);
                    if (isset($candidateMap[$candidateKey])) {
                        $parentScanCandidates[$candidateKey] = $candidateMap[$candidateKey];
                    }
                }

                $scanProgress = $this->createProgressBar(
                    $output,
                    count($parentScanCandidates),
                    'qt_discovery',
                    'Supplemental scan pass ' . $parentScanPass,
                );
                $scanProgress?->start();

                foreach ($parentScanCandidates as $className => $candidate) {
                    $classData = $preparedClassDataByClass[$className] ?? null;
                    if (!is_array($classData)) {
                        $scanProgress?->advance();
                        continue;
                    }

                    $missingClass = $this->missingSupplementalParentClass(
                        $classData,
                        $allowedClassLookup,
                        $classTypeResolver,
                    );
                    if ($missingClass === null || isset($knownClasses[$missingClass]) || isset($knownClasses[CppName::unqualify($missingClass)])) {
                        $scanProgress?->advance();
                        continue;
                    }

                    $supplemental = $this->supplementalResolver->resolve(
                        $missingClass,
                        $candidate,
                        $modules,
                        $includePaths,
                        $knownClasses,
                        'unsupported_parent_class',
                    );
                    if ($supplemental === null) {
                        $scanProgress?->advance();
                        continue;
                    }

                    $queuedThisPass[$supplemental->candidate->identityKey()] = $supplemental;
                    $knownClasses[$supplemental->candidate->identityKey()] = true;
                    $knownClasses[$supplemental->candidate->className] = true;
                    if ($supplemental->candidate->qualifiedClassName !== null) {
                        $knownClasses[$supplemental->candidate->qualifiedClassName] = true;
                    }
                    $scanProgress?->advance();
                }

                if ($scanProgress !== null) {
                    $scanProgress->finish();
                    $output->write(PHP_EOL);
                }
            }

            if ($queuedThisPass === [] && $pendingTypeScan !== []) {
                $typeScanPass++;
                /** @var array<string, HeaderCandidate> $typeScanCandidates */
                $typeScanCandidates = [];
                foreach (array_keys($pendingTypeScan) as $candidateKey) {
                    unset($pendingTypeScan[$candidateKey]);
                    if (isset($candidateMap[$candidateKey])) {
                        $typeScanCandidates[$candidateKey] = $candidateMap[$candidateKey];
                    }
                }

                $typeScanProgress = $this->createProgressBar(
                    $output,
                    count($typeScanCandidates),
                    'qt_discovery',
                    'Type-driven supplemental scan pass ' . $typeScanPass,
                );
                $typeScanProgress?->start();
                $missingTypeOrigins = $this->collectTypeDrivenMissingDependencies(
                    $typeScanCandidates,
                    $preparedClassDataByClass,
                    $knownClasses,
                    $dependencyTypeCache,
                    static function () use ($typeScanProgress): void {
                        $typeScanProgress?->advance();
                    },
                );
                if ($typeScanProgress !== null) {
                    $typeScanProgress->finish();
                    $output->write(PHP_EOL);
                }

                $typeResolveProgress = $this->createProgressBar(
                    $output,
                    count($missingTypeOrigins),
                    'qt_discovery',
                    'Type-driven dependency resolution pass ' . $typeScanPass,
                );
                $typeResolveProgress?->start();
                foreach ($this->resolveTypeDrivenSupplementalCandidates(
                    $missingTypeOrigins,
                    $modules,
                    $includePaths,
                    $knownClasses,
                    static function () use ($typeResolveProgress): void {
                        $typeResolveProgress?->advance();
                    },
                ) as $supplemental) {
                    $queuedThisPass[$supplemental->candidate->identityKey()] = $supplemental;
                    $knownClasses[$supplemental->candidate->identityKey()] = true;
                    $knownClasses[$supplemental->candidate->className] = true;
                    if ($supplemental->candidate->qualifiedClassName !== null) {
                        $knownClasses[$supplemental->candidate->qualifiedClassName] = true;
                    }
                }
                if ($typeResolveProgress !== null) {
                    $typeResolveProgress->finish();
                    $output->write(PHP_EOL);
                }
            }

            if ($queuedThisPass === []) {
                if ($pendingParentScan === [] && $pendingTypeScan === []) {
                    break;
                }

                continue;
            }

            $output->writeln(sprintf(
                '<comment>Supplemental class discovery:</comment> queued %d new candidate(s).',
                count($queuedThisPass),
            ));

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

            foreach ($prepared['accepted_candidates'] as $candidate) {
                $candidateKey = $candidate->identityKey();
                $candidateMap[$candidateKey] = $candidate;
                $pendingParentScan[$candidateKey] = true;
                $pendingTypeScan[$candidateKey] = true;
            }

            foreach ($prepared['prepared_class_data'] as $className => $classData) {
                $preparedClassDataByClass[$className] = $classData;
            }

            foreach ($prepared['signature_dependency_types_by_class'] as $className => $dependencyTypes) {
                $dependencyTypeCache[$className] = $dependencyTypes;
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

            foreach ($queuedThisPass as $candidate) {
                $supplementalCandidates[$candidate->identityKey()] = $candidate;
            }

            if ($prepared['prepared_class_data'] !== []) {
                $classTypeResolver = CppClassTypeResolver::fromPreparedClassData($preparedClassDataByClass);
            }
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

    /**
     * @param array<string, mixed> $classData
     * @param array<string, bool> $allowedClasses
     */
    private function missingSupplementalParentClass(
        array $classData,
        array $allowedClasses,
        CppClassTypeResolver $classTypeResolver,
    ): ?string {
        $parentClass = is_string($classData['bases'][0] ?? null) ? trim($classData['bases'][0]) : '';
        if ($parentClass === '' || $parentClass === 'QIODeviceBase') {
            return null;
        }

        $context = TypeResolutionContext::fromClassData($classData);
        $resolvedParent = $classTypeResolver->canonicalizeType($parentClass, $context);

        if (
            isset($allowedClasses[$resolvedParent])
            || isset($allowedClasses[$parentClass])
            || isset($allowedClasses[CppName::unqualify($resolvedParent)])
        ) {
            return null;
        }

        return $resolvedParent !== '' ? $resolvedParent : $parentClass;
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
     *   signature_dependency_types_by_class: array<string, list<string>>,
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
        /** @var array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass */
        $skippedByClass = [];
        /** @var array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass */
        $errorsByClass = [];
        /** @var array<string, list<string>> $signatureDependencyTypesByClass */
        $signatureDependencyTypesByClass = [];
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

            if (!$this->hasSignatureDependencyTypesPayload($cachedPayload)) {
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
                $signatureDependencyTypesByClass,
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
                    'signature_dependency_types' => $result->signatureDependencyTypes,
                    'reason_code' => $result->reasonCode,
                    'reason_message' => $result->reasonMessage,
                ];

                $this->writeClassStructureCache($metadataDir, $candidate, $includePaths, $payload);
                $this->recordClassStructurePayload(
                    $candidate,
                    $payload,
                    $preparedCandidates,
                    $preparedClassDataByClass,
                    $signatureDependencyTypesByClass,
                    $skippedByClass,
                    $errorsByClass,
                );
            }
        }

        ksort($preparedCandidates);
        ksort($preparedClassDataByClass);
        ksort($signatureDependencyTypesByClass);

        return [
            'accepted_candidates' => array_values($preparedCandidates),
            'prepared_class_data' => $preparedClassDataByClass,
            'signature_dependency_types_by_class' => $signatureDependencyTypesByClass,
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
        $passes = 0;

        do {
            $passes++;
            $allowedClasses = array_keys($viableCandidates);
            $allowedClasses = array_values(array_unique([...$allowedClasses, ...$importedAvailableClasses]));
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
                    includePaths: [],
                );

                if ($result->status === 'ok') {
                    $nextViableCandidates[$className] = $candidate;
                    unset($skippedByClass[$className], $errorsByClass[$className]);
                    $progressBar?->advance();
                    continue;
                }

                $skippedByClass[$className] = [
                    'module' => $candidate->module,
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

    /**
     * Collect unresolved dependency types from class signatures (container
     * element/key/value types and owner-qualified nested types).
     *
     * @param array<string, HeaderCandidate> $candidateMap
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, bool> $knownClasses
     * @param array<string, list<string>> $dependencyTypeCache
     * @return array<string, HeaderCandidate>
     */
    private function collectTypeDrivenMissingDependencies(
        array $candidateMap,
        array $preparedClassDataByClass,
        array $knownClasses,
        array &$dependencyTypeCache = [],
        ?callable $onCandidateScanned = null,
    ): array {
        /** @var array<string, HeaderCandidate> $missingTypeOrigins */
        $missingTypeOrigins = [];

        foreach ($candidateMap as $candidateKey => $candidate) {
            $classData = $preparedClassDataByClass[$candidateKey] ?? null;
            if (!is_array($classData)) {
                $onCandidateScanned?->__invoke();
                continue;
            }

            $dependencyTypes = $dependencyTypeCache[$candidateKey] ?? null;
            if ($dependencyTypes === null) {
                $dependencyTypes = $this->signatureDependencyCollector->collectFromClassData(
                    $classData,
                    is_string($classData['name'] ?? null) ? (string) $classData['name'] : $candidate->className,
                );
                $dependencyTypeCache[$candidateKey] = $dependencyTypes;
            }

            foreach ($dependencyTypes as $dependencyType) {
                // Defensive pruning for stale cached payloads generated before
                // owner-qualified fallback removal (e.g. `QWidget::QString`).
                if ($candidate->className !== '' && str_starts_with($dependencyType, $candidate->className . '::')) {
                    continue;
                }

                if (isset($known[$dependencyType]) || isset($known[CppName::unqualify($dependencyType)])) {
                    continue;
                }

                if (!isset($missingTypeOrigins[$dependencyType])) {
                    $missingTypeOrigins[$dependencyType] = $candidate;
                }
            }

            $onCandidateScanned?->__invoke();
        }

        return $missingTypeOrigins;
    }

    /**
     * @param array<string, HeaderCandidate> $missingTypeOrigins
     * @param list<string> $requestedModules
     * @param list<string> $includePaths
     * @param array<string, bool> $knownClasses
     * @return list<SupplementalClassCandidate>
     */
    private function resolveTypeDrivenSupplementalCandidates(
        array $missingTypeOrigins,
        array $requestedModules,
        array $includePaths,
        array $knownClasses,
        ?callable $onDependencyResolved = null,
    ): array {
        /** @var array<string, SupplementalClassCandidate> $queued */
        $queued = [];
        $known = $knownClasses;

        foreach ($missingTypeOrigins as $dependencyType => $originCandidate) {
            if (isset($known[$dependencyType]) || isset($known[CppName::unqualify($dependencyType)])) {
                $onDependencyResolved?->__invoke();
                continue;
            }

            $supplemental = $this->supplementalResolver->resolve(
                $dependencyType,
                $originCandidate,
                $requestedModules,
                $includePaths,
                $known,
                'unsupported_signature_dependency',
            );
            if ($supplemental === null) {
                $onDependencyResolved?->__invoke();
                continue;
            }

            $identity = $supplemental->candidate->identityKey();
            $queued[$identity] = $supplemental;
            $known[$identity] = true;
            $known[$supplemental->candidate->className] = true;
            if ($supplemental->candidate->qualifiedClassName !== null) {
                $known[$supplemental->candidate->qualifiedClassName] = true;
            }

            $onDependencyResolved?->__invoke();
        }

        return array_values($queued);
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
                candidateKey: $candidate->identityKey(),
                includePaths: $includePaths,
                workerMode: 'facts',
            );
        }

        return $tasks;
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
     * @param array<string, HeaderCandidate> $preparedCandidates
     * @param array<string, array<string, mixed>> $preparedClassDataByClass
     * @param array<string, list<string>> $signatureDependencyTypesByClass
     * @param array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $skippedByClass
     * @param array<string, array{module: string|null, class: string, header: string, reason_code: string|null, reason_message: string|null}> $errorsByClass
     * @param array<string, mixed> $payload
     */
    private function recordClassStructurePayload(
        HeaderCandidate $candidate,
        array $payload,
        array &$preparedCandidates,
        array &$preparedClassDataByClass,
        array &$signatureDependencyTypesByClass,
        array &$skippedByClass,
        array &$errorsByClass,
    ): void {
        $status = (string) ($payload['status'] ?? 'error');
        if ($status === 'ok' && is_array($payload['class_data'] ?? null)) {
            $resolvedCandidate = $candidate->withQualifiedClassName(
                is_string($payload['class_data']['qualified_name'] ?? null)
                    ? (string) $payload['class_data']['qualified_name']
                    : null,
            );
            $candidateKey = $resolvedCandidate->identityKey();
            $preparedCandidates[$candidateKey] = $resolvedCandidate;
            $preparedClassDataByClass[$candidateKey] = $payload['class_data'];
            $signatureDependencyTypesByClass[$candidateKey] = $this->normalizeDependencyTypesPayload($payload['signature_dependency_types'] ?? []);
            unset($skippedByClass[$candidateKey], $errorsByClass[$candidateKey]);

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
     * @return list<string>
     */
    private function normalizeDependencyTypesPayload(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn(string $item): bool => $item !== '',
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasSignatureDependencyTypesPayload(array $payload): bool
    {
        return is_array($payload['signature_dependency_types'] ?? null);
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
