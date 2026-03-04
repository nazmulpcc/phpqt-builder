<?php

declare(strict_types=1);

use QtBuilder\Tests\Support\GenerateCommandBuildModeCases;

$cases = new GenerateCommandBuildModeCases();

it('generates signal apis and retains protected slots', function () use ($cases): void {
    $cases->testGenerateBuildModeGeneratesSignalApisAndRetainsProtectedSlots();
});

it('disambiguates overloaded signal sugar methods', function () use ($cases): void {
    $cases->testGenerateBuildModeDisambiguatesOverloadedSignalSugarMethods();
});

it('uses unique utf8 temp names for multi qstring signal callbacks', function () use ($cases): void {
    $cases->testGenerateBuildModeUsesUniqueUtf8TempNamesForMultiQStringSignalCallbacks();
});

it('skips signals with non copyable callback parameters', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsSignalsWithNonCopyableCallbackParameters();
});

it('includes inherited signals in the connect api', function () use ($cases): void {
    $cases->testGenerateBuildModeIncludesInheritedSignalsInConnectApi();
});

it('preserves const signal member pointers', function () use ($cases): void {
    $cases->testGenerateBuildModePreservesConstSignalMemberPointers();
});

it('filters connect methods by name', function () use ($cases): void {
    $cases->testGenerateBuildModeFiltersConnectMethodsByName();
});
