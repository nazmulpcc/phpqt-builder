<?php

declare(strict_types=1);

use QtBuilder\Commands\BuildCommand;
use QtBuilder\Tests\Support\FakeExtensionBootstrapper;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

it('generates the extension tree from a fixture qt root', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    $classCacheDir = $buildRoot . '/classes';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult();
    expect(is_file($outputDir . '/config.m4'))->toBeTrue()
        ->and(is_file($outputDir . '/php_qt.h'))->toBeTrue()
        ->and(is_file($outputDir . '/qt.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qpoint.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qtree.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qnode.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qabstractitemmodel.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/build/gen_stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/configure'))->toBeTrue()
        ->and(is_file($outputDir . '/Makefile'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qpoint_arginfo.h'))->toBeTrue()
        ->and(is_file($metadataDir . '/build_summary.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/allowed_classes.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/discovery_cache.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/accepted_candidates.json'))->toBeTrue()
        ->and(is_file($classCacheDir . '/QPoint.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/phpize.stdout.log'))->toBeTrue()
        ->and(is_file($metadataDir . '/gen_stub.stdout.log'))->toBeTrue()
        ->and(is_file($metadataDir . '/configure.stdout.log'))->toBeTrue()
        ->and(is_file($metadataDir . '/make.stdout.log'))->toBeTrue()
        ->and($result['display'])->toContain(
            'Running 2 parallel discovery worker(s)...',
            'Class structure cache:',
            'Discovery pass 1',
            'Module acceptance:',
            'QtCore:',
        )
        ->and($bootstrapper->contexts)->toHaveCount(1);

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['generated_classes'])->toBe(5)
        ->and($summary['skipped_classes'])->toBe(1)
        ->and(array_column($summary['bootstrap'], 'name'))->toBe(['phpize', 'gen_stub', 'configure', 'make']);

    $classmap = qt_decode_json((string) file_get_contents($metadataDir . '/classmap.json'));
    expect(array_column($classmap, 'class'))->toBe(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree']);

    $allowedClasses = qt_decode_json((string) file_get_contents($metadataDir . '/allowed_classes.json'));
    expect($allowedClasses)->toBe(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree']);

    $stub = (string) file_get_contents($outputDir . '/classes/qt_qtree.stub.php');
    expect($stub)->toContain('QNode|null $node = null');
});

it('reuses an existing discovery cache', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-cache-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    $bootstrapper = new FakeExtensionBootstrapper();

    $initialRun = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );
    expect($initialRun)->toBeSuccessfulCommandResult();

    $cache = qt_decode_json((string) file_get_contents($metadataDir . '/discovery_cache.json'));
    $cache['candidate_count'] = 1;
    $cache['accepted_candidates'] = [[
        'module' => 'QtCore',
        'class' => 'QPoint',
        'public_header' => $fixtureRoot . '/include/QtCore/QPoint',
        'parse_header' => $fixtureRoot . '/include/QtCore/qpoint.h',
    ]];
    $cache['allowed_classes'] = ['QPoint'];
    file_put_contents($metadataDir . '/discovery_cache.json', json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($metadataDir . '/accepted_candidates.json', json_encode($cache['accepted_candidates'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($metadataDir . '/allowed_classes.json', json_encode($cache['allowed_classes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult();
    expect($result['display'])->toContain(
        'Using cached build metadata:',
        'Bootstrapping extension build tree...',
        'discovery_cache.json',
        'accepted_candidates.json',
        'allowed_classes.json',
        'Module acceptance:',
        'QtCore:',
    )->not->toContain('Running 2 parallel discovery worker(s)...');

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['generated_classes'])->toBe(1)
        ->and($summary['candidate_classes'])->toBe(1);

    $classmap = qt_decode_json((string) file_get_contents($metadataDir . '/classmap.json'));
    expect(array_column($classmap, 'class'))->toBe(['QPoint']);
});

it('generates abstract shells and concrete children', function (): void {
    $fixtureRoot = qt_fixture_path('abstract-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-abstract-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult();
    expect(is_file($outputDir . '/classes/qt_qabstractshell.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qabstractparentthing.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qconcretechildthing.cpp'))->toBeTrue();

    $classmap = qt_decode_json((string) file_get_contents($metadataDir . '/classmap.json'));
    expect(array_column($classmap, 'class'))->toBe(['QAbstractParentThing', 'QAbstractShell', 'QConcreteChildThing']);

    $allowedClasses = qt_decode_json((string) file_get_contents($metadataDir . '/allowed_classes.json'));
    expect($allowedClasses)->toBe(['QAbstractParentThing', 'QAbstractShell', 'QConcreteChildThing']);

    $skippedClasses = qt_decode_json((string) file_get_contents($metadataDir . '/skipped_classes.json'));
    expect($skippedClasses)->toBe([]);

    $abstractStub = (string) file_get_contents($outputDir . '/classes/qt_qabstractparentthing.stub.php');
    expect($abstractStub)->toContain('abstract class QAbstractParentThing');
});

it('fails when a bootstrap step fails', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-fail-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';

    $bootstrapper = new FakeExtensionBootstrapper();
    $bootstrapper->failureMessage = 'configure failed';

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeFailureCommandResult()
        ->and($result['display'])->toContain('configure failed');

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['bootstrap_error'])->toBe('configure failed');
});

it('rewrites cached allow lists to actual generated classes', function (): void {
    $fixtureRoot = qt_fixture_path('policy-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-stable-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    mkdir($metadataDir, 0755, true);

    $cache = [
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
    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            '--modules' => 'QtCore',
            '--output' => $outputDir,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult();
    expect($result['display'])->toContain('Using cached build metadata:', 'Re-evaluating generated dependency set', 'Module acceptance:', 'QtCore:');

    $allowedClasses = qt_decode_json((string) file_get_contents($metadataDir . '/allowed_classes.json'));
    expect($allowedClasses)->toBe(['QCStringHolder']);

    $acceptedCandidates = qt_decode_json((string) file_get_contents($metadataDir . '/accepted_candidates.json'));
    expect(array_column($acceptedCandidates, 'class'))->toBe(['QCStringHolder']);

    $classmap = qt_decode_json((string) file_get_contents($metadataDir . '/classmap.json'));
    expect(array_column($classmap, 'class'))->toBe(['QCStringHolder']);

    $skippedClasses = qt_decode_json((string) file_get_contents($metadataDir . '/skipped_classes.json'));
    $skippedByClass = [];
    foreach ($skippedClasses as $skippedClass) {
        $skippedByClass[$skippedClass['class']] = $skippedClass['reason_code'];
    }
    expect($skippedByClass['QChildThing'] ?? null)->toBe('unsupported_parent_class');

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['generation_passes'])->toBe(2)
        ->and($summary['generated_classes'])->toBe(1)
        ->and($summary['skipped_classes'])->toBe(1);
});
