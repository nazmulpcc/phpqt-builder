<?php

declare(strict_types=1);

use QtBuilder\Tests\Support\GenerateCommandBuildModeCases;

$cases = new GenerateCommandBuildModeCases();

it('casts const object pointer returns for wrapping', function () use ($cases): void {
    $cases->testGenerateBuildModeCastsConstObjectPointerReturnsForWrapping();
});

it('casts enum parameters back to native types', function () use ($cases): void {
    $cases->testGenerateBuildModeCastsEnumParametersBackToNativeTypes();
});

it('handles const char pointer string returns', function () use ($cases): void {
    $cases->testGenerateBuildModeHandlesConstCharPointerStringReturns();
});

it('treats qbitarray factories as value returns and skips bool out parameters', function () use ($cases): void {
    $cases->testGenerateBuildModeTreatsQBitArrayFactoryAsValueReturnAndSkipsBoolOutParameter();
});

it('skips object double pointer out parameters', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsObjectDoublePointerOutParameters();
});

it('uses fromInt for flag aliases', function () use ($cases): void {
    $cases->testGenerateBuildModeUsesFromIntForFlagAliases();
});

it('handles char strings and skips non const reference parameters', function () use ($cases): void {
    $cases->testGenerateBuildModeHandlesCharStringsAndSkipsNonConstReferenceParameters();
});

it('builds an input only argv constructor bridge', function () use ($cases): void {
    $cases->testGenerateBuildModeBuildsInputOnlyArgvConstructorBridge();
});

it('skips nested result types but keeps nested enums', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsNestedResultTypesButKeepsNestedEnums();
});

it('handles std string conversions', function () use ($cases): void {
    $cases->testGenerateBuildModeHandlesStdStringConversions();
});

it('treats object returns without pointers as value objects', function () use ($cases): void {
    $cases->testGenerateBuildModeTreatsObjectReturnsWithoutPointersAsValueObjects();
});

it('skips nested struct returns but keeps bare enums', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsNestedStructReturnsButKeepsBareEnums();
});

it('skips qualified nested struct returns but keeps qualified enums', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsQualifiedNestedStructReturnsButKeepsQualifiedEnums();
});

it('converts chrono durations to and from integers', function () use ($cases): void {
    $cases->testGenerateBuildModeConvertsChronoDurationsToAndFromIntegers();
});

it('bridges wide strings through qstring', function () use ($cases): void {
    $cases->testGenerateBuildModeBridgesWideStringsThroughQString();
});

it('returns qanystringview values via toString', function () use ($cases): void {
    $cases->testGenerateBuildModeReturnsQAnyStringViewViaToString();
});

it('skips qchar buffer returns', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsQCharBufferReturns();
});

it('copies pointer returns for value types', function () use ($cases): void {
    $cases->testGenerateBuildModeCopiesPointerReturnsForValueTypes();
});

it('skips complex returns instead of casting to scalars', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsComplexReturnsInsteadOfCastingToScalars();
});

it('uses move construction for move only value object returns', function () use ($cases): void {
    $cases->testGenerateBuildModeMoveOnlyValueObjectReturnsUseMoveConstruction();
});

it('uses a move aware bridge for rvalue reference object parameters', function () use ($cases): void {
    $cases->testGenerateBuildModeRvalueReferenceObjectParametersUseMoveAwareBridge();
});
