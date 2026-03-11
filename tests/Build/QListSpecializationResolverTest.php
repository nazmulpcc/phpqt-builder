<?php

declare(strict_types=1);

use QtBuilder\Containers\QListSpecializationResolver;

it('resolves supported QList specializations and aliases', function (): void {
    $resolver = new QListSpecializationResolver();

    $pointList = $resolver->specializationFor('const QList<QPoint> &');
    expect($pointList)->not->toBeNull();
    expect($pointList->className)->toBe('QListOfQPoint')
        ->and($pointList->rawType)->toBe('QList<QPoint>')
        ->and($pointList->elementCppType)->toBe('QPoint')
        ->and($pointList->elementPhpType)->toBe('QPoint')
        ->and($pointList->nativeIncludes)->toBe(['<QList>', '<QPoint>'])
        ->and($pointList->nativeAliasOf)->toBe('QList<QPoint>');

    $stringList = $resolver->specializationFor('QStringList');
    expect($stringList)->not->toBeNull();
    expect($stringList->className)->toBe('QStringList')
        ->and($stringList->rawType)->toBe('QStringList')
        ->and($stringList->elementCppType)->toBe('QString')
        ->and($stringList->elementPhpType)->toBe('string')
        ->and($stringList->nativeIncludes)->toBe(['<QStringList>', '<QString>'])
        ->and($stringList->nativeAliasOf)->toBeNull();
});

it('rejects unsupported QList element shapes', function (): void {
    $resolver = new QListSpecializationResolver();

    expect($resolver->specializationFor('QList<QPair<QString, QString>>'))->toBeNull()
        ->and($resolver->specializationFor('QList<QPointer<QObject>>'))->toBeNull()
        ->and($resolver->specializationFor('QVector<QPoint>'))->toBeNull();
});

it('derives stable QListOf names for nested element types', function (): void {
    $resolver = new QListSpecializationResolver();

    $nestedList = $resolver->specializationFor('QList<AddressInfo>');
    expect($nestedList)->not->toBeNull();
    expect($nestedList->className)->toBe('QListOfAddressInfo')
        ->and($nestedList->elementPhpType)->toBe('AddressInfo')
        ->and($nestedList->nativeIncludes)->toBe(['<QList>', '<AddressInfo>']);
});

it('only exposes synthetic QList element methods when the element type is supported', function (): void {
    $resolver = new QListSpecializationResolver();

    $pointList = $resolver->specializationFor('QList<QPoint>');
    expect($pointList)->not->toBeNull();

    $withoutElement = $resolver->buildPhpClass($pointList, []);
    expect(array_map(static fn ($method): string => $method->name, $withoutElement->methods))
        ->toBe(['count', 'size', 'isEmpty', 'clear']);

    $withElement = $resolver->buildPhpClass($pointList, ['QPoint']);
    expect(array_map(static fn ($method): string => $method->name, $withElement->methods))
        ->toBe(['count', 'size', 'isEmpty', 'clear', 'appendItem', 'itemAt']);

    $stringList = $resolver->specializationFor('QStringList');
    expect($stringList)->not->toBeNull();

    $scalarElement = $resolver->buildPhpClass($stringList, []);
    expect(array_map(static fn ($method): string => $method->name, $scalarElement->methods))
        ->toBe(['count', 'size', 'isEmpty', 'clear', 'appendItem', 'itemAt'])
        ->and($scalarElement->methods[4]->parameters[0]->phpType)->toBe('string')
        ->and($scalarElement->methods[5]->returnType)->toBe('string');
});
