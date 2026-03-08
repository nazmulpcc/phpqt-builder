<?php

declare(strict_types=1);

use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\TypeResolutionContext;

it('prefers the current class namespace when canonicalizing bare class references', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'QNodeId', 'qualified_name' => 'Qt3DCore::QNodeId', 'module' => 'Qt3DCore'],
        ['name' => 'QEntity', 'qualified_name' => 'Qt3DCore::QEntity', 'module' => 'Qt3DCore'],
    ]);

    $context = TypeResolutionContext::fromNames('QNodeId', 'Qt3DCore::QNodeId');

    expect($resolver->canonicalizeType('QNodeId', $context))->toBe('Qt3DCore::QNodeId')
        ->and($resolver->canonicalizeType('const QNodeId &', $context))->toBe('const Qt3DCore::QNodeId &')
        ->and($resolver->resolvePhpClassIdentity('Qt3DCore::QNodeId', $context))->toBe('QNodeId');
});

it('leaves ambiguous bare class references unchanged', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'QThing', 'qualified_name' => 'Qt3DCore::QThing', 'module' => 'Qt3DCore'],
        ['name' => 'QThing', 'qualified_name' => 'Qt3DRender::QThing', 'module' => 'Qt3DRender'],
    ]);

    $context = TypeResolutionContext::fromNames('QSomethingElse', 'Qt3DExtras::QSomethingElse');

    expect($resolver->canonicalizeType('QThing', $context))->toBe('QThing')
        ->and($resolver->resolvePhpClassIdentity('QThing', $context))->toBeNull();
});

it('canonicalizes nested enum owners within the current namespace', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'QTextureData', 'qualified_name' => 'Qt3DRender::QTextureData', 'module' => 'Qt3DRender'],
        ['name' => 'QAbstractTexture', 'qualified_name' => 'Qt3DRender::QAbstractTexture', 'module' => 'Qt3DRender'],
        ['name' => 'QTextureWrapMode', 'qualified_name' => 'Qt3DRender::QTextureWrapMode', 'module' => 'Qt3DRender'],
    ]);

    $context = TypeResolutionContext::fromNames('QTextureData', 'Qt3DRender::QTextureData');

    expect($resolver->canonicalizeType('QAbstractTexture::Target', $context))->toBe('Qt3DRender::QAbstractTexture::Target')
        ->and($resolver->canonicalizeType('const QTextureWrapMode::WrapMode &', $context))->toBe('const Qt3DRender::QTextureWrapMode::WrapMode &');
});

it('leaves ambiguous nested enum owners unchanged', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'QThing', 'qualified_name' => 'Qt3DCore::QThing', 'module' => 'Qt3DCore'],
        ['name' => 'QThing', 'qualified_name' => 'Qt3DRender::QThing', 'module' => 'Qt3DRender'],
    ]);

    $context = TypeResolutionContext::fromNames('QSomethingElse', 'Qt3DExtras::QSomethingElse');

    expect($resolver->canonicalizeType('QThing::Mode', $context))->toBe('QThing::Mode');
});

it('prefers same-module matches over unqualified exact bare-name entries', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'QBuffer', 'qualified_name' => 'QBuffer', 'module' => 'QtCore'],
        ['name' => 'QBuffer', 'qualified_name' => 'Qt3DCore::QBuffer', 'module' => 'Qt3DCore'],
    ]);

    $context = TypeResolutionContext::fromNames('QAttribute', 'Qt3DCore::QAttribute');

    expect($resolver->canonicalizeType('QBuffer *', $context))->toBe('Qt3DCore::QBuffer *');
});
