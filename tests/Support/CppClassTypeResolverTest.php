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

it('maps nested class types to owner-scoped php namespaces', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'QBluetoothServiceInfo', 'qualified_name' => 'QBluetoothServiceInfo', 'module' => 'QtBluetooth'],
        ['name' => 'Sequence', 'qualified_name' => 'QBluetoothServiceInfo::Sequence', 'module' => 'QtBluetooth'],
    ]);

    $context = TypeResolutionContext::fromNames('QBluetoothServiceInfo', 'QBluetoothServiceInfo');

    expect($resolver->resolvePhpType('QBluetoothServiceInfo::Sequence', $context, 'Qt\\Bluetooth'))
        ->toBe('\\Qt\\Bluetooth\\QBluetoothServiceInfo\\Sequence')
        ->and($resolver->resolvePhpType('QBluetoothServiceInfo', $context, 'Qt\\Bluetooth'))
        ->toBe('QBluetoothServiceInfo');
});

it('resolves bare nested member names against the current owner class', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'QFont', 'qualified_name' => 'QFont', 'module' => 'QtGui'],
        ['name' => 'Tag', 'qualified_name' => 'QFont::Tag', 'module' => 'QtGui'],
        ['name' => 'Tag', 'qualified_name' => 'QOther::Tag', 'module' => 'QtGui'],
    ]);
    $context = TypeResolutionContext::fromNames('QFont', 'QFont');

    expect($resolver->canonicalizeType('Tag', $context))->toBe('QFont::Tag');
});

it('canonicalizes nested class references inside template arguments', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'QLowEnergyAdvertisingParameters', 'qualified_name' => 'QLowEnergyAdvertisingParameters', 'module' => 'QtBluetooth'],
        ['name' => 'AddressInfo', 'qualified_name' => 'QLowEnergyAdvertisingParameters::AddressInfo', 'module' => 'QtBluetooth'],
    ]);
    $context = TypeResolutionContext::fromNames('QLowEnergyAdvertisingParameters', 'QLowEnergyAdvertisingParameters');

    expect($resolver->canonicalizeType('QList<AddressInfo>', $context))
        ->toBe('QList<QLowEnergyAdvertisingParameters::AddressInfo>')
        ->and($resolver->canonicalizeType('const QList<AddressInfo> &', $context))
        ->toBe('const QList<QLowEnergyAdvertisingParameters::AddressInfo> &');
});

it('does not downgrade unresolved qualified names to unrelated bare-name matches', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'Key', 'qualified_name' => 'QPixmapCache::Key', 'module' => 'QtGui'],
    ]);
    $context = TypeResolutionContext::fromNames('QKeyCombination', 'QKeyCombination');

    expect($resolver->resolvePhpClassIdentity('Qt::Key', $context))->toBeNull()
        ->and($resolver->resolvePhpType('Qt::Key', $context, 'Qt\\Core'))->toBeNull()
        ->and($resolver->canonicalizeType('Qt::Key', $context))->toBe('Qt::Key');
});

it('does not bind non-q-prefixed bare names to unique classes from a different module', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'Key', 'qualified_name' => 'QPixmapCache::Key', 'module' => 'QtGui'],
    ]);
    $context = new TypeResolutionContext(
        className: 'QMediaMetaData',
        qualifiedClassName: 'QMediaMetaData',
        module: 'QtMultimedia',
        namespace: null,
    );

    expect($resolver->resolveQualifiedClassName('Key', $context))->toBeNull();
});

it('does not bind non-q-prefixed bare nested names to a different owner in the same module', function (): void {
    $resolver = new CppClassTypeResolver([
        ['name' => 'QByteArray', 'qualified_name' => 'QByteArray', 'module' => 'QtCore'],
        ['name' => 'const_iterator', 'qualified_name' => 'QJsonObject::const_iterator', 'module' => 'QtCore'],
    ]);
    $context = new TypeResolutionContext(
        className: 'QByteArray',
        qualifiedClassName: 'QByteArray',
        module: 'QtCore',
        namespace: null,
    );

    expect($resolver->resolveQualifiedClassName('const_iterator', $context))->toBeNull()
        ->and($resolver->canonicalizeType('const_iterator', $context))->toBe('const_iterator');
});
