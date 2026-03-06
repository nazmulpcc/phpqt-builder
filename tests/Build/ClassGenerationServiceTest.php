<?php

declare(strict_types=1);

use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Definition\PhpMethod;

it('widens child visibility for abstract public parent contracts', function (): void {
    $service = new ClassGenerationService();
    $method = new ReflectionMethod($service, 'normalizeMethodsAgainstInheritedContracts');

    $child = new PhpMethod(
        name: 'createShader',
        access: 'protected',
        isStatic: false,
        isSignal: false,
        isSlot: false,
        isAbstractMethod: false,
        returnType: '\\Qt\\Quick\\QSGMaterialShader',
        parameters: [],
        overloads: [],
        cppName: 'createShader',
    );

    $parent = new PhpMethod(
        name: 'createShader',
        access: 'public',
        isStatic: false,
        isSignal: false,
        isSlot: false,
        isAbstractMethod: true,
        returnType: '\\Qt\\Quick\\QSGMaterialShader',
        parameters: [],
        overloads: [],
        cppName: 'createShader',
    );

    /** @var list<PhpMethod> $normalized */
    $normalized = $method->invoke($service, [$child], ['createShader' => $parent]);

    expect($normalized)->toHaveCount(1)
        ->and($normalized[0]->access)->toBe('public')
        ->and($normalized[0]->name)->toBe('createShader');
});
