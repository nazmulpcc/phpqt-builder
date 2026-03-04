<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use QtBuilder\Tests\Runtime\Support\QtRuntimeProcessResult;
use QtBuilder\Tests\Runtime\Support\QtRuntimeProcessRunner;

function qt_fixture_path(string $relative): string
{
    return __DIR__ . '/Fixtures/' . ltrim($relative, '/');
}

function qt_temp_dir(string $prefix): string
{
    $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException(sprintf('Could not create temp directory: %s', $path));
    }

    return $path;
}

/**
 * @return array<string, mixed>
 */
function qt_decode_json(string $json): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * @return array{tester: CommandTester, exitCode: int, display: string}
 */
function qt_command_result(Command $command, array $input): array
{
    $tester = new CommandTester($command);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_WARNING && str_contains($message, 'mkdir(): File exists')) {
            return true;
        }

        return false;
    });

    try {
        $exitCode = $tester->execute($input);
    } finally {
        restore_error_handler();
    }

    return [
        'tester' => $tester,
        'exitCode' => $exitCode,
        'display' => $tester->getDisplay(),
    ];
}

function qt_runtime_fixture(string $fixture, array $env = [], int $timeout = 5): QtRuntimeProcessResult
{
    return QtRuntimeProcessRunner::runFixture($fixture, $env, $timeout);
}

function qt_runtime_payload(string $fixture, array $env = [], int $timeout = 5): array
{
    $result = qt_runtime_fixture($fixture, $env, $timeout);

    if ($result->isSkipped()) {
        test()->markTestSkipped($result->skipReason());
    }

    expect($result->exitCode())->toBe(
        0,
        sprintf(
            "Fixture %s failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
            $fixture,
            $result->stdout(),
            $result->stderr(),
        ),
    );

    return $result->payload();
}

function qt_runtime_inline_payload(string $body, array $env = [], int $timeout = 30): array
{
    $result = QtRuntimeProcessRunner::runInline($body, $env, $timeout);

    if ($result->isSkipped()) {
        test()->markTestSkipped($result->skipReason());
    }

    expect($result->exitCode())->toBe(
        0,
        sprintf(
            "Inline runtime test failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
            $result->stdout(),
            $result->stderr(),
        ),
    );

    return $result->payload();
}
