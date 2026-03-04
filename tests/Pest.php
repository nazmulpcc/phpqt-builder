<?php

declare(strict_types=1);

use QtBuilder\Tests\Runtime\Support\QtRuntimeProcessResult;
use QtBuilder\Tests\Runtime\Support\QtRuntimeProcessRunner;

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
