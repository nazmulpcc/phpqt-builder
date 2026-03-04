<?php

declare(strict_types=1);

use QtBuilder\Tests\Support\GenerateCommandBuildModeCases;

$cases = new GenerateCommandBuildModeCases();

it('supports common qt container parameters and returns', function () use ($cases): void {
    $cases->testGenerateBuildModeSupportsCommonQtContainerParametersAndReturns();
});

it('keeps writable container references unsupported', function () use ($cases): void {
    $cases->testGenerateBuildModeKeepsWritableContainerReferencesUnsupported();
});

it('skips nested qualified container struct elements', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsNestedQualifiedContainerStructElements();
});

it('does not mistake self pointer constructors for copy constructors', function () use ($cases): void {
    $cases->testGenerateBuildModeDoesNotMistakeSelfPointerConstructorForCopyConstructor();
});

it('keeps supported constructor overloads when one sibling is unsupported', function () use ($cases): void {
    $cases->testGenerateBuildModeKeepsSupportedConstructorOverloadsWhenOneSiblingIsUnsupported();
});

it('retains method overloads and matches across all parameters', function () use ($cases): void {
    $cases->testGenerateBuildModeRetainsMethodOverloadsAndMatchesAcrossAllParameters();
});

it('prefers more specific object overloads', function () use ($cases): void {
    $cases->testGenerateBuildModePrefersMoreSpecificObjectOverloads();
});

it('skips private reference constructor variants', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsPrivateReferenceConstructorVariants();
});

it('does not emit fallback objects for required reference overload parameters', function () use ($cases): void {
    $cases->testGenerateBuildModeDoesNotEmitFallbackObjectForRequiredReferenceOverloadParameters();
});
