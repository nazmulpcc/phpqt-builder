<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use function Laravel\Prompts\select;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand('example', 'Run an example app from the examples directory.')]
final class ExampleCommand extends Command
{
    /** @var callable(array<int, string>, OutputInterface): int */
    private $runner;
    /** @var callable(string, array<int, string>): string */
    private $selector;
    /** @var callable(string): bool */
    private $extensionLoadedChecker;

    public function __construct(
        private readonly string $projectRoot = __DIR__ . '/../../',
        ?callable $runner = null,
        ?callable $selector = null,
        ?callable $extensionLoadedChecker = null,
    ) {
        $this->runner = $runner ?? fn (array $command, OutputInterface $output): int => $this->runProcess($command, $output);
        $this->selector = $selector ?? static fn (string $label, array $options): string => (string) select(
            label: $label,
            options: $options,
            scroll: max(5, min(15, count($options))),
        );
        $this->extensionLoadedChecker = $extensionLoadedChecker ?? static fn (string $extension): bool => extension_loaded($extension);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Example name (directory under examples/)')
            ->addOption('examples-dir', null, InputOption::VALUE_REQUIRED, 'Path to examples directory', $this->projectRoot . '/examples')
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'PHP binary path', PHP_BINARY)
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Path to qt extension shared library', $this->projectRoot . '/build/ext/.libs/qt.so')
            ->addOption('no-extension', null, InputOption::VALUE_NONE, 'Do not add -dextension=...')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List available examples and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $examplesDir = (string) $input->getOption('examples-dir');
        $examples = $this->discoverExamples($examplesDir);

        if ($examples === []) {
            $io->error(sprintf('No runnable examples found in: %s', $examplesDir));
            return self::FAILURE;
        }

        if ((bool) $input->getOption('list')) {
            $io->writeln('Available examples:');
            foreach (array_keys($examples) as $name) {
                $io->writeln(' - ' . $name);
            }
            return self::SUCCESS;
        }

        $name = trim((string) ($input->getArgument('name') ?? ''));
        if ($name === '') {
            if (!$input->isInteractive()) {
                $io->error('No example name provided in non-interactive mode. Use `qtb example <name>` or `--list`.');
                return self::FAILURE;
            }

            $name = ($this->selector)('Select an example', array_keys($examples));
        }

        if (!isset($examples[$name])) {
            $io->error(sprintf('Unknown example "%s".', $name));
            $io->writeln('Available examples: ' . implode(', ', array_keys($examples)));
            return self::FAILURE;
        }

        $phpBinary = (string) $input->getOption('php');
        $command = [$phpBinary];
        $useExtension = !(bool) $input->getOption('no-extension');
        $qtAlreadyLoaded = ($this->extensionLoadedChecker)('qt');

        if ($useExtension && !$qtAlreadyLoaded) {
            $extension = (string) $input->getOption('extension');
            if (!is_file($extension)) {
                $io->error(sprintf('Qt extension not found at: %s', $extension));
                $io->writeln('Build it first with: php qtb build');
                return self::FAILURE;
            }
            $command[] = '-dextension=' . $extension;
        }

        $scriptPath = $examples[$name];
        $command[] = $scriptPath;

        $io->writeln(sprintf('<info>Running example:</info> %s', $name));
        $exitCode = ($this->runner)($command, $output);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string>
     */
    private function discoverExamples(string $examplesDir): array
    {
        if (!is_dir($examplesDir)) {
            return [];
        }

        $entries = scandir($examplesDir);
        if ($entries === false) {
            return [];
        }

        $examples = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '_')) {
                continue;
            }

            $dir = rtrim($examplesDir, '/') . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }

            $runScript = $dir . '/run.php';
            if (is_file($runScript)) {
                $examples[$entry] = $runScript;
            }
        }

        ksort($examples);
        return $examples;
    }

    /**
     * @param array<int, string> $command
     */
    private function runProcess(array $command, OutputInterface $output): int
    {
        $process = new Process($command, $this->projectRoot);
        $process->setTimeout(null);
        $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $process->getExitCode() ?? 1;
    }
}
