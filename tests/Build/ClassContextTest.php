<?php

declare(strict_types=1);

use QtBuilder\CodeGen\ClassContext;
use QtBuilder\CodeGen\TypeBridge;
use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;

it('resolves ambiguous bare nested class refs to owner-specific include ids', function (): void {
    $phpClass = new PhpClass(
        name: 'QDirListing',
        parent: null,
        isAbstract: false,
        isCopyConstructible: true,
        hasPublicConstructor: true,
        hasPublicDestructor: true,
        properties: [],
        methods: [
            new PhpMethod(
                name: 'begin',
                access: 'public',
                isStatic: false,
                isSignal: false,
                isSlot: false,
                isAbstractMethod: false,
                returnType: 'const_iterator',
                parameters: [],
                overloads: [],
            ),
        ],
        signals: [],
        nativeCppType: 'QDirListing',
        generationId: 'qdirlisting',
    );

    $context = new ClassContext(
        phpClass: $phpClass,
        namespace: 'Qt\\Core',
        typeBridge: new TypeBridge(),
        classMetadata: [
            'qdirlisting' => [
                'name' => 'QDirListing',
                'namespace' => 'Qt\\Core',
                'generation_id' => 'qdirlisting',
                'qualified_name' => 'QDirListing',
                'module' => 'QtCore',
            ],
            'const_iterator__qdirlisting' => [
                'name' => 'const_iterator',
                'namespace' => 'Qt\\Core\\QDirListing',
                'generation_id' => 'const_iterator__qdirlisting',
                'qualified_name' => 'QDirListing::const_iterator',
                'module' => 'QtCore',
            ],
            'const_iterator__qjsonarray' => [
                'name' => 'const_iterator',
                'namespace' => 'Qt\\Core\\QJsonArray',
                'generation_id' => 'const_iterator__qjsonarray',
                'qualified_name' => 'QJsonArray::const_iterator',
                'module' => 'QtCore',
            ],
        ],
    );

    expect($context->requiredIncludes)->toBe(['qt_const_iterator__qdirlisting.h'])
        ->not->toContain('qt_const_iterator.h');
});
