<?php

declare(strict_types=1);

use QtBuilder\Tests\Support\GenerateCommandBuildModeCases;

$cases = new GenerateCommandBuildModeCases();

it('skips child methods with incompatible inherited signatures', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsChildMethodsWithIncompatibleInheritedSignatures();
});

it('generates abstract classes and retains pure virtual methods', function () use ($cases): void {
    $cases->testGenerateBuildModeGeneratesAbstractClassesAndRetainsPureVirtualMethods();
});

it('generates abstract shell classes with supported pure virtuals', function () use ($cases): void {
    $cases->testGenerateBuildModeGeneratesAbstractShellClassesWithSupportedPureVirtuals();
});

it('omits abstract constructors when pure virtuals cannot be satisfied', function () use ($cases): void {
    $cases->testGenerateBuildModeOmitsAbstractConstructorsWhenPureVirtualsCannotBeSatisfied();
});

it('omits abstract constructors when pure virtuals are only inherited', function () use ($cases): void {
    $cases->testGenerateBuildModeOmitsAbstractConstructorsWhenPureVirtualsAreOnlyInherited();
});

it('keeps abstract constructors when value object pure virtuals are supported', function () use ($cases): void {
    $cases->testGenerateBuildModeKeepsAbstractConstructorsWhenValueObjectPureVirtualsAreSupported();
});

it('does not make inherited concrete methods abstract in php', function () use ($cases): void {
    $cases->testGenerateBuildModeDoesNotMakeInheritedConcreteMethodsAbstractInPhp();
});

it('renames inherited conflicting methods deterministically', function () use ($cases): void {
    $cases->testGenerateBuildModeRenamesInheritedConflictingMethodsDeterministically();
});

it('supports inherited enum types in overrides', function () use ($cases): void {
    $cases->testGenerateBuildModeSupportsInheritedEnumTypesInOverrides();
});

it('allows concrete children of abstract parents', function () use ($cases): void {
    $cases->testGenerateBuildModeAllowsConcreteChildrenOfAbstractParents();
});
