<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\BuildDirectoryCleaner;
use QtBuilder\Build\BuildExecutionRequest;
use QtBuilder\Build\BuildDiscoveryService;
use QtBuilder\Build\BuildLayout;
use QtBuilder\Build\BuildPipeline;
use QtBuilder\Build\Dependencies\ModuleDependencyResolver;
use QtBuilder\Build\Dependencies\ResolvedModuleGraph;
use QtBuilder\Build\Dependencies\StaticModuleDependencyResolver;
use QtBuilder\Build\ExtensionBootstrapper;
use QtBuilder\Build\ProcessExtensionBootstrapper;
use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Prompts\ModuleMultiSearchPrompt;
use QtBuilder\Qt\QtInstallationResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('build', 'Generate a PHP extension source tree from Qt modules.')]
class BuildCommand extends Command
{
    private const ALL_MODULES_OPTION = '__all';

    private readonly ExtensionBootstrapper $bootstrapper;
    private readonly ModuleDependencyResolver $dependencyResolver;
    /** @var array<string, list<string>> */
    private array $interactiveDependencyMap = [];
    /** @var callable(string, array<string, string>, array<int, string>): array<int, string> */
    private $moduleSelector;

    public function __construct(
        private readonly SystemInformation $systemInformation,
        ?ExtensionBootstrapper $bootstrapper = null,
        private readonly BuildDiscoveryService $discoveryService = new BuildDiscoveryService(),
        private readonly BuildDirectoryCleaner $buildDirectoryCleaner = new BuildDirectoryCleaner(),
        ?ModuleDependencyResolver $dependencyResolver = null,
        ?callable $moduleSelector = null,
    ) {
        $this->bootstrapper = $bootstrapper ?? new ProcessExtensionBootstrapper($systemInformation);
        $this->dependencyResolver = $dependencyResolver ?? new StaticModuleDependencyResolver();
        $this->moduleSelector = $moduleSelector ?? function (string $label, array $options, array $default): array {
            $prompt = new ModuleMultiSearchPrompt(
                label: $label,
                options: static fn (string $search): array => self::filterModuleOptions($options, $search),
                allOptions: $options,
                dependencyMap: $this->interactiveDependencyMap,
                scroll: max(5, min(15, count($options))),
                allOptionKey: self::ALL_MODULES_OPTION,
            );
            foreach ($default as $defaultOption) {
                if (!isset($options[$defaultOption])) {
                    continue;
                }
                $prompt->values[$defaultOption] = $options[$defaultOption];
            }

            return array_values(array_map(
                static fn (mixed $value): string => (string) $value,
                $prompt->prompt(),
            ));
        };

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('modules', InputArgument::OPTIONAL, 'Comma-separated Qt modules to scan')
            ->addOption('qt-path', null, InputOption::VALUE_REQUIRED, 'Path to the Qt installation root')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Extension name', 'qt')
            ->addOption('ext-version', null, InputOption::VALUE_REQUIRED, 'Extension version', '0.1.0')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Build root directory; extension sources go under <output>/ext', 'build')
            ->addOption('force', 'F', InputOption::VALUE_NONE, 'Clear the selected build root before starting')
            ->addOption('no-build', null, InputOption::VALUE_NONE, 'Generate sources only and skip phpize/configure/make')
            ->addOption('ccache', null, InputOption::VALUE_NEGATABLE, 'Use ccache for configure/make when available')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel discovery/bootstrap workers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $requestedModules = $this->resolveRequestedModules($input);
            $resolvedGraph = $this->dependencyResolver->resolve($requestedModules);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }

        $this->renderDependencyResolution($output, $resolvedGraph);

        $qtResolver = new QtInstallationResolver($this->systemInformation);
        $installation = $qtResolver->resolve(
            $input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null,
            $resolvedGraph->buildOrder,
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
        try {
            $useCcache = $this->resolveCcacheUsage($input);
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }
        $result = $pipeline->build(
            new BuildExecutionRequest(
                installation: $installation,
                buildRootDir: $layout->buildRootDir,
                outputDir: $layout->extensionDir(),
                modules: $resolvedGraph->buildOrder,
                requestedModules: $resolvedGraph->requestedModules,
                extensionName: (string) $input->getOption('name'),
                extensionVersion: (string) $input->getOption('ext-version'),
                jobs: $this->resolveJobs($input->getOption('jobs')),
                resolvedModuleGraph: $resolvedGraph,
                dependencySource: $resolvedGraph->dependencySource,
                bootstrapEnabled: !(bool) $input->getOption('no-build'),
                useCcache: $useCcache,
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

    /**
     * @return list<string>
     */
    private function resolveRequestedModules(InputInterface $input): array
    {
        $modulesArgument = $input->getArgument('modules');
        if (is_string($modulesArgument) && trim($modulesArgument) !== '') {
            return $this->parseModules($modulesArgument);
        }

        $supportedModules = $this->dependencyResolver->supportedModules();
        if ($supportedModules === []) {
            throw new \RuntimeException('No Qt modules are available in the dependency manifest.');
        }

        if (!$input->isInteractive()) {
            return $supportedModules;
        }

        $this->interactiveDependencyMap = $this->buildInteractiveDependencyMap($supportedModules);

        $options = [self::ALL_MODULES_OPTION => 'All'];
        foreach ($supportedModules as $module) {
            $options[$module] = $module;
        }

        $selected = ($this->moduleSelector)(
            'Select Qt modules to build',
            $options,
            [self::ALL_MODULES_OPTION],
        );

        if ($selected === []) {
            $selected = [self::ALL_MODULES_OPTION];
        }

        $selected = $this->normalizeAllSelection($selected);

        if ($selected === [self::ALL_MODULES_OPTION]) {
            return $supportedModules;
        }

        return $this->expandSelectedModulesWithDependencies($selected, $supportedModules);
    }

    /**
     * @param array<string, string> $options
     * @return array<string, string>
     */
    private static function filterModuleOptions(array $options, string $search): array
    {
        $search = mb_strtolower(trim($search));
        if ($search === '') {
            return $options;
        }

        return array_filter(
            $options,
            static fn (string $label): bool => str_contains(mb_strtolower($label), $search),
        );
    }

    /**
     * @param list<string> $selected
     * @return list<string>
     */
    private function normalizeAllSelection(array $selected): array
    {
        if (!in_array(self::ALL_MODULES_OPTION, $selected, true)) {
            return $selected;
        }

        $concreteModules = array_values(array_filter(
            $selected,
            static fn (string $module): bool => $module !== self::ALL_MODULES_OPTION,
        ));

        return $concreteModules === [] ? [self::ALL_MODULES_OPTION] : $concreteModules;
    }

    /**
     * @param list<string> $selected
     * @param list<string> $supportedModules
     * @return list<string>
     */
    private function expandSelectedModulesWithDependencies(array $selected, array $supportedModules): array
    {
        $expandedSet = [];
        foreach ($selected as $module) {
            $expandedSet[$module] = true;
            foreach ($this->interactiveDependencyMap[$module] ?? [] as $dependencyModule) {
                $expandedSet[$dependencyModule] = true;
            }
        }

        return array_values(array_filter(
            $supportedModules,
            static fn (string $module): bool => isset($expandedSet[$module]),
        ));
    }

    /**
     * @param list<string> $supportedModules
     * @return array<string, list<string>>
     */
    private function buildInteractiveDependencyMap(array $supportedModules): array
    {
        $supportedSet = array_fill_keys($supportedModules, true);
        $dependencyMap = [];

        foreach ($supportedModules as $module) {
            $graph = $this->dependencyResolver->resolve([$module]);
            $dependencyMap[$module] = array_values(array_filter(
                $graph->expandedModules(),
                static fn (string $dependencyModule): bool => $dependencyModule !== $module && isset($supportedSet[$dependencyModule]),
            ));
        }

        return $dependencyMap;
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

    private function resolveCcacheUsage(InputInterface $input): bool
    {
        $option = $input->getOption('ccache');
        $ccachePath = $this->systemInformation->findExecutable('ccache');
        $hasCcache = is_string($ccachePath) && $ccachePath !== '';

        if ($option === false) {
            return false;
        }

        if ($option === true && !$hasCcache) {
            throw new \RuntimeException('The --ccache option was set but ccache is not available on PATH.');
        }

        return $hasCcache;
    }

    private function renderDependencyResolution(OutputInterface $output, ResolvedModuleGraph $graph): void
    {
        $output->writeln(sprintf(
            '<comment>Requested modules:</comment> %s',
            implode(', ', $graph->requestedModules),
        ));

        if ($graph->autoAddedModules() !== []) {
            $output->writeln(sprintf(
                '<comment>Auto-added dependency modules:</comment> %s',
                implode(', ', $graph->autoAddedModules()),
            ));
        }

        if ($graph->unmappedModules !== []) {
            $output->writeln(sprintf(
                '<comment>Manifest warning:</comment> %s %s no static dependency manifest entry; only the implicit QtCore dependency will be applied for %s.',
                implode(', ', $graph->unmappedModules),
                count($graph->unmappedModules) === 1 ? 'has' : 'have',
                count($graph->unmappedModules) === 1 ? 'that module' : 'those modules',
            ));
        }

        $output->writeln(sprintf(
            '<comment>Expanded modules:</comment> %s',
            implode(', ', $graph->expandedModules()),
        ));
    }
}
