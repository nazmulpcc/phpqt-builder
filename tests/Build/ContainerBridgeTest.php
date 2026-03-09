<?php

declare(strict_types=1);

use QtBuilder\CodeGen\ContainerBridge;
use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\TypeResolutionContext;

it('resolves container element object types using type-resolution context', function (): void {
    $resolver = new CppClassTypeResolver([
        [
            'name' => 'QAction',
            'qualified_name' => 'QAction',
            'module' => 'QtGui',
        ],
        [
            'name' => 'QAction',
            'qualified_name' => 'Qt3DInput::QAction',
            'module' => 'Qt3DInput',
        ],
    ]);

    $bridge = new ContainerBridge();
    $bridge->setTypeResolutionMetadata(
        $resolver,
        new TypeResolutionContext(
            className: 'QLogicalDevice',
            qualifiedClassName: 'Qt3DInput::QLogicalDevice',
            module: 'Qt3DInput',
            namespace: 'Qt3DInput',
        ),
    );

    expect($bridge->elementPhpType('QAction *'))->toBe('\Qt\Qt3DInput\QAction');
    expect($bridge->classRefs('QList<QAction *>'))->toBe(['\Qt\Qt3DInput\QAction']);
});

