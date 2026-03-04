<?php

declare(strict_types=1);

use QtBuilder\Tests\Support\GenerateCommandBuildModeCases;

$cases = new GenerateCommandBuildModeCases();

it('skips template classes', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsTemplateClasses();
});

it('skips classes with unsupported parents', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsClassesWithUnsupportedParent();
});

it('detects qdisablecopy and skips copy constructor exposure', function () use ($cases): void {
    $cases->testClassGenerationDetectsQDisableCopyAndSkipsCopyConstructorExposure();
});

it('skips protected default constructors and keeps public constructors', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsProtectedDefaultConstructorAndKeepsPublicConstructor();
});

it('skips direct construction and delete for private lifecycle classes', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsDirectConstructionAndDeleteForPrivateLifecycleClasses();
});

it('skips delete when the destructor is in an implicit private section', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsDeleteWhenDestructorIsInImplicitPrivateSection();
});

it('skips qt disambiguation tag parameters', function () use ($cases): void {
    $cases->testGenerateBuildModeSkipsQtDisambiguationTagParameters();
});
