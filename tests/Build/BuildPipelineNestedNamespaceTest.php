<?php

declare(strict_types=1);

use QtBuilder\Build\BuildPipeline;
use QtBuilder\Scanning\HeaderCandidate;
use QtBuilder\Tests\Support\FakeExtensionBootstrapper;

it('derives nested php namespaces from candidate qualified names', function (): void {
    $pipeline = new BuildPipeline(new FakeExtensionBootstrapper());

    $method = new ReflectionMethod($pipeline, 'classNamespaces');
    /** @var array<string, string> $namespaces */
    $namespaces = $method->invoke($pipeline, [
        new HeaderCandidate(
            module: 'QtCore',
            className: 'QNestedOwner',
            publicHeader: '/tmp/QNestedOwner',
            parseHeader: '/tmp/qnestedowner.h',
            qualifiedClassName: 'QNestedOwner',
        ),
        new HeaderCandidate(
            module: 'QtCore',
            className: 'Used',
            publicHeader: '/tmp/QNestedOwner',
            parseHeader: '/tmp/qnestedowner.h',
            qualifiedClassName: 'QNestedOwner::Used',
        ),
    ]);

    expect($namespaces['QNestedOwner'] ?? null)->toBe('Qt\\Core')
        ->and($namespaces['QNestedOwner::Used'] ?? null)->toBe('Qt\\Core\\QNestedOwner');
});

it('resolves generated class key namespaces for nested class identities', function (): void {
    $pipeline = new BuildPipeline(new FakeExtensionBootstrapper());

    $method = new ReflectionMethod($pipeline, 'classPhpNamespaceForKey');

    expect($method->invoke($pipeline, 'QNestedOwner::Used', ['QNestedOwner::Used' => 'QtCore']))
        ->toBe('Qt\\Core\\QNestedOwner')
        ->and($method->invoke($pipeline, 'Qt3DInput::QInputSequence', ['Qt3DInput::QInputSequence' => 'Qt3DInput']))
            ->toBe('Qt\\Qt3DInput');
});
