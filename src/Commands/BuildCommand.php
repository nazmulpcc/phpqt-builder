<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\BuildDirectoryCleaner;
use QtBuilder\Build\BuildExecutionRequest;
use QtBuilder\Build\BuildDiscoveryService;
use QtBuilder\Build\BuildLayout;
use QtBuilder\Build\BuildPipeline;
use QtBuilder\Build\ExtensionBootstrapper;
use QtBuilder\Build\ProcessExtensionBootstrapper;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Qt\QtInstallationResolver;
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
        private readonly BuildDiscoveryService $discoveryService = new BuildDiscoveryService(),
        private readonly BuildDirectoryCleaner $buildDirectoryCleaner = new BuildDirectoryCleaner(),
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
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Build root directory; extension sources go under <output>/ext', 'build')
            ->addOption('force', 'F', InputOption::VALUE_NONE, 'Clear the selected build root before starting')
            ->addOption('no-build', null, InputOption::VALUE_NONE, 'Generate sources only and skip phpize/configure/make')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel discovery/bootstrap workers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modules = $this->parseModules((string) $input->getOption('modules'));

        $qtResolver = new QtInstallationResolver($this->systemInformation);
        $installation = $qtResolver->resolve(
            $input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null,
            $modules,
        );

        try {
            $layout = BuildLayout::fromCliOutput((string) $input->getOption('output'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }

        if ((bool) $input->getOption('force')) {
            try {
                $this->buildDirectoryCleaner->clear($layout->buildRootDir);
                $output->writeln(sprintf('<comment>Cleared build root:</comment> %s', $layout->buildRootDir));
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        }

        $pipeline = new BuildPipeline($this->bootstrapper, $this->discoveryService);
        $result = $pipeline->build(
            new BuildExecutionRequest(
                installation: $installation,
                buildRootDir: $layout->buildRootDir,
                outputDir: $layout->extensionDir(),
                modules: $modules,
                extensionName: (string) $input->getOption('name'),
                extensionVersion: (string) $input->getOption('ext-version'),
                jobs: $this->resolveJobs($input->getOption('jobs')),
                bootstrapEnabled: !(bool) $input->getOption('no-build'),
            ),
            $output,
        );

        return $result->successful ? self::SUCCESS : self::FAILURE;
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
}
