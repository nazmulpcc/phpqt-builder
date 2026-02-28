<?php

namespace QtBuilder\Commands;

use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Preflight\CheckResult;
use QtBuilder\Preflight\CheckStatus;
use QtBuilder\Preflight\PreflightReport;
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
            $this->checkBuildTool(),
            $this->checkCmake(),
        ]);
    }

    private function checkSupportedOs(): CheckResult
    {
        $osFamily = $this->systemInformation->getOsFamily();
        $supported = ['Linux', 'Darwin'];

        if (\in_array($osFamily, $supported, true)) {
            return new CheckResult(
                'supported_os',
                'Supported OS',
                CheckStatus::Pass,
                sprintf(
                    'Detected %s (%s, %s).',
                    $this->systemInformation->getOsName(),
                    $this->systemInformation->getKernelVersion(),
                    $this->systemInformation->getArchitecture(),
                ),
                [
                    'os_family' => $osFamily,
                    'os_name' => $this->systemInformation->getOsName(),
                    'kernel' => $this->systemInformation->getKernelVersion(),
                    'arch' => $this->systemInformation->getArchitecture(),
                ],
            );
        }

        return new CheckResult(
            'supported_os',
            'Supported OS',
            CheckStatus::Fail,
            sprintf('Detected unsupported OS family "%s". Supported families: Linux, Darwin.', $osFamily),
            ['os_family' => $osFamily],
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
            'The ext-cparser extension is missing. Install or enable it before continuing.',
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
        $compiler = $this->findFirstExecutable(['c++', 'g++', 'clang++']);

        if ($compiler !== null) {
            return new CheckResult(
                'cpp_compiler',
                'C++ Compiler',
                CheckStatus::Pass,
                sprintf('Found compiler: %s.', basename($compiler)),
                ['path' => $compiler],
            );
        }

        return new CheckResult(
            'cpp_compiler',
            'C++ Compiler',
            CheckStatus::Fail,
            'No C++ compiler found (c++, g++, or clang++).',
        );
    }

    private function checkPhpize(): CheckResult
    {
        $phpize = $this->systemInformation->findExecutable('phpize');

        if ($phpize !== null) {
            return new CheckResult(
                'phpize',
                'phpize',
                CheckStatus::Pass,
                'Found phpize.',
                ['path' => $phpize],
            );
        }

        return new CheckResult(
            'phpize',
            'phpize',
            CheckStatus::Fail,
            'The phpize executable is required but was not found.',
        );
    }

    private function checkBuildTool(): CheckResult
    {
        $buildTool = $this->findFirstExecutable(['make', 'ninja']);

        if ($buildTool !== null) {
            return new CheckResult(
                'build_tool',
                'Build Tool',
                CheckStatus::Pass,
                sprintf('Found build tool: %s.', basename($buildTool)),
                ['path' => $buildTool],
            );
        }

        return new CheckResult(
            'build_tool',
            'Build Tool',
            CheckStatus::Fail,
            'No build tool found. Install make or ninja.',
        );
    }

    private function checkCmake(): CheckResult
    {
        $cmake = $this->systemInformation->findExecutable('cmake');

        if ($cmake !== null) {
            return new CheckResult(
                'cmake',
                'CMake',
                CheckStatus::Pass,
                'Found CMake.',
                ['path' => $cmake],
            );
        }

        return new CheckResult(
            'cmake',
            'CMake',
            CheckStatus::Warn,
            'CMake was not found. It is optional for now, but recommended.',
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
}
