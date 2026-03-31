<?php

declare(strict_types=1);

use QtBuilder\Commands\BuildCommand;
use QtBuilder\Tests\Support\FakeExtensionBootstrapper;
use QtBuilder\Tests\Support\FakeSystemInformation;

it('reuses the generation analysis cache when generation skips classes', function (): void {
    $fixtureRoot = qt_fixture_path('policy-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-generation-skip-cache-' . bin2hex(random_bytes(4));
    $metadataDir = $buildRoot . '/generated';
    mkdir($metadataDir, 0755, true);

    $cache = [
        'schema_version' => 1,
        'class_cache_schema_version' => 13,
        'modules' => ['QtCore'],
        'qt_path' => $fixtureRoot,
        'candidate_count' => 2,
        'accepted_candidates' => [
            [
                'module' => 'QtCore',
                'class' => 'QCStringHolder',
                'public_header' => $fixtureRoot . '/include/QtCore/QCStringHolder',
                'parse_header' => $fixtureRoot . '/include/QtCore/qcstringholder.h',
            ],
            [
                'module' => 'QtCore',
                'class' => 'QChildThing',
                'public_header' => $fixtureRoot . '/include/QtCore/QChildThing',
                'parse_header' => $fixtureRoot . '/include/QtCore/qchildthing.h',
            ],
        ],
        'skipped_classes' => [],
        'allowed_classes' => ['QCStringHolder', 'QChildThing'],
    ];

    file_put_contents($metadataDir . '/discovery_cache.json', json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($metadataDir . '/accepted_candidates.json', json_encode($cache['accepted_candidates'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($metadataDir . '/allowed_classes.json', json_encode($cache['allowed_classes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $bootstrapper = new FakeExtensionBootstrapper();

    $first = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );
    expect($first)->toBeSuccessfulCommandResult()
        ->and($first['display'])->toContain('Generate analysis pass 1');

    $second = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($second)->toBeSuccessfulCommandResult()
        ->and($second['display'])->toContain('Generation analysis cache: hit')
        ->and($second['display'])->not->toContain('Generate analysis pass 1');

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['cache']['generation_analysis']['hit'] ?? null)->toBeTrue();
});
