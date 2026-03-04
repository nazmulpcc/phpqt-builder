<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Support;

use QtBuilder\Commands\GenerateCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateBuildModeRunner
{
    /**
     * @param array<string, mixed> $input
     */
    public static function run(string $fixture, array $input, string $outputPrefix = 'qtbuilder-generate-'): GenerateBuildModeResult
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/' . ltrim($fixture, '/');
        $defaultOutputDir = sys_get_temp_dir() . '/' . $outputPrefix . bin2hex(random_bytes(4));
        $options = [
            '--build-mode' => true,
            '--output' => $defaultOutputDir,
            '--output-subdir' => 'classes',
        ];
        $options = array_replace($options, $input);
        $outputDir = (string) $options['--output'];
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute($options);

        $display = $tester->getDisplay();
        $decoded = json_decode($display, true);

        return new GenerateBuildModeResult(
            $exitCode,
            $display,
            is_array($decoded) ? $decoded : [],
            $outputDir,
            $fixtureRoot,
        );
    }
}
