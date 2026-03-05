<?php

declare(strict_types=1);

use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;

it('detects signal and slot methods via annotations', function (): void {
    if (!method_exists(\CParser\Cursor::class, 'getAnnotations')) {
        test()->markTestSkipped('ext-cparser does not expose cursor annotations.');
    }

    $fixtureRoot = qt_fixture_path('signals-qt');
    $includeRoot = $fixtureRoot . '/include';
    $header = $includeRoot . '/QtCore/qsignalfixture.h';

    $inspector = new QtClassInspector(new ClangArgumentBuilder([
        $includeRoot,
        $includeRoot . '/QtCore',
    ]));

    $classData = $inspector->inspect($header, 'QSignalFixture');
    expect($classData)->not->toBeNull();

    $methods = [];
    foreach ($classData['methods'] as $method) {
        $methods[$method['name']] = $method;
    }

    expect($methods)->toHaveKeys([
        'plainMethod',
        'setValue',
        'resetValue',
        'triggered',
        'valueChanged',
    ]);

    expect($methods['plainMethod']['is_signal'])->toBeFalse()
        ->and($methods['plainMethod']['is_slot'])->toBeFalse()
        ->and($methods['setValue']['is_signal'])->toBeFalse()
        ->and($methods['setValue']['is_slot'])->toBeTrue()
        ->and($methods['setValue']['access'])->toBe('public')
        ->and($methods['resetValue']['is_signal'])->toBeFalse()
        ->and($methods['resetValue']['is_slot'])->toBeTrue()
        ->and($methods['resetValue']['access'])->toBe('protected')
        ->and($methods['triggered']['is_signal'])->toBeTrue()
        ->and($methods['triggered']['is_slot'])->toBeFalse()
        ->and($methods['triggered']['access'])->toBe('public')
        ->and($methods['valueChanged']['is_signal'])->toBeTrue()
        ->and($methods['valueChanged']['is_slot'])->toBeFalse();
});

it('detects final methods via cursor kind 404', function (): void {
    $fixtureRoot = qt_fixture_path('policy-qt');
    $includeRoot = $fixtureRoot . '/include';
    $header = $includeRoot . '/QtCore/qfinalvirtualthing.h';

    $inspector = new QtClassInspector(new ClangArgumentBuilder([
        $includeRoot,
        $includeRoot . '/QtCore',
    ]));

    $classData = $inspector->inspect($header, 'QFinalVirtualThing');
    expect($classData)->not->toBeNull();

    $methods = [];
    foreach ($classData['methods'] as $method) {
        $methods[$method['name']] = $method;
    }

    expect($methods)->toHaveKey('value');
    expect($methods['value']['is_virtual'])->toBeTrue()
        ->and($methods['value']['is_final'])->toBeTrue();
});

it('extracts enum constants with scalar values', function (): void {
    $fixtureRoot = qt_fixture_path('policy-qt');
    $includeRoot = $fixtureRoot . '/include';
    $header = $includeRoot . '/QtCore/qenumholder.h';

    $inspector = new QtClassInspector(new ClangArgumentBuilder([
        $includeRoot,
        $includeRoot . '/QtCore',
    ]));

    $classData = $inspector->inspect($header, 'QEnumHolder');
    expect($classData)->not->toBeNull();

    $constants = [];
    foreach ($classData['enum_constants'] as $constant) {
        $constants[$constant['name']] = $constant;
    }

    expect($constants)->toHaveKeys(['Off', 'On'])
        ->and($constants['Off']['enum_name'])->toBe('Mode')
        ->and($constants['Off']['value'])->toBe(0)
        ->and($constants['On']['value'])->toBe(1);
});
