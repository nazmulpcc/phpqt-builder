<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Preflight\CheckResult;
use QtBuilder\Preflight\CheckStatus;
use QtBuilder\Preflight\PreflightReport;
use QtBuilder\System\CommandResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('doctor', 'Check if system requirements are met.')]
class DoctorCommand extends Command
{
    public function __construct(private readonly SystemInformation $systemInformation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_REQUIRED,
            'Output format: human or json',
            'human',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!\in_array($format, ['human', 'json'], true)) {
            $output->writeln(sprintf('<error>Unsupported format "%s". Use human or json.</error>', $format));

            return self::FAILURE;
        }

        $report = $this->runPreflightChecks();
        $this->renderReport($report, $output, $format);

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    private function runPreflightChecks(): PreflightReport
    {
        return new PreflightReport([
            $this->checkSupportedOs(),
            $this->checkPhpVersion(),
            $this->checkCparserExtension(),
            $this->checkQtDiscovery(),
            $this->checkCppCompiler(),
            $this->checkPhpize(),
            $this->checkPhpConfig(),
            $this->checkMake(),
            $this->checkPkgConfig(),
        ]);
    }

    private function checkSupportedOs(): CheckResult
    {
        $osFamily = $this->systemInformation->getOsFamily();
        $meta = [
            'os_family' => $osFamily,
            'os_name' => $this->systemInformation->getOsName(),
            'kernel' => $this->systemInformation->getKernelVersion(),
            'arch' => $this->systemInformation->getArchitecture(),
        ];

        if (\in_array($osFamily, ['Linux', 'Darwin'], true)) {
            return new CheckResult(
                'supported_os',
                'Supported OS',
                CheckStatus::Pass,
                sprintf(
                    'Detected %s. The current bootstrap path uses phpize, configure, and make.',
                    $osFamily,
                ),
                $meta,
            );
        }

        if ($osFamily === 'Windows') {
            return new CheckResult(
                'supported_os',
                'Supported OS',
                CheckStatus::Fail,
                'Detected Windows. The current bootstrap path is Unix-oriented and expects phpize, configure, and make.',
                $meta + [
                    'hint' => 'Qt/header inspection may still work, but extension bootstrap is not implemented for Windows yet.',
                ],
            );
        }

        return new CheckResult(
            'supported_os',
            'Supported OS',
            CheckStatus::Fail,
            sprintf('Detected unsupported OS family "%s". The current bootstrap path is only implemented for Linux and macOS.', $osFamily),
            $meta,
        );
    }

    private function checkPhpVersion(): CheckResult
    {
        $phpVersion = $this->systemInformation->getPhpVersion();
        $supported = version_compare($phpVersion, '8.4.0', '>=') && version_compare($phpVersion, '9.0.0', '<');

        if ($supported) {
            return new CheckResult(
                'php_version',
                'PHP Version',
                CheckStatus::Pass,
                sprintf('Detected PHP %s (requirement: ^8.4).', $phpVersion),
                ['detected' => $phpVersion, 'constraint' => '^8.4'],
            );
        }

        return new CheckResult(
            'php_version',
            'PHP Version',
            CheckStatus::Fail,
            sprintf('Detected PHP %s, but ^8.4 is required.', $phpVersion),
            ['detected' => $phpVersion, 'constraint' => '^8.4'],
        );
    }

    private function checkCparserExtension(): CheckResult
    {
        if ($this->systemInformation->hasExtension('cparser')) {
            return new CheckResult(
                'php_ext_cparser',
                'PHP Extension cparser',
                CheckStatus::Pass,
                'The ext-cparser extension is loaded.',
            );
        }

        return new CheckResult(
            'php_ext_cparser',
            'PHP Extension cparser',
            CheckStatus::Fail,
            'The ext-cparser extension is missing. Parsing Qt headers will not work until it is installed and loaded.',
            ['hint' => 'See cparser.stub.php for the extension API expected by this project.'],
        );
    }

    private function checkQtDiscovery(): CheckResult
    {
        $qtDetection = $this->systemInformation->detectQt();

        return new CheckResult(
            'qt_discovery',
            'Qt Discovery',
            $qtDetection->isDetected() ? CheckStatus::Pass : CheckStatus::Fail,
            $qtDetection->getMessage(),
            $qtDetection->getMeta(),
        );
    }

    private function checkCppCompiler(): CheckResult
    {
        $candidates = ['c++', 'clang++', 'g++', 'cl'];
        $compiler = $this->findFirstExecutable($candidates);

        if ($compiler !== null) {
            $version = $this->commandSummary([$compiler, '--version']);
            $details = [
                'path' => $compiler,
                'tool' => basename($compiler),
                'candidates' => $this->candidateMap($candidates),
            ];

            if ($version !== null) {
                $details['version'] = $version;
            }

            return new CheckResult(
                'cpp_compiler',
                'C++ Compiler',
                CheckStatus::Pass,
                sprintf('Found compiler: %s.', basename($compiler)),
                $details,
            );
        }

        return new CheckResult(
            'cpp_compiler',
            'C++ Compiler',
            CheckStatus::Fail,
            'No supported C++ compiler found. Install c++, clang++, g++, or cl.',
            [
                'candidates' => $this->candidateMap($candidates),
                'hint' => 'Generated extensions are compiled as C++17 code.',
            ],
        );
    }

    private function checkPhpize(): CheckResult
    {
        $phpize = $this->systemInformation->findExecutable('phpize');

        if ($phpize !== null) {
            $details = ['path' => $phpize];
            $version = $this->commandSummary([$phpize, '--version']);
            if ($version !== null) {
                $details['version'] = $version;
            }

            return new CheckResult(
                'phpize',
                'phpize',
                CheckStatus::Pass,
                'Found phpize.',
                $details,
            );
        }

        return new CheckResult(
            'phpize',
            'phpize',
            CheckStatus::Fail,
            'The phpize executable is required for extension bootstrap but was not found.',
            ['hint' => 'Install the PHP development package that matches the PHP binary you are using.'],
        );
    }

    private function checkPhpConfig(): CheckResult
    {
        $phpConfig = $this->systemInformation->findExecutable('php-config');

        if ($phpConfig !== null) {
            $details = ['path' => $phpConfig];
            $version = $this->commandSummary([$phpConfig, '--version']);
            if ($version !== null) {
                $details['version'] = $version;
            }

            return new CheckResult(
                'php_config',
                'php-config',
                CheckStatus::Pass,
                'Found php-config.',
                $details,
            );
        }

        return new CheckResult(
            'php_config',
            'php-config',
            CheckStatus::Warn,
            'php-config was not found. Configure may still work if PHP development files are installed, but explicit detection will be unavailable.',
            ['hint' => 'Most package managers ship phpize and php-config together in the PHP development package.'],
        );
    }

    private function checkMake(): CheckResult
    {
        $make = $this->systemInformation->findExecutable('make');

        if ($make !== null) {
            return new CheckResult(
                'make',
                'make',
                CheckStatus::Pass,
                'Found make.',
                [
                    'path' => $make,
                    'version' => $this->commandSummary([$make, '--version']),
                    'alternatives' => $this->candidateMap(['ninja', 'jom', 'nmake']),
                ],
            );
        }

        $alternatives = array_filter(
            $this->candidateMap(['ninja', 'jom', 'nmake']),
            static fn(string $path): bool => $path !== '',
        );

        return new CheckResult(
            'make',
            'make',
            CheckStatus::Fail,
            'The current bootstrap path invokes make directly, but make was not found.',
            [
                'alternatives' => $alternatives === [] ? (object) [] : $alternatives,
                'hint' => 'ninja, jom, or nmake may be installed, but the current build bootstrap does not use them yet.',
            ],
        );
    }

    private function checkPkgConfig(): CheckResult
    {
        $pkgConfig = $this->systemInformation->findExecutable('pkg-config');

        if ($pkgConfig !== null) {
            return new CheckResult(
                'pkg_config',
                'pkg-config',
                CheckStatus::Pass,
                'Found pkg-config.',
                [
                    'path' => $pkgConfig,
                    'version' => $this->commandSummary([$pkgConfig, '--version']),
                ],
            );
        }

        return new CheckResult(
            'pkg_config',
            'pkg-config',
            CheckStatus::Warn,
            'pkg-config was not found. Qt library flags may fall back to manual -L/-l resolution.',
            ['hint' => 'Install pkg-config if you want automatic Qt6 module link flags from .pc files.'],
        );
    }

    /**
     * @param list<string> $candidates
     */
    private function findFirstExecutable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $path = $this->systemInformation->findExecutable($candidate);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    private function renderReport(PreflightReport $report, OutputInterface $output, string $format): void
    {
        if ($format === 'json') {
            $output->writeln($this->renderJson($report));

            return;
        }

        $this->renderHuman($report, $output);
    }

    private function renderHuman(PreflightReport $report, OutputInterface $output): void
    {
        $output->writeln('<options=bold>System Preflight</>');

        foreach ($report->getChecks() as $check) {
            $output->writeln(
                sprintf(
                    '%s %s: %s',
                    $this->statusLabel($check->getStatus()),
                    $check->getLabel(),
                    $check->getMessage(),
                ),
            );

            foreach ($this->metaLines($check) as $line) {
                $output->writeln(sprintf('  %s', $line));
            }
        }

        $summary = sprintf(
            'Summary: %d passed, %d warning(s), %d failed.',
            $report->getPassedCount(),
            $report->getWarningCount(),
            $report->getFailedCount(),
        );

        $summaryStatus = $report->getOverallStatus();
        if ($summaryStatus === CheckStatus::Fail) {
            $output->writeln(sprintf('<error>%s</error>', $summary));

            return;
        }

        if ($summaryStatus === CheckStatus::Warn) {
            $output->writeln(sprintf('<comment>%s</comment>', $summary));

            return;
        }

        $output->writeln(sprintf('<info>%s</info>', $summary));
    }

    private function renderJson(PreflightReport $report): string
    {
        $payload = [
            'summary' => [
                'status' => $report->getOverallStatus()->value,
                'passed' => $report->getPassedCount(),
                'warnings' => $report->getWarningCount(),
                'failed' => $report->getFailedCount(),
            ],
            'checks' => array_map(
                static function (CheckResult $check): array {
                    $meta = $check->getMeta();

                    return [
                        'id' => $check->getId(),
                        'label' => $check->getLabel(),
                        'status' => $check->getStatus()->value,
                        'message' => $check->getMessage(),
                        'meta' => $meta === [] ? (object) [] : $meta,
                    ];
                },
                $report->getChecks(),
            ),
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '{"summary":{"status":"fail","passed":0,"warnings":0,"failed":0},"checks":[]}' : $encoded;
    }

    private function statusLabel(CheckStatus $status): string
    {
        return match ($status) {
            CheckStatus::Pass => '<info>[PASS]</info>',
            CheckStatus::Warn => '<comment>[WARN]</comment>',
            CheckStatus::Fail => '<error>[FAIL]</error>',
        };
    }

    /**
     * @return array<int, string>
     */
    private function metaLines(CheckResult $check): array
    {
        $meta = $check->getMeta();
        $lines = [];

        if (isset($meta['os_name'], $meta['kernel'], $meta['arch'])) {
            $lines[] = sprintf('system: %s, kernel %s, arch %s', $meta['os_name'], $meta['kernel'], $meta['arch']);
        }

        if (isset($meta['detected'], $meta['constraint'])) {
            $lines[] = sprintf('requirement: %s, detected: %s', $meta['constraint'], $meta['detected']);
        }

        foreach (['path', 'tool', 'version', 'headers', 'libs', 'host_prefix', 'prefix', 'package', 'cflags'] as $key) {
            if (!isset($meta[$key]) || !is_string($meta[$key]) || $meta[$key] === '') {
                continue;
            }

            $label = str_replace('_', ' ', $key);
            $lines[] = sprintf('%s: %s', $label, $meta[$key]);
        }

        if (isset($meta['headers_exists']) && is_bool($meta['headers_exists'])) {
            $lines[] = sprintf('headers dir exists: %s', $meta['headers_exists'] ? 'yes' : 'no');
        }

        if (isset($meta['libs_exists']) && is_bool($meta['libs_exists'])) {
            $lines[] = sprintf('libs dir exists: %s', $meta['libs_exists'] ? 'yes' : 'no');
        }

        if (isset($meta['hint']) && is_string($meta['hint']) && $meta['hint'] !== '') {
            $lines[] = sprintf('hint: %s', $meta['hint']);
        }

        if (isset($meta['alternatives']) && is_array($meta['alternatives']) && $meta['alternatives'] !== []) {
            $lines[] = sprintf('alternatives: %s', $this->formatNamedPaths($meta['alternatives']));
        }

        if (isset($meta['candidates']) && is_array($meta['candidates']) && $meta['candidates'] !== []) {
            $lines[] = sprintf('searched: %s', $this->formatNamedPaths($meta['candidates']));
        }

        if (isset($meta['attempts']) && is_array($meta['attempts'])) {
            foreach ($meta['attempts'] as $attempt) {
                if (!is_array($attempt)) {
                    continue;
                }

                $tool = is_string($attempt['tool'] ?? null) ? $attempt['tool'] : 'unknown';
                $path = is_string($attempt['path'] ?? null) ? $attempt['path'] : 'not found';
                $exitCode = (int) ($attempt['exit_code'] ?? -1);
                $version = is_string($attempt['version'] ?? null) ? $attempt['version'] : null;

                $line = sprintf('attempt: %s at %s exited %d', $tool, $path, $exitCode);
                if ($version !== null && $version !== '') {
                    $line .= sprintf(' (version %s)', $version);
                }

                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $candidates
     * @return array<string, string>
     */
    private function candidateMap(array $candidates): array
    {
        $resolved = [];

        foreach ($candidates as $candidate) {
            $path = $this->systemInformation->findExecutable($candidate);
            if ($path !== null && $path !== '') {
                $resolved[$candidate] = $path;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $paths
     */
    private function formatNamedPaths(array $paths): string
    {
        $parts = [];

        foreach ($paths as $name => $path) {
            $parts[] = sprintf('%s=%s', $name, $path);
        }

        return implode(', ', $parts);
    }

    /**
     * @param list<string> $command
     */
    private function commandSummary(array $command): ?string
    {
        $result = $this->systemInformation->runCommand($command);

        if (!$result->isSuccessful()) {
            return null;
        }

        return $this->firstMeaningfulLine($result);
    }

    private function firstMeaningfulLine(CommandResult $result): ?string
    {
        $combined = trim($result->getStdout() . "\n" . $result->getStderr());
        if ($combined === '') {
            return null;
        }

        $fallback = null;

        foreach (preg_split('/\R+/', $combined) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $fallback ??= $trimmed;

                if (!str_ends_with($trimmed, ':') && preg_match('/\d/', $trimmed) === 1) {
                    return $trimmed;
                }
            }
        }

        return $fallback;
    }
}
