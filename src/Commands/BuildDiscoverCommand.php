<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\BuildDiscoveryService;
use QtBuilder\Build\ExtensionBuildContext;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Qt\QtInstallationResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('build:discover', 'Scan Qt modules and write reusable build discovery metadata.')]
class BuildDiscoverCommand extends Command
{
    public function __construct(
        private readonly SystemInformation $systemInformation,
        private readonly BuildDiscoveryService $discoveryService = new BuildDiscoveryService(),
    ) {
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

        $qtResolver = new QtInstallationResolver($this->systemInformation);
        $installation = $qtResolver->resolve($input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null, $modules);

        $outputDir = (string) $input->getOption('output');
        $jobs = $this->resolveJobs($input->getOption('jobs'));
        $context = new ExtensionBuildContext('qt', '0.1.0', $outputDir, $installation, $modules);
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
        $this->renderModuleAcceptance($output, $modules, $discovery->acceptedCandidates, $discovery->skippedClasses);

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
     */
    private function renderModuleAcceptance(OutputInterface $output, array $modules, array $acceptedCandidates, array $skippedClasses): void
    {
        $output->writeln('<comment>Module acceptance:</comment>');

        foreach ($this->discoveryService->moduleAcceptance($modules, $acceptedCandidates, $skippedClasses) as $row) {
            $output->writeln(sprintf(
                '  <comment>%s:</comment> %d/%d accepted (%s%%)',
                $row['module'],
                $row['accepted'],
                $row['total'],
                number_format($row['percent'], 1),
            ));
        }
    }
}
