<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\BuildLayout;
use QtBuilder\Build\BuildDirectoryCleaner;
use QtBuilder\Build\BuildDiscoveryService;
use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Qt\QtInstallationResolver;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('build:discover', 'Scan Qt modules and write reusable build discovery metadata.')]
class BuildDiscoverCommand extends Command
{
    public function __construct(
        private readonly SystemInformation $systemInformation,
        private readonly BuildDiscoveryService $discoveryService = new BuildDiscoveryService(),
        private readonly BuildDirectoryCleaner $buildDirectoryCleaner = new BuildDirectoryCleaner(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('modules', InputArgument::OPTIONAL, 'Comma-separated Qt modules to scan', 'QtCore')
            ->addOption('qt-path', null, InputOption::VALUE_REQUIRED, 'Path to the Qt installation root')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Build root directory; discovery metadata is written under <output>/generated', 'build')
            ->addOption('force', 'F', InputOption::VALUE_NONE, 'Clear the selected build root before starting discovery')
            ->addOption('no-acceptance-table', null, InputOption::VALUE_NONE, 'Skip module acceptance table output (internal use)')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel discovery workers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modules = $this->parseModules((string) $input->getArgument('modules'));

        $qtResolver = new QtInstallationResolver($this->systemInformation);
        $installation = $qtResolver->resolve($input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null, $modules);

        try {
            $layout = BuildLayout::fromCliOutput((string) $input->getOption('output'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }

        $jobs = $this->resolveJobs($input->getOption('jobs'));
        if ((bool) $input->getOption('force')) {
            try {
                $this->buildDirectoryCleaner->clear($layout->buildRootDir);
                $output->writeln(sprintf('<comment>Cleared build root:</comment> %s', $layout->buildRootDir));
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        }

        $context = new ExtensionBuildContext('qt', '0.1.0', $layout->buildRootDir, $layout->extensionDir(), $installation, $modules);
        $outputDir = $context->outputDir;
        $metadataDir = $context->metadataDir();

        $output->writeln(sprintf('<info>Running %d parallel discovery worker(s)...</info>', $jobs));
        $discovery = $this->discoveryService->discover(
            $installation,
            $modules,
            $outputDir,
            $metadataDir,
            $jobs,
            $output,
        );
        $output->writeln(sprintf(
            '<info>Scanning complete.</info> %d candidates queued, %d filtered before discovery.',
            count($discovery->acceptedCandidates),
            count($discovery->skippedClasses),
        ));
        if (!(bool) $input->getOption('no-acceptance-table')) {
            $this->renderModuleAcceptance(
                $output,
                $modules,
                $discovery->acceptedCandidates,
                $discovery->skippedClasses,
                $discovery->moduleMethodTotals,
                $discovery->moduleAcceptedMethodTotals,
            );
        }

        if ($discovery->errors !== []) {
            foreach ($discovery->errors as $error) {
                $message = is_string($error['reason_message'] ?? null) ? $error['reason_message'] : 'Worker failed.';
                $output->writeln(sprintf('<error>%s</error>', $message));
            }

            return self::FAILURE;
        }

        $this->discoveryService->writeCache($metadataDir, $modules, $installation->rootPath, $discovery);

        foreach ([
            'discovery_cache.json',
            'accepted_candidates.json',
            'allowed_classes.json',
        ] as $filename) {
            $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $metadataDir . '/' . $filename));
        }

        $output->writeln(sprintf(
            '<info>Discovery cache ready.</info> %d viable class(es), %d skipped, %d pass(es).',
            count($discovery->acceptedCandidates),
            count($discovery->skippedClasses),
            $discovery->passes,
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

    /**
     * @param list<string> $modules
     * @param list<\QtBuilder\Scanning\HeaderCandidate> $acceptedCandidates
     * @param list<array<string, string|null>> $skippedClasses
     * @param array<string, int> $moduleMethodTotals
     * @param array<string, int> $moduleAcceptedMethodTotals
     */
    private function renderModuleAcceptance(
        OutputInterface $output,
        array $modules,
        array $acceptedCandidates,
        array $skippedClasses,
        array $moduleMethodTotals,
        array $moduleAcceptedMethodTotals,
    ): void
    {
        $rows = [];
        foreach ($this->discoveryService->moduleAcceptance($modules, $acceptedCandidates, $skippedClasses) as $row) {
            $module = $row['module'];
            $classAccepted = (int) $row['accepted'];
            $classTotal = (int) $row['total'];
            $classPercent = $classTotal > 0 ? ($classAccepted / $classTotal) * 100.0 : 0.0;

            $methodTotal = max(0, (int) ($moduleMethodTotals[$module] ?? 0));
            $methodAccepted = max(0, (int) ($moduleAcceptedMethodTotals[$module] ?? 0));
            if ($methodAccepted > $methodTotal) {
                $methodAccepted = $methodTotal;
            }
            $methodPercent = $methodTotal > 0 ? 100.0 : 0.0;
            if ($methodTotal > 0) {
                $methodPercent = ($methodAccepted / $methodTotal) * 100.0;
            }

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
}
