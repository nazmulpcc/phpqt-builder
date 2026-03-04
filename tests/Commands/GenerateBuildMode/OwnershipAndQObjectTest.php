<?php

declare(strict_types=1);

use QtBuilder\Tests\Support\GenerateCommandBuildModeCases;

$cases = new GenerateCommandBuildModeCases();

it('uses nullable unions for optional value object parameters', function () use ($cases): void {
    $cases->testGenerateBuildModeUsesNullableUnionForOptionalValueObjectParameters();
});

it('skips methods with value object dependencies outside the allow list', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsMethodsWithValueObjectDependenciesOutsideAllowList();
});

it('uses nullable unions for optional qobject parameters', function () use ($cases): void {
    $cases->testGenerateBuildModeUsesNullableUnionForOptionalQObjectParameters();
});

it('transfers ownership for layout attachment methods', function () use ($cases): void {
    $cases->testGenerateBuildModeTransfersOwnershipForLayoutAttachmentMethods();
});

it('uses the automatic qobject ownership probe', function () use ($cases): void {
    $cases->testGenerateBuildModeUsesAutomaticQObjectOwnershipProbe();
});

it('adds qobject property apis and handlers', function () use ($cases): void {
    $cases->testGenerateBuildModeAddsQObjectPropertyApisAndHandlers();
});

it('adds qobject property handlers to derived classes', function () use ($cases): void {
    $cases->testGenerateBuildModeAddsQObjectPropertyHandlersToDerivedClasses();
});

it('uses the automatic standard item ownership probe', function () use ($cases): void {
    $cases->testGenerateBuildModeUsesAutomaticStandardItemOwnershipProbe();
});

it('uses the automatic table widget item ownership probe', function () use ($cases): void {
    $cases->testGenerateBuildModeUsesAutomaticTableWidgetItemOwnershipProbe();
});

it('transfers qevent ownership for post event', function () use ($cases): void {
    $cases->testGenerateBuildModeTransfersQEventOwnershipForPostEvent();
});

it('guards instance methods when native pointers are missing', function () use ($cases): void {
    $cases->testGenerateBuildModeGuardsInstanceMethodsWhenNativePtrIsMissing();
});
