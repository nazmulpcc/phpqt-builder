<?php

declare(strict_types=1);

use QtBuilder\Tests\Runtime\Support\QtRuntimeProcessRunner;

it('loads dependency-ordered split modules for qtwidgets builds', function (): void {
    $result = QtRuntimeProcessRunner::runFixtureWithModules(
        'Modules/qtcore_qtwidgets_smoke.php',
        ['QtWidgets'],
    );

    if ($result->isSkipped()) {
        test()->markTestSkipped($result->skipReason());
    }

    expect($result->exitCode())->toBe(
        0,
        sprintf(
            "Split module runtime fixture failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
            $result->stdout(),
            $result->stderr(),
        ),
    );

    expect($result->payload()['inherits_qobject'] ?? false)->toBeTrue();
});
