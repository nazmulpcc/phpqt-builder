<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\BuildDirectoryCleaner;
use QtBuilder\Build\BuildExecutionRequest;
use QtBuilder\Build\BuildDiscoveryService;
use QtBuilder\Build\BuildLayout;
use QtBuilder\Build\BuildPipeline;
use QtBuilder\Build\ExtensionBootstrapper;
use QtBuilder\Build\ImportedModuleAbi;
use QtBuilder\Build\ProcessExtensionBootstrapper;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Qt\QtInstallationResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('build:modules', 'Generate separate PHP extension source trees for Qt modules.')]
class BuildModulesCommand extends Command
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
            ->addOption('modules', null, InputOption::VALUE_REQUIRED, 'Comma-separated Qt modules to build', 'QtCore')
            ->addOption('ext-version', null, InputOption::VALUE_REQUIRED, 'Extension version', '0.1.0')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Base output directory; each module is written under <output>/<Module>', 'build')
            ->addOption('force', 'F', InputOption::VALUE_NONE, 'Clear each selected module build root before starting')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel discovery/bootstrap workers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $baseLayout = BuildLayout::fromCliOutput((string) $input->getOption('output'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }

        $modules = $this->normalizeModules((string) $input->getOption('modules'));
        $jobs = $this->resolveJobs($input->getOption('jobs'));
        $qtPath = $input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null;
        $extensionVersion = (string) $input->getOption('ext-version');
        $pipeline = new BuildPipeline($this->bootstrapper, $this->discoveryService);
        $qtResolver = new QtInstallationResolver($this->systemInformation);
        $qtCoreImport = null;
        $loadOrder = [];

        foreach ($modules as $index => $module) {
            if ($index > 0) {
                $output->writeln('');
            }

            $moduleBuildRoot = $baseLayout->buildRootDir . '/' . $module;
            $moduleLayout = new BuildLayout($moduleBuildRoot);

            if ((bool) $input->getOption('force')) {
                try {
                    $this->buildDirectoryCleaner->clear($moduleLayout->buildRootDir);
                    $output->writeln(sprintf('<comment>Cleared build root:</comment> %s', $moduleLayout->buildRootDir));
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                    return self::FAILURE;
                }
            }

            $extensionName = $this->extensionNameForModule($module);
            $nativeModules = $module === 'QtCore'
                ? ['QtCore']
                : array_values(array_unique(['QtCore', $module]));
            $installation = $qtResolver->resolve($qtPath, $nativeModules);

            $output->writeln(sprintf('<info>Building %s as %s...</info>', $module, $extensionName));

            $result = $pipeline->build(
                new BuildExecutionRequest(
                    installation: $installation,
                    buildRootDir: $moduleLayout->buildRootDir,
                    outputDir: $moduleLayout->extensionDir(),
                    modules: [$module],
                    extensionName: $extensionName,
                    extensionVersion: $extensionVersion,
                    jobs: $jobs,
                    linkModules: $nativeModules,
                    importedAbi: $module === 'QtCore' ? null : $qtCoreImport,
                    forceSignalConnectionSupport: $module === 'QtCore',
                    writeAbiManifest: true,
                    reuseDiscoveryCache: $module === 'QtCore',
                ),
                $output,
            );

            if (!$result->successful) {
                return self::FAILURE;
            }

            $loadOrder[] = $extensionName;
            if ($module === 'QtCore') {
                $abiManifestPath = $result->abiManifest?->metadataDir . '/module_abi.json';
                if ($abiManifestPath === null || !is_file($abiManifestPath)) {
                    $output->writeln('<error>QtCore build completed without a module ABI manifest.</error>');
                    return self::FAILURE;
                }

                $qtCoreImport = ImportedModuleAbi::load($abiManifestPath);
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('<comment>Extension load order:</comment> %s', implode(', ', $loadOrder)));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function normalizeModules(string $modules): array
    {
        $parts = array_map('trim', explode(',', $modules));
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        if ($parts === []) {
            $parts = ['QtCore'];
        }

        $ordered = ['QtCore'];
        foreach ($parts as $part) {
            if ($part === 'QtCore') {
                continue;
            }

            $ordered[] = $part;
        }

        return array_values(array_unique($ordered));
    }

    private function extensionNameForModule(string $module): string
    {
        return strtolower($module);
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
