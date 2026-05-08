<?php

declare(strict_types=1);

use QtBuilder\Build\SupplementalClassCandidateResolver;
use QtBuilder\Scanning\HeaderCandidate;

it('does not reintroduce classes filtered by exposure policy', function (): void {
    $resolver = new SupplementalClassCandidateResolver();

    $candidate = new HeaderCandidate(
        module: 'QtTest',
        className: 'QAbstractItemModelTester',
        publicHeader: '/tmp/QtTest/QAbstractItemModelTester',
        parseHeader: '/tmp/QtTest/qabstractitemmodeltester.h',
    );

    $supplemental = $resolver->resolve(
        'QTestAccessibility',
        $candidate,
        ['QtCore', 'QtGui', 'QtWidgets', 'QtTest'],
        [],
        [],
        'missing_value_object',
    );

    expect($supplemental)->toBeNull();
});
