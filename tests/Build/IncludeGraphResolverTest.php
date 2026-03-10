<?php

declare(strict_types=1);

use QtBuilder\Build\IncludeGraphResolver;

it('resolves transitive includes and caches by header and include set', function (): void {
    $root = qt_temp_dir('qtbuilder-include-graph-');
    $headerA = $root . '/a.h';
    $headerB = $root . '/b.h';
    $headerC = $root . '/c.h';

    file_put_contents($headerC, <<<'CPP'
class QC {};
CPP);

    file_put_contents($headerB, <<<'CPP'
#include "c.h"
class QB {};
CPP);

    file_put_contents($headerA, <<<'CPP'
#include "b.h"
class QA {};
CPP);

    $resolvedHeaderB = realpath($headerB) ?: $headerB;
    $resolvedHeaderC = realpath($headerC) ?: $headerC;

    $resolver = new IncludeGraphResolver();
    $first = $resolver->transitiveIncludes($headerA, [$root]);
    $second = $resolver->transitiveIncludes($headerA, [$root]);

    expect($first)->toContain($resolvedHeaderB, $resolvedHeaderC)
        ->and($second)->toBe($first);
});
