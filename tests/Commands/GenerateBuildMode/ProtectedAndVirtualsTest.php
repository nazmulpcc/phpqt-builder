<?php

declare(strict_types=1);

use QtBuilder\Tests\Support\GenerateCommandBuildModeCases;

$cases = new GenerateCommandBuildModeCases();

it('generates protected methods through access shims', function () use ($cases): void {
    $cases->testGenerateBuildModeGeneratesProtectedMethodsThroughAccessShims();
});

it('generates protected virtual methods with native trampolines', function () use ($cases): void {
    $cases->testGenerateBuildModeGeneratesProtectedVirtualMethodsWithNativeTrampolines();
});

it('generates static protected access helpers', function () use ($cases): void {
    $cases->testGenerateBuildModeGeneratesStaticProtectedAccessHelpers();
});

it('marshals const char pointer virtual args to php strings', function () use ($cases): void {
    $cases->testGenerateBuildModeMarshalsConstCharPointerVirtualArgsToPhpStrings();
});

it('erases protected nested enum types at the shim boundary', function () use ($cases): void {
    $cases->testGenerateBuildModeErasesProtectedNestedEnumTypesAtShimBoundary();
});

it('does not generate trampolines for final virtual methods', function () use ($cases): void {
    $cases->testGenerateBuildModeDoesNotGenerateTrampolinesForFinalVirtualMethods();
});

it('uses a shim only for the protected overload branch', function () use ($cases): void {
    $cases->testGenerateBuildModeUsesShimOnlyForProtectedOverloadBranch();
});
