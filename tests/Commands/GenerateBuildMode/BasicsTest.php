<?php

declare(strict_types=1);

use QtBuilder\Tests\Support\GenerateCommandBuildModeCases;

$cases = new GenerateCommandBuildModeCases();

it('returns json and writes files in build mode', function () use ($cases): void {
    $cases->testGenerateBuildModeReturnsJsonAndWritesFiles();
});

it('returns json without writing files in probe mode', function () use ($cases): void {
    $cases->testGenerateProbeModeReturnsJsonWithoutWritingFiles();
});

it('uses explicit includes even when qt path is invalid', function () use ($cases): void {
    $cases->testGenerateBuildModeUsesExplicitIncludesEvenWhenQtPathIsInvalid();
});

it('can load allowed classes from a json file', function () use ($cases): void {
    $cases->testGenerateBuildModeCanLoadAllowedClassesFromJsonFile();
});
