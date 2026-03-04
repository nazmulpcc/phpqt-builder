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
