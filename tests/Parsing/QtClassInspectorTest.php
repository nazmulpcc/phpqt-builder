<?php

declare(strict_types=1);

use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;

function namespacedQtInspector(): QtClassInspector
{
    $fixtureRoot = qt_fixture_path('namespaced-qt');
    $includeRoot = $fixtureRoot . '/include';

    return new QtClassInspector(new ClangArgumentBuilder([
        $includeRoot,
        $includeRoot . '/Qt3DCore',
        $includeRoot . '/QtGui',
    ]));
}

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

it('reads constructor access directly from cparser output', function (): void {
    $fixtureRoot = qt_fixture_path('policy-qt');
    $includeRoot = $fixtureRoot . '/include';
    $header = $includeRoot . '/QtCore/qprivaterefconstructorthing.h';

    $inspector = new QtClassInspector(new ClangArgumentBuilder([
        $includeRoot,
        $includeRoot . '/QtCore',
    ]));

    $classData = $inspector->inspect($header, 'QPrivateRefConstructorThing');
    expect($classData)->not->toBeNull();

    $ctors = array_values(array_filter(
        $classData['methods'],
        static fn (array $method): bool => $method['name'] === 'QPrivateRefConstructorThing',
    ));

    $ctorAccessByArity = [];
    foreach ($ctors as $ctor) {
        $ctorAccessByArity[count($ctor['parameters'])] = $ctor['access'];
    }

    expect($ctors)->toHaveCount(2)
        ->and($ctorAccessByArity[0] ?? null)->toBe('public')
        ->and($ctorAccessByArity[1] ?? null)->toBe('private');
});

it('reports private-only constructors as private', function (): void {
    $fixtureRoot = qt_fixture_path('policy-qt');
    $includeRoot = $fixtureRoot . '/include';
    $header = $includeRoot . '/QtCore/quninstantiablething.h';

    $inspector = new QtClassInspector(new ClangArgumentBuilder([
        $includeRoot,
        $includeRoot . '/QtCore',
    ]));

    $classData = $inspector->inspect($header, 'QUninstantiableThing');
    expect($classData)->not->toBeNull();

    $ctors = array_values(array_filter(
        $classData['methods'],
        static fn (array $method): bool => $method['name'] === 'QUninstantiableThing',
    ));

    expect($ctors)->toHaveCount(1)
        ->and($ctors[0]['access'])->toBe('private');
});

it('finds namespaced classes from the requested header', function (): void {
    $fixtureRoot = qt_fixture_path('namespaced-qt');
    $header = $fixtureRoot . '/include/Qt3DCore/qnode.h';

    $inspector = namespacedQtInspector();
    $classData = $inspector->inspect($header, 'QNode');

    expect($classData)->not->toBeNull()
        ->and($classData['name'])->toBe('QNode')
        ->and($classData['bases'])->toBe([])
        ->and(array_column($classData['methods'], 'name'))->toContain('setEnabled');
});

it('supports qualified lookup for namespaced classes', function (): void {
    $fixtureRoot = qt_fixture_path('namespaced-qt');
    $header = $fixtureRoot . '/include/Qt3DCore/qnode.h';

    $inspector = namespacedQtInspector();
    $inspector->parse($header);
    $class = $inspector->findClass('Qt3DCore::QNode', $header);

    expect($class)->not->toBeNull()
        ->and($class?->getSpelling())->toBe('QNode');
});

it('prefers the namespaced class defined in the requested header over included collisions', function (): void {
    $fixtureRoot = qt_fixture_path('namespaced-qt');
    $header = $fixtureRoot . '/include/Qt3DCore/qtransform.h';

    $inspector = namespacedQtInspector();
    $classData = $inspector->inspect($header, 'QTransform');

    expect($classData)->not->toBeNull()
        ->and($classData['bases'])->toBe(['QComponent'])
        ->and(array_column($classData['methods'], 'name'))->toContain('setTranslation')
        ->and(array_column($classData['methods'], 'name'))->not->toContain('map');
});

it('filters Q_GADGET marker methods during inspection', function (): void {
    $fixtureRoot = qt_fixture_path('policy-qt');
    $includeRoot = $fixtureRoot . '/include';
    $header = $includeRoot . '/QtCore/qgadgetmarkerthing.h';

    $inspector = new QtClassInspector(new ClangArgumentBuilder([
        $includeRoot,
        $includeRoot . '/QtCore',
    ]));

    $classData = $inspector->inspect($header, 'QGadgetMarkerThing');
    expect($classData)->not->toBeNull()
        ->and(array_column($classData['methods'], 'name'))->toContain('value')
        ->and(array_column($classData['methods'], 'name'))->not->toContain('qt_check_for_QGADGET_macro');
});

it('filters Q_OBJECT marker methods during inspection', function (): void {
    $fixtureRoot = qt_fixture_path('policy-qt');
    $includeRoot = $fixtureRoot . '/include';
    $header = $includeRoot . '/QtCore/qobjectmarkerthing.h';

    $inspector = new QtClassInspector(new ClangArgumentBuilder([
        $includeRoot,
        $includeRoot . '/QtCore',
    ]));

    $classData = $inspector->inspect($header, 'QObjectMarkerThing');
    expect($classData)->not->toBeNull()
        ->and(array_column($classData['methods'], 'name'))->toContain('ping')
        ->and(array_column($classData['methods'], 'name'))->not->toContain('qt_static_metacall')
        ->and(array_column($classData['methods'], 'name'))->not->toContain('qt_metacall')
        ->and(array_column($classData['methods'], 'name'))->not->toContain('qt_metacast');
});
