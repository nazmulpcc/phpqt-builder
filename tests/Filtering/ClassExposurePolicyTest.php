<?php

declare(strict_types=1);

use QtBuilder\Filtering\ClassExposurePolicy;

it('no longer filters view classes by suffix', function (): void {
    $policy = new ClassExposurePolicy();

    $decision = $policy->decideClassName('QAbstractItemView');

    expect($decision->accepted)->toBeTrue()
        ->and($decision->reasonCode)->toBeNull();
});

it('keeps iterator classes filtered by suffix', function (): void {
    $policy = new ClassExposurePolicy();

    $decision = $policy->decideClassName('QJSValueIterator');

    expect($decision->accepted)->toBeFalse()
        ->and($decision->reasonCode)->toBe('class_filtered');
});

it('explicitly filters the internal qml placeholder type', function (): void {
    $policy = new ClassExposurePolicy();

    $decision = $policy->decideClassName('QQmlTypeNotAvailable');

    expect($decision->accepted)->toBeFalse()
        ->and($decision->reasonCode)->toBe('class_filtered');
});

it('filters qtest accessibility because its public header defines link-visible helpers', function (): void {
    $policy = new ClassExposurePolicy();

    $decision = $policy->decideClassName('QTestAccessibility');

    expect($decision->accepted)->toBeFalse()
        ->and($decision->reasonCode)->toBe('class_filtered');
});

it('accepts whitelisted concrete classes that would otherwise match prefix or suffix filters', function (): void {
    $policy = new ClassExposurePolicy();

    $whitelisted = [
        'QPropertyAnimation',
        'QSequentialAnimationGroup',
        'QCryptographicHash',
        'QCborMap',
        'QQmlPropertyMap',
        'QDirIterator',
        'QTreeWidgetItemIterator',
        'QStringMatcher',
        'QByteArrayMatcher',
        'QTextList',
        'QSignalSpy',
        'QTestEventList',
    ];

    foreach ($whitelisted as $className) {
        $decision = $policy->decideClassName($className);
        expect($decision->accepted)->toBeTrue("Expected {$className} to be accepted by ALWAYS_EXPOSE")
            ->and($decision->reasonCode)->toBeNull();
    }
});

it('keeps generic containers and low-level iterators filtered', function (): void {
    $policy = new ClassExposurePolicy();

    $filtered = [
        'QProperty',
        'QSequentialIterator',
        'QHash',
        'QMap',
        'QList',
        'QSet',
        'QListIterator',
        'QBitRef',
        'QJsonValueRef',
    ];

    foreach ($filtered as $className) {
        $decision = $policy->decideClassName($className);
        expect($decision->accepted)->toBeFalse("Expected {$className} to be filtered")
            ->and($decision->reasonCode)->toBe('class_filtered');
    }
});
