<?php

declare(strict_types=1);

use QtBuilder\Parsing\ContainerTypeParser;

it('recognizes common qt containers', function (): void {
    $parser = new ContainerTypeParser();

    $stringList = $parser->parse('const QStringList &');
    expect($stringList)->not->toBeNull();
    expect($stringList->kind)->toBe('sequence')
        ->and($stringList->elementType)->toBe('QString');

    $indexList = $parser->parse('QModelIndexList');
    expect($indexList)->not->toBeNull();
    expect($indexList->kind)->toBe('sequence')
        ->and($indexList->elementType)->toBe('QModelIndex');

    $byteMap = $parser->parse('QHash<int, QByteArray>');
    expect($byteMap)->not->toBeNull();
    expect($byteMap->kind)->toBe('hash')
        ->and($byteMap->keyType)->toBe('int')
        ->and($byteMap->valueType)->toBe('QByteArray');

    $variantMap = $parser->parse('QMap<int, QVariant>');
    expect($variantMap)->not->toBeNull();
    expect($variantMap->kind)->toBe('map')
        ->and($variantMap->keyType)->toBe('int')
        ->and($variantMap->valueType)->toBe('QVariant');

    $multiHash = $parser->parse('QMultiHash<int, QString>');
    expect($multiHash)->not->toBeNull();
    expect($multiHash->kind)->toBe('multi_hash')
        ->and($multiHash->keyType)->toBe('int')
        ->and($multiHash->valueType)->toBe('QString');

    $multiMap = $parser->parse('QMultiMap<QString, int>');
    expect($multiMap)->not->toBeNull();
    expect($multiMap->kind)->toBe('multi_map')
        ->and($multiMap->keyType)->toBe('QString')
        ->and($multiMap->valueType)->toBe('int');

    $actionList = $parser->parse('const QList<QAction *> &');
    expect($actionList)->not->toBeNull();
    expect($actionList->kind)->toBe('sequence')
        ->and($actionList->elementType)->toBe('QAction *');

    $constActionList = $parser->parse('const QList<const QAction *> &');
    expect($constActionList)->not->toBeNull();
    expect($constActionList->kind)->toBe('sequence')
        ->and($constActionList->elementType)->toBe('const QAction *');
});

it('rejects unsupported container shapes', function (): void {
    $parser = new ContainerTypeParser();

    expect($parser->parse('QSet<QString>'))->toBeNull();

    $complexList = $parser->parse('QList<std::pair<qreal, QPointF>>');
    expect($complexList)->not->toBeNull();
    expect($complexList->elementType)->toBe('std::pair<qreal, QPointF>');
});
