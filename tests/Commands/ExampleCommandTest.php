<?php

declare(strict_types=1);

use QtBuilder\Commands\ExampleCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

function example_test_dir(string $prefix): string
{
    $dir = qt_temp_dir($prefix);
    if (!is_dir($dir . '/examples')) {
        mkdir($dir . '/examples', 0777, true);
    }

    return $dir;
}

it('lists runnable examples', function (): void {
    $projectRoot = example_test_dir('qtbuilder-example-command-');
    mkdir($projectRoot . '/examples/login-form', 0777, true);
    mkdir($projectRoot . '/examples/csv-viewer', 0777, true);
    mkdir($projectRoot . '/examples/not-runnable', 0777, true);
    file_put_contents($projectRoot . '/examples/login-form/run.php', "<?php\n");
    file_put_contents($projectRoot . '/examples/csv-viewer/run.php', "<?php\n");

    $command = new ExampleCommand($projectRoot);
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        '--examples-dir' => $projectRoot . '/examples',
        '--list' => true,
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('login-form')
        ->and($tester->getDisplay())->toContain('csv-viewer')
        ->and($tester->getDisplay())->not->toContain('not-runnable');
});

it('runs requested example directly', function (): void {
    $projectRoot = example_test_dir('qtbuilder-example-command-');
    mkdir($projectRoot . '/examples/login-form', 0777, true);
    file_put_contents($projectRoot . '/examples/login-form/run.php', "<?php\n");
    file_put_contents($projectRoot . '/qt.so', 'fake');

    $captured = [];
    $command = new ExampleCommand(
        $projectRoot,
        static function (array $argv, OutputInterface $output) use (&$captured): int {
            $captured = $argv;
            return 0;
        },
        null,
        static fn (string $extension): bool => false,
    );

    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        'name' => 'login-form',
        '--examples-dir' => $projectRoot . '/examples',
        '--php' => '/usr/bin/php',
        '--extension' => $projectRoot . '/qt.so',
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($captured)->toBe([
            '/usr/bin/php',
            '-dextension=' . $projectRoot . '/qt.so',
            $projectRoot . '/examples/login-form/run.php',
        ]);
});

it('skips manual extension loading when qt is already loaded', function (): void {
    $projectRoot = example_test_dir('qtbuilder-example-command-');
    mkdir($projectRoot . '/examples/login-form', 0777, true);
    file_put_contents($projectRoot . '/examples/login-form/run.php', "<?php\n");

    $captured = [];
    $command = new ExampleCommand(
        $projectRoot,
        static function (array $argv, OutputInterface $output) use (&$captured): int {
            $captured = $argv;
            return 0;
        },
        null,
        static fn (string $extension): bool => $extension === 'qt',
    );

    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        'name' => 'login-form',
        '--examples-dir' => $projectRoot . '/examples',
        '--php' => '/usr/bin/php',
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($captured)->toBe([
            '/usr/bin/php',
            $projectRoot . '/examples/login-form/run.php',
        ]);
});

it('prompts for selection when name is omitted', function (): void {
    $projectRoot = example_test_dir('qtbuilder-example-command-');
    mkdir($projectRoot . '/examples/a-first', 0777, true);
    mkdir($projectRoot . '/examples/b-second', 0777, true);
    file_put_contents($projectRoot . '/examples/a-first/run.php', "<?php\n");
    file_put_contents($projectRoot . '/examples/b-second/run.php', "<?php\n");

    $captured = [];
    $command = new ExampleCommand(
        $projectRoot,
        static function (array $argv, OutputInterface $output) use (&$captured): int {
            $captured = $argv;
            return 0;
        },
        static fn (string $label, array $options): string => 'b-second',
    );

    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        '--examples-dir' => $projectRoot . '/examples',
        '--no-extension' => true,
    ]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($captured)->toBe([
            PHP_BINARY,
            $projectRoot . '/examples/b-second/run.php',
        ]);
});

it('fails without a name in non-interactive mode', function (): void {
    $projectRoot = example_test_dir('qtbuilder-example-command-');
    mkdir($projectRoot . '/examples/login-form', 0777, true);
    file_put_contents($projectRoot . '/examples/login-form/run.php', "<?php\n");

    $command = new ExampleCommand($projectRoot);
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        '--examples-dir' => $projectRoot . '/examples',
    ], ['interactive' => false]);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('No example name provided in non-interactive mode');
});
