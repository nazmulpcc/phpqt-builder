<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Build\ExtensionScaffolder;
use QtBuilder\Build\GenerateTask;
use QtBuilder\Build\GenerateWorkerPool;
use QtBuilder\CodeGen\TypeBridge;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Filtering\ClassExposurePolicy;
use QtBuilder\Qt\QtInstallationResolver;
use QtBuilder\Scanning\ModuleHeaderScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('build', 'Generate a PHP extension source tree from Qt modules.')]
class BuildCommand extends Command
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
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Extension name', 'qt')
            ->addOption('ext-version', null, InputOption::VALUE_REQUIRED, 'Extension version', '0.1.0')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'build/ext')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel generate workers');
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
        $extensionName = (string) $input->getOption('name');
        $extensionVersion = (string) $input->getOption('ext-version');
        $jobs = $this->resolveJobs($input->getOption('jobs'));

        $context = new ExtensionBuildContext($extensionName, $extensionVersion, $outputDir, $installation, $modules);
        $scaffolder = new ExtensionScaffolder();
        $scaffolder->prepare($context);

        $scanner = new ModuleHeaderScanner();
        $classPolicy = new ClassExposurePolicy();
        $typeBridge = new TypeBridge();

        $acceptedCandidates = [];
        $skippedClasses = [];

        foreach ($modules as $module) {
            foreach ($scanner->scan($installation, $module) as $candidate) {
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

        $allowedClasses = array_values(array_filter(
            array_map(static fn($candidate) => $candidate->className, $acceptedCandidates),
            static fn(string $className): bool => $typeBridge->isValueType($className),
        ));

        $tasks = [];
        foreach ($acceptedCandidates as $candidate) {
            $tasks[] = new GenerateTask(
                headerPath: $candidate->parseHeader,
                className: $candidate->className,
                module: $candidate->module,
                namespace: $this->namespaceForModule($candidate->module),
                outputDir: $outputDir,
                extensionName: $extensionName,
                qtPath: $installation->rootPath,
                allowedClasses: $allowedClasses,
            );
        }

        $output->writeln(sprintf('<info>Scanning complete.</info> %d candidates queued, %d filtered before generation.', count($tasks), count($skippedClasses)));
        $output->writeln(sprintf('<info>Running %d parallel generate worker(s)...</info>', $jobs));

        $workerPool = new GenerateWorkerPool(dirname(__DIR__, 2));
        $results = $workerPool->run($tasks, $jobs);

        $generatedClasses = [];
        $skippedMethods = [];
        $errors = [];
        $classmap = [];

        foreach ($results as $result) {
            if ($result->isOk()) {
                $generatedClasses[] = $result->className;
                $classmap[] = [
                    'class' => $result->className,
                    'header' => $result->headerPath,
                    'files' => $result->generatedFiles,
                ];
            } elseif ($result->isSkipped()) {
                $skippedClasses[] = [
                    'class' => $result->className,
                    'header' => $result->headerPath,
                    'reason_code' => $result->reasonCode,
                    'reason_message' => $result->reasonMessage,
                ];
            } else {
                $errors[] = [
                    'class' => $result->className,
                    'header' => $result->headerPath,
                    'reason_code' => $result->reasonCode,
                    'reason_message' => $result->reasonMessage,
                    'stderr' => $result->stderr,
                ];
            }

            foreach ($result->skippedMethods as $skippedMethod) {
                $skippedMethods[] = ['class' => $result->className] + $skippedMethod;
            }
        }

        sort($generatedClasses);
        $context = $context->withGeneratedClasses($generatedClasses);
        $scaffoldFiles = $scaffolder->finalize($context);

        $summary = [
            'modules' => $modules,
            'candidate_classes' => count($acceptedCandidates) + count($skippedClasses),
            'generated_classes' => count($generatedClasses),
            'skipped_classes' => count($skippedClasses),
            'failed_classes' => count($errors),
            'jobs' => $jobs,
        ];

        file_put_contents($outputDir . '/generated/classmap.json', json_encode($classmap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($outputDir . '/generated/skipped_classes.json', json_encode($skippedClasses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($outputDir . '/generated/skipped_methods.json', json_encode($skippedMethods, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
        file_put_contents($outputDir . '/generated/build_summary.json', json_encode($summary + ['errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        foreach ($scaffoldFiles as $file) {
            $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $file));
        }
        $output->writeln(sprintf('<info>Generated %d class wrapper(s); %d class(es) skipped; %d error(s).</info>', count($generatedClasses), count($skippedClasses), count($errors)));

        if ($generatedClasses === [] || $errors !== []) {
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
}
