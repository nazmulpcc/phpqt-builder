<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Build\BuildLayout;
use QtBuilder\Build\RuntimeManifest;
use QtBuilder\Build\RuntimeModuleMetadata;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('build:info', 'Read build metadata from a generated runtime manifest.')]
final class BuildInfoCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('build-root', null, InputOption::VALUE_REQUIRED, 'Build root directory containing generated/runtime_manifest.json', 'build')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Show only one module entry');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = strtolower(trim((string) $input->getOption('format')));
        if (!in_array($format, ['table', 'json'], true)) {
            $output->writeln(sprintf('<error>Unsupported format "%s". Use table or json.</error>', $format));

            return self::FAILURE;
        }

        try {
            $layout = BuildLayout::fromCliOutput((string) $input->getOption('build-root'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $manifestPath = $layout->metadataDir() . '/runtime_manifest.json';
        if (!is_file($manifestPath)) {
            $output->writeln(sprintf(
                '<error>Runtime manifest not found at %s. Run `php qtb build` or `php qtb build:modules` first.</error>',
                $manifestPath,
            ));

            return self::FAILURE;
        }

        try {
            $manifest = RuntimeManifest::load($manifestPath);
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $moduleName = trim((string) ($input->getOption('module') ?? ''));
        if ($moduleName !== '') {
            $module = $manifest->module($moduleName);
            if (!$module instanceof RuntimeModuleMetadata) {
                $availableModules = implode(', ', array_keys($manifest->modules));
                $output->writeln(sprintf(
                    '<error>Unknown module "%s". Available modules: %s</error>',
                    $moduleName,
                    $availableModules !== '' ? $availableModules : '(none)',
                ));

                return self::FAILURE;
            }

            if ($format === 'json') {
                $output->write($this->encodeJson($module->toArray()) . PHP_EOL);

                return self::SUCCESS;
            }

            $this->renderManifestSummary($output, $manifest);
            $output->writeln('');
            $output->writeln(sprintf('<comment>Module:</comment> %s', $moduleName));
            $this->renderModuleTable($output, [$module]);

            return self::SUCCESS;
        }

        if ($format === 'json') {
            $rawManifest = file_get_contents($manifestPath);
            if (!is_string($rawManifest)) {
                $output->writeln(sprintf('<error>Could not read runtime manifest: %s</error>', $manifestPath));

                return self::FAILURE;
            }

            $output->write($rawManifest);

            return self::SUCCESS;
        }

        $this->renderManifestSummary($output, $manifest);
        $output->writeln('');
        $this->renderModuleTable($output, $this->orderedModules($manifest));

        return self::SUCCESS;
    }

    private function renderManifestSummary(OutputInterface $output, RuntimeManifest $manifest): void
    {
        $output->writeln(sprintf('Build mode: %s', $manifest->buildMode));
        $output->writeln(sprintf('Qt version: %s', $manifest->qtVersion));
        $output->writeln(sprintf('Extension version: %s', $manifest->extensionVersion));
        $output->writeln(sprintf('Builder ABI: %s', $manifest->builderAbiVersion));
        $output->writeln(sprintf('Dependency source: %s', $manifest->dependencySource));
        $output->writeln(sprintf('Requested modules: %s', $this->joinList($manifest->requestedModules)));
        $output->writeln(sprintf('Expanded modules: %s', $this->joinList($manifest->expandedModules)));
        $output->writeln(sprintf('Build order: %s', $this->joinList($manifest->buildOrder)));
    }

    /**
     * @param list<RuntimeModuleMetadata> $modules
     */
    private function renderModuleTable(OutputInterface $output, array $modules): void
    {
        $rows = array_map(
            fn (RuntimeModuleMetadata $module): array => [
                $module->module,
                $module->extensionName,
                $this->joinList($module->dependencies),
                (string) $module->classCount,
                $this->joinList($module->namespaces),
            ],
            $modules,
        );

        $table = new Table($output);
        $table->setHeaders(['Module', 'Extension', 'Depends On', 'Classes', 'Namespaces']);
        $table->setRows($rows);
        $table->render();
    }

    /**
     * @return list<RuntimeModuleMetadata>
     */
    private function orderedModules(RuntimeManifest $manifest): array
    {
        $modules = [];
        foreach ($manifest->buildOrder as $moduleName) {
            $module = $manifest->module($moduleName);
            if ($module instanceof RuntimeModuleMetadata) {
                $modules[] = $module;
            }
        }

        foreach ($manifest->modules as $moduleName => $module) {
            if (!$module instanceof RuntimeModuleMetadata) {
                continue;
            }

            if (in_array($moduleName, $manifest->buildOrder, true)) {
                continue;
            }

            $modules[] = $module;
        }

        return $modules;
    }

    /**
     * @param list<string> $items
     */
    private function joinList(array $items): string
    {
        return $items === [] ? '-' : implode(', ', $items);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Could not encode manifest payload as JSON.');
        }

        return $encoded;
    }
}
