<?php

declare(strict_types=1);

use QtBuilder\Tests\Runtime\Support\QtRuntimeProcessRunner;

it('reports monolithic build metadata through php --ri qt', function (): void {
    $result = QtRuntimeProcessRunner::runPhpInfo('qt');

    if ($result->isSkipped()) {
        test()->markTestSkipped($result->skipReason());
    }

    expect($result->exitCode())->toBe(
        0,
        sprintf(
            "php --ri qt failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
            $result->stdout(),
            $result->stderr(),
        ),
    );

    expect($result->stdout())->toContain(
        'qt support => enabled',
        'build mode => monolithic',
        'Qt version =>',
        'built modules =>',
        'QtCore',
    );
});

it('reports split build metadata through php --ri qtcore', function (): void {
    $result = QtRuntimeProcessRunner::runPhpInfoWithModules('qtcore', ['QtCore']);

    if ($result->isSkipped()) {
        test()->markTestSkipped($result->skipReason());
    }

    expect($result->exitCode())->toBe(
        0,
        sprintf(
            "php --ri qtcore failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
            $result->stdout(),
            $result->stderr(),
        ),
    );

    expect($result->stdout())->toContain(
        'qtcore support => enabled',
        'build mode => modular',
        'Qt version =>',
        'current module => QtCore',
        'dependency modules => -',
        'built modules =>',
        'loaded modules => QtCore',
    );
});

it('reports split dependency metadata through php --ri qtwidgets', function (): void {
    $result = QtRuntimeProcessRunner::runPhpInfoWithModules('qtwidgets', ['QtWidgets']);

    if ($result->isSkipped()) {
        test()->markTestSkipped($result->skipReason());
    }

    expect($result->exitCode())->toBe(
        0,
        sprintf(
            "php --ri qtwidgets failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
            $result->stdout(),
            $result->stderr(),
        ),
    );

    expect($result->stdout())->toContain(
        'qtwidgets support => enabled',
        'build mode => modular',
        'current module => QtWidgets',
        'dependency modules => QtCore, QtGui',
        'loaded modules => QtCore, QtGui, QtWidgets',
    );
});
