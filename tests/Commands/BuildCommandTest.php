<?php

declare(strict_types=1);

use QtBuilder\Commands\BuildCommand;
use QtBuilder\Build\Dependencies\StaticModuleDependencyResolver;
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
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult();
    expect(is_file($outputDir . '/config.m4'))->toBeTrue()
        ->and(is_file($outputDir . '/config.w32'))->toBeTrue()
        ->and(is_file($outputDir . '/php_qt.h'))->toBeTrue()
        ->and(is_file($outputDir . '/qt.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_buildinfo.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_buildinfo.stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qpoint.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qtree.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qnode.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qabstractitemmodel.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/build/gen_stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/configure'))->toBeTrue()
        ->and(is_file($outputDir . '/Makefile'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qpoint_arginfo.h'))->toBeTrue()
        ->and(is_file($metadataDir . '/build_summary.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/runtime_manifest.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/allowed_classes.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/discovery_cache.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/enum_holders_cache.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/generation_analysis_cache.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/enum_candidate_headers.json'))->toBeTrue()
        ->and(is_file($metadataDir . '/accepted_candidates.json'))->toBeTrue()
        ->and(
            is_file($classCacheDir . '/QPoint.json')
            || is_file($classCacheDir . '/qpoint__qtcore__qpoint_h.json')
            || (glob($classCacheDir . '/qpoint__*.json') ?: []) !== [],
        )->toBeTrue()
        ->and(is_file($metadataDir . '/phpize.stdout.log'))->toBeTrue()
        ->and(is_file($metadataDir . '/gen_stub.stdout.log'))->toBeTrue()
        ->and(is_file($metadataDir . '/configure.stdout.log'))->toBeTrue()
        ->and(is_file($metadataDir . '/make.stdout.log'))->toBeTrue()
        ->and($result['display'])->toContain(
            'Requested modules:',
            'Expanded modules: QtCore',
            'Running 2 parallel discovery worker(s)...',
            'Class structure cache:',
            'Enum holder cache: miss.',
            'Generation analysis cache: miss.',
            'Extracting enum holders with 2 parallel worker(s)...',
            'Enum discovery',
            'Generate analysis pass 1',
            'timing:',
            'phpize: started',
            'phpize: succeeded (',
            'gen_stub: started',
            'gen_stub: succeeded (',
            'configure: started',
            'configure: succeeded (',
            'make: started',
            'make: succeeded (',
            'Module acceptance:',
            'File writes:',
            'Module Name',
            'Class Acceptance',
            'Method Acceptance',
            'QtCore',
        )
        ->and($bootstrapper->contexts)->toHaveCount(1);

    expect(substr_count($result['display'], 'Module acceptance:'))->toBe(1);

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['generated_classes'])->toBe(5)
        ->and($summary['skipped_classes'])->toBe(1)
        ->and($summary['requested_modules'] ?? null)->toBe(['QtCore'])
        ->and($summary['expanded_modules'] ?? null)->toBe(['QtCore'])
        ->and($summary['dependency_source'] ?? null)->toBe('static_manifest')
        ->and($summary['runtime_manifest'] ?? null)->toBe($metadataDir . '/runtime_manifest.json')
        ->and(array_column($summary['bootstrap'], 'name'))->toBe(['phpize', 'gen_stub', 'configure', 'make'])
        ->and($summary['bootstrap'][0]['duration_seconds'] ?? null)->toBeFloat()
        ->and(array_keys($summary['timings'] ?? []))->toContain('discovery', 'class_structure_cache', 'supplemental_discovery', 'enum_discovery', 'generation_analysis', 'emission', 'bootstrap', 'build_total')
        ->and(($summary['cache']['generation_analysis']['hit'] ?? null))->toBeFalse()
        ->and($summary['file_writes']['total']['total'] ?? null)->toBeGreaterThan(0);

    $runtimeManifest = qt_decode_json((string) file_get_contents($metadataDir . '/runtime_manifest.json'));
    expect($runtimeManifest['build_mode'])->toBe('monolithic')
        ->and($runtimeManifest['qt_version'])->toBe('6.7.1')
        ->and($runtimeManifest['builder_abi_version'])->toBe('phpqt-builder-abi-v1')
        ->and($runtimeManifest['requested_modules'])->toBe(['QtCore'])
        ->and($runtimeManifest['expanded_modules'])->toBe(['QtCore'])
        ->and($runtimeManifest['dependency_source'])->toBe('static_manifest')
        ->and($runtimeManifest['built_modules'])->toBe(['QtCore'])
        ->and($runtimeManifest['modules']['QtCore']['extension_name'] ?? null)->toBe('qt')
        ->and($runtimeManifest['modules']['QtCore']['class_count'] ?? null)->toBe(5);

    $extensionSource = (string) file_get_contents($outputDir . '/qt.cpp');
    expect($extensionSource)->toContain(
        'PHP_MINIT(qt_buildinfo)',
        '#include "classes/qt_buildinfo.h"',
        'php_info_print_table_row(2, "build mode", "monolithic");',
        'php_info_print_table_row(2, "Qt version", "6.7.1");',
        'php_info_print_table_row(2, "built modules", ZSTR_VAL(qt_buildinfo_built_modules));',
    );

    $buildInfoStub = (string) file_get_contents($outputDir . '/classes/qt_buildinfo.stub.php');
    expect($buildInfoStub)->toContain(
        "public const string MODE_MONOLITHIC = 'monolithic';",
        "public const string MODE_MODULAR = 'modular';",
    );

    $classmap = qt_decode_json((string) file_get_contents($metadataDir . '/classmap.json'));
    expect(array_column($classmap, 'class'))->toBe(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree']);

    $allowedClasses = qt_decode_json((string) file_get_contents($metadataDir . '/allowed_classes.json'));
    expect($allowedClasses)->toBe(['QAbstractItemModel', 'QModelIndex', 'QNode', 'QPoint', 'QTree']);

    $skippedMethods = qt_decode_json((string) file_get_contents($metadataDir . '/skipped_methods.json'));
    $skippedMethodClasses = array_values(array_unique(array_filter(array_column($skippedMethods, 'class'), 'is_string')));
    expect($skippedMethods)->not->toBe([])
        ->and(array_values(array_unique(array_column($skippedMethods, 'module'))))->toBe(['QtCore'])
        ->and($skippedMethodClasses)->toBe(['QPoint'])
        ->and(array_values(array_intersect($skippedMethodClasses, array_column($classmap, 'class'))))->toBe($skippedMethodClasses);

    $stub = (string) file_get_contents($outputDir . '/classes/qt_qtree.stub.php');
    expect($stub)->toContain('QNode|null $node = null');
});

it('stages a monolithic build directly into a php-src extension tree', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-stage-static-' . bin2hex(random_bytes(4));
    $metadataDir = $buildRoot . '/generated';
    $stageTarget = qt_temp_dir('qtbuilder-stage-target-') . '/php-src/ext/qt';
    $system = FakeSystemInformation::passing();
    $system->setOsFamily('Windows');
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand($system, $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--stage-static-to' => $stageTarget,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and(is_file($stageTarget . '/config.m4'))->toBeTrue()
        ->and(is_file($stageTarget . '/config.w32'))->toBeTrue()
        ->and(is_file($stageTarget . '/php_qt.h'))->toBeTrue()
        ->and(is_file($stageTarget . '/qt.cpp'))->toBeTrue()
        ->and(is_file($stageTarget . '/classes/qt_qpoint.cpp'))->toBeTrue()
        ->and(is_file($stageTarget . '/src_00/qt_bucket_00.cpp'))->toBeTrue()
        ->and(is_file($stageTarget . '/.qtb-stage-manifest.json'))->toBeTrue()
        ->and(is_file($buildRoot . '/ext/configure'))->toBeFalse()
        ->and(is_file($buildRoot . '/ext/Makefile'))->toBeFalse()
        ->and($bootstrapper->contexts)->toHaveCount(0)
        ->and($result['display'])->toContain(
            'Static staging target:',
            'Skipping bootstrap (--no-build).',
            'Static staging complete:',
            'Next:',
        );

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    $normalizedTarget = str_replace('\\', '/', $stageTarget);
    expect($summary['bootstrap_disabled'] ?? null)->toBeTrue()
        ->and($summary['static_stage']['enabled'] ?? null)->toBeTrue()
        ->and($summary['static_stage']['successful'] ?? null)->toBeTrue()
        ->and($summary['static_stage']['target_dir'] ?? null)->toBe($normalizedTarget)
        ->and($summary['static_stage']['staged_files'] ?? null)->toBeGreaterThan(0);
});

it('prunes stale previously staged files when rerunning static staging', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-stage-prune-' . bin2hex(random_bytes(4));
    $metadataDir = $buildRoot . '/generated';
    $stageTarget = qt_temp_dir('qtbuilder-stage-prune-target-') . '/php-src/ext/qt';
    $bootstrapper = new FakeExtensionBootstrapper();

    $first = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--stage-static-to' => $stageTarget,
        ],
    );
    expect($first)->toBeSuccessfulCommandResult();

    $manifestPath = $stageTarget . '/.qtb-stage-manifest.json';
    $manifest = qt_decode_json((string) file_get_contents($manifestPath));
    $manifest['files'][] = 'classes/qt_stale.h';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents($stageTarget . '/classes/qt_stale.h', "stale\n");

    $second = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--stage-static-to' => $stageTarget,
        ],
    );

    expect($second)->toBeSuccessfulCommandResult()
        ->and(is_file($stageTarget . '/classes/qt_stale.h'))->toBeFalse()
        ->and($second['display'])->toContain('Static stage file writes:');

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['static_stage']['pruned_files'] ?? null)->toBe(1);
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
            'modules' => 'QtCore',
            '--output' => $buildRoot,
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
            'modules' => 'QtCore',
            '--output' => $buildRoot,
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
        'enum_holders_cache.json',
        'enum_candidate_headers.json',
        'phpize: started',
        'phpize: succeeded (',
        'gen_stub: started',
        'gen_stub: succeeded (',
        'configure: started',
        'configure: succeeded (',
        'make: started',
        'make: succeeded (',
        'timing:',
        'Module acceptance:',
        'Module Name',
        'Class Acceptance',
        'Method Acceptance',
        'QtCore',
    )->not->toContain('Running 2 parallel discovery worker(s)...');
    expect(substr_count($result['display'], 'Module acceptance:'))->toBe(1);

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
            'modules' => 'QtCore',
            '--output' => $buildRoot,
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

it('generates synthetic QList parents for supported list-derived classes', function (): void {
    $fixtureRoot = qt_fixture_path('list-parent-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-list-parent-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore,QtGui',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and(is_file($outputDir . '/classes/qt_qlistofqpoint.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qpolygon.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qpoint.cpp'))->toBeTrue();

    $classmap = qt_decode_json((string) file_get_contents($metadataDir . '/classmap.json'));
    expect(array_column($classmap, 'class'))->toBe(['QListOfQPoint', 'QPoint', 'QPolygon']);

    $polygonStub = (string) file_get_contents($outputDir . '/classes/qt_qpolygon.stub.php');
    expect($polygonStub)->toContain('class QPolygon extends \\Qt\\Core\\QListOfQPoint');

    $listHeader = (string) file_get_contents($outputDir . '/classes/qt_qlistofqpoint.h');
    expect($listHeader)->toContain('using QListOfQPoint = QList<QPoint>;');

    $polygonHeader = (string) file_get_contents($outputDir . '/classes/qt_qpolygon.h');
    expect($polygonHeader)->not->toContain('prevent_destroy');

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['generated_classes'])->toBe(3)
        ->and($summary['skipped_classes'])->toBe(1);
});

it('generates enum holder classes and unblocks enum-based methods', function (): void {
    $fixtureRoot = qt_fixture_path('enum-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-enums-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore,QtSql',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and(is_file($outputDir . '/classes/qt_enum_qt_connection_type.stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_enum_qt_connection_types.stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_enum_qt_core_q_connection_carrier_mode.stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_enum_qt_core_q_connection_carrier_modes.stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_enum_qt_sql_q_sql_param_type_flag.stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_enum_qt_sql_q_sql_param_type.stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_enum_qt_sql_q_sql_table_type.stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qconnectioncarrier.stub.php'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qsqlquerylike.stub.php'))->toBeTrue();

    $enumCandidateHeaders = qt_decode_json((string) file_get_contents($metadataDir . '/enum_candidate_headers.json'));
    expect($enumCandidateHeaders)->toContainEqual([
        'header' => $fixtureRoot . '/include/QtCore/qnamespace.h',
        'module' => 'QtCore',
        'types' => ['Qt::ConnectionType', 'Qt::ConnectionTypes'],
    ])->toContainEqual([
        'header' => $fixtureRoot . '/include/QtSql/qsqlquerylike.h',
        'module' => 'QtSql',
        'types' => ['QSql::ParamType', 'QSql::TableType'],
    ]);

    $globalEnumStub = (string) file_get_contents($outputDir . '/classes/qt_enum_qt_connection_type.stub.php');
    expect($globalEnumStub)->toContain(
        'namespace Qt;',
        'final class ConnectionType',
        'public const int AutoConnection = 0;',
        'public const int DirectConnection = 1;',
    );

    $classEnumStub = (string) file_get_contents($outputDir . '/classes/qt_enum_qt_core_q_connection_carrier_modes.stub.php');
    expect($classEnumStub)->toContain(
        'namespace Qt\\Core\\QConnectionCarrier;',
        'final class Modes',
        'public const int Idle = 0;',
        'public const int Busy = 1;',
    );

    $namespaceEnumStub = (string) file_get_contents($outputDir . '/classes/qt_enum_qt_sql_q_sql_param_type.stub.php');
    expect($namespaceEnumStub)->toContain(
        'namespace Qt\\Sql\\QSql;',
        'final class ParamType',
        'public const int In = 1;',
        'public const int Out = 2;',
        'public const int InOut = 3;',
        'public const int Binary = 4;',
    );

    $carrierStub = (string) file_get_contents($outputDir . '/classes/qt_qconnectioncarrier.stub.php');
    expect($carrierStub)->toContain(
        'public function setConnectionType(int $type): void',
        'public function connectionType(): int',
        'public function setConnectionFlags(int $flags): void',
        'public function connectionFlags(): int',
        'public function setMode(int $mode): void',
        'public function mode(): int',
        'public function setModes(int $modes): void',
        'public function modes(): int',
    );

    $sqlStub = (string) file_get_contents($outputDir . '/classes/qt_qsqlquerylike.stub.php');
    expect($sqlStub)->toContain(
        'public function bindValue(int $position, int $value, int $type): void',
        'public function bindingType(): int',
        'public function tableType(): int',
    );

    $classmap = qt_decode_json((string) file_get_contents($metadataDir . '/classmap.json'));
    expect(array_column($classmap, 'class'))->toBe(['QConnectionCarrier', 'QSqlQueryLike']);

    $skippedMethods = qt_decode_json((string) file_get_contents($metadataDir . '/skipped_methods.json'));
    $enumBlockedMethods = array_values(array_filter(
        $skippedMethods,
        static fn(array $entry): bool => in_array($entry['class'] ?? '', ['QConnectionCarrier', 'QSqlQueryLike'], true),
    ));
    expect($enumBlockedMethods)->toBe([]);
});

it('removes stale enum holder files during incremental builds', function (): void {
    $fixtureRoot = qt_fixture_path('enum-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-enum-stale-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $bootstrapper = new FakeExtensionBootstrapper();

    $first = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore,QtSql',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );
    expect($first)->toBeSuccessfulCommandResult();

    file_put_contents(
        $outputDir . '/classes/qt_enum_stale.stub.php',
        "<?php\n\nnamespace Qt;\n\nfinal class Broken(\n{\n}\n",
    );
    file_put_contents($outputDir . '/classes/qt_enum_stale.cpp', "// stale\n");
    file_put_contents($outputDir . '/classes/qt_enum_stale.h', "// stale\n");
    file_put_contents($outputDir . '/classes/qt_enum_stale_arginfo.h', "// stale\n");
    file_put_contents($outputDir . '/classes/qt_enum_stale.dep', "classes/qt_enum_stale.lo: classes/qt_enum_stale.cpp classes/qt_enum_stale.h\n");
    file_put_contents($outputDir . '/classes/qt_enum_stale.lo', "# libtool object\n");
    @mkdir($outputDir . '/classes/.libs', 0777, true);
    file_put_contents($outputDir . '/classes/.libs/qt_enum_stale.o', "stale object\n");
    file_put_contents($outputDir . '/qt.dep', "qt.lo: classes/qt_enum_stale.h\n");

    $second = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore,QtSql',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );

    expect($second)->toBeSuccessfulCommandResult()
        ->and(is_file($outputDir . '/classes/qt_enum_stale.stub.php'))->toBeFalse()
        ->and(is_file($outputDir . '/classes/qt_enum_stale.cpp'))->toBeFalse()
        ->and(is_file($outputDir . '/classes/qt_enum_stale.h'))->toBeFalse()
        ->and(is_file($outputDir . '/classes/qt_enum_stale_arginfo.h'))->toBeFalse()
        ->and(is_file($outputDir . '/classes/qt_enum_stale.dep'))->toBeFalse()
        ->and(is_file($outputDir . '/classes/qt_enum_stale.lo'))->toBeFalse()
        ->and(is_file($outputDir . '/classes/.libs/qt_enum_stale.o'))->toBeFalse()
        ->and(is_file($outputDir . '/qt.dep'))->toBeFalse();
});

it('generates wrappers for supplemental class candidates discovered through includes', function (): void {
    $fixtureRoot = qt_fixture_path('supplemental-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-supplemental-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtGui,QtOpenGL',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and($result['display'])->toContain('Supplemental class discovery: queued 1 new candidate(s).')
        ->and(is_file($outputDir . '/classes/qt_qabstractopenglfunctions.cpp'))->toBeTrue()
        ->and((string) file_get_contents($outputDir . '/classes/qt_qabstractopenglfunctions.h'))->toContain('qopenglversionfunctions.h')
        ->and(is_file($outputDir . '/classes/qt_qopenglfunctions_1_0.cpp'))->toBeTrue()
        ->and(is_file($metadataDir . '/supplemental_candidates.json'))->toBeTrue();

    $classmap = qt_decode_json((string) file_get_contents($metadataDir . '/classmap.json'));
    expect(array_column($classmap, 'class'))->toContain('QAbstractOpenGLFunctions', 'QOpenGLFunctions_1_0');
});

it('auto-adds static manifest dependencies for monolithic builds', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-expanded-' . bin2hex(random_bytes(4));
    $metadataDir = $buildRoot . '/generated';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtWidgets',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and($result['display'])->toContain(
            'Requested modules: QtWidgets',
            'Auto-added dependency modules: QtCore, QtGui',
            'Expanded modules: QtCore, QtGui, QtWidgets',
            'Skipping bootstrap (--no-build).',
        );

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['modules'] ?? null)->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($summary['requested_modules'] ?? null)->toBe(['QtWidgets'])
        ->and($summary['expanded_modules'] ?? null)->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($summary['dependency_source'] ?? null)->toBe('static_manifest');

    $runtimeManifest = qt_decode_json((string) file_get_contents($metadataDir . '/runtime_manifest.json'));
    expect($runtimeManifest['requested_modules'] ?? null)->toBe(['QtWidgets'])
        ->and($runtimeManifest['expanded_modules'] ?? null)->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($runtimeManifest['built_modules'] ?? null)->toBe(['QtCore', 'QtGui', 'QtWidgets'])
        ->and($runtimeManifest['modules']['QtWidgets']['dependencies'] ?? null)->toBe(['QtCore', 'QtGui']);
});

it('uses all supported modules when modules argument is omitted in non-interactive mode', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-all-non-interactive-' . bin2hex(random_bytes(4));
    mkdir($buildRoot, 0777, true);
    $manifestPath = $buildRoot . '/manifest.json';
    $manifest = [
        'schema_version' => 1,
        'qt_major' => 6,
        'modules' => [
            'QtCore' => ['extension_name' => 'qtcore', 'dependencies' => []],
            'QtGui' => ['extension_name' => 'qtgui', 'dependencies' => ['QtCore']],
            'QtWidgets' => ['extension_name' => 'qtwidgets', 'dependencies' => ['QtGui']],
        ],
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $promptCalls = 0;
    $command = new BuildCommand(
        FakeSystemInformation::passing(),
        new FakeExtensionBootstrapper(),
        dependencyResolver: new StaticModuleDependencyResolver($manifestPath),
        moduleSelector: static function (string $label, array $options, array $default) use (&$promptCalls): array {
            $promptCalls++;
            return ['QtCore'];
        },
    );

    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        '--qt-path' => $fixtureRoot,
        '--output' => $buildRoot,
        '--jobs' => '2',
        '--no-build' => true,
    ], ['interactive' => false]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($promptCalls)->toBe(0)
        ->and($tester->getDisplay())->toContain(
            'Requested modules: QtCore, QtGui, QtWidgets',
            'Expanded modules: QtCore, QtGui, QtWidgets',
        );
});

it('uses all supported modules when prompt selects All for omitted modules argument', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-all-interactive-' . bin2hex(random_bytes(4));
    mkdir($buildRoot, 0777, true);
    $manifestPath = $buildRoot . '/manifest.json';
    $manifest = [
        'schema_version' => 1,
        'qt_major' => 6,
        'modules' => [
            'QtCore' => ['extension_name' => 'qtcore', 'dependencies' => []],
            'QtGui' => ['extension_name' => 'qtgui', 'dependencies' => ['QtCore']],
            'QtWidgets' => ['extension_name' => 'qtwidgets', 'dependencies' => ['QtGui']],
        ],
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $promptCalls = 0;
    $command = new BuildCommand(
        FakeSystemInformation::passing(),
        new FakeExtensionBootstrapper(),
        dependencyResolver: new StaticModuleDependencyResolver($manifestPath),
        moduleSelector: static function (string $label, array $options, array $default) use (&$promptCalls): array {
            $promptCalls++;
            expect($default)->toBe(['__all'])
                ->and($options)->toHaveKeys(['__all', 'QtCore', 'QtGui', 'QtWidgets']);
            return ['__all'];
        },
    );

    $result = qt_command_result($command, [
        '--qt-path' => $fixtureRoot,
        '--output' => $buildRoot,
        '--jobs' => '2',
        '--no-build' => true,
    ]);

    expect($result)->toBeSuccessfulCommandResult()
        ->and($promptCalls)->toBe(1)
        ->and($result['display'])->toContain(
            'Requested modules: QtCore, QtGui, QtWidgets',
            'Expanded modules: QtCore, QtGui, QtWidgets',
        );
});

it('uses prompt-selected subset when modules argument is omitted in interactive mode', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-subset-interactive-' . bin2hex(random_bytes(4));
    mkdir($buildRoot, 0777, true);
    $manifestPath = $buildRoot . '/manifest.json';
    $manifest = [
        'schema_version' => 1,
        'qt_major' => 6,
        'modules' => [
            'QtCore' => ['extension_name' => 'qtcore', 'dependencies' => []],
            'QtGui' => ['extension_name' => 'qtgui', 'dependencies' => ['QtCore']],
            'QtWidgets' => ['extension_name' => 'qtwidgets', 'dependencies' => ['QtGui']],
        ],
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $promptCalls = 0;
    $command = new BuildCommand(
        FakeSystemInformation::passing(),
        new FakeExtensionBootstrapper(),
        dependencyResolver: new StaticModuleDependencyResolver($manifestPath),
        moduleSelector: static function (string $label, array $options, array $default) use (&$promptCalls): array {
            $promptCalls++;
            return ['QtWidgets'];
        },
    );

    $result = qt_command_result($command, [
        '--qt-path' => $fixtureRoot,
        '--output' => $buildRoot,
        '--jobs' => '2',
        '--no-build' => true,
    ]);

    expect($result)->toBeSuccessfulCommandResult()
        ->and($promptCalls)->toBe(1)
        ->and($result['display'])->toContain(
            'Requested modules: QtCore, QtGui, QtWidgets',
            'Expanded modules: QtCore, QtGui, QtWidgets',
        )->not->toContain('Auto-added dependency modules:');
});

it('bypasses prompt when modules argument is explicitly provided', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-explicit-modules-' . bin2hex(random_bytes(4));
    mkdir($buildRoot, 0777, true);
    $manifestPath = $buildRoot . '/manifest.json';
    $manifest = [
        'schema_version' => 1,
        'qt_major' => 6,
        'modules' => [
            'QtCore' => ['extension_name' => 'qtcore', 'dependencies' => []],
            'QtGui' => ['extension_name' => 'qtgui', 'dependencies' => ['QtCore']],
            'QtWidgets' => ['extension_name' => 'qtwidgets', 'dependencies' => ['QtGui']],
        ],
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $promptCalls = 0;
    $command = new BuildCommand(
        FakeSystemInformation::passing(),
        new FakeExtensionBootstrapper(),
        dependencyResolver: new StaticModuleDependencyResolver($manifestPath),
        moduleSelector: static function (string $label, array $options, array $default) use (&$promptCalls): array {
            $promptCalls++;
            return ['__all'];
        },
    );

    $result = qt_command_result($command, [
        '--qt-path' => $fixtureRoot,
        'modules' => 'QtCore',
        '--output' => $buildRoot,
        '--jobs' => '2',
        '--no-build' => true,
    ]);

    expect($result)->toBeSuccessfulCommandResult()
        ->and($promptCalls)->toBe(0)
        ->and($result['display'])->toContain(
            'Requested modules: QtCore',
            'Expanded modules: QtCore',
        );
});

it('treats empty interactive module selection as All', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-empty-selection-' . bin2hex(random_bytes(4));
    mkdir($buildRoot, 0777, true);
    $manifestPath = $buildRoot . '/manifest.json';
    $manifest = [
        'schema_version' => 1,
        'qt_major' => 6,
        'modules' => [
            'QtCore' => ['extension_name' => 'qtcore', 'dependencies' => []],
            'QtGui' => ['extension_name' => 'qtgui', 'dependencies' => ['QtCore']],
        ],
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $command = new BuildCommand(
        FakeSystemInformation::passing(),
        new FakeExtensionBootstrapper(),
        dependencyResolver: new StaticModuleDependencyResolver($manifestPath),
        moduleSelector: static fn (string $label, array $options, array $default): array => [],
    );

    $result = qt_command_result($command, [
        '--qt-path' => $fixtureRoot,
        '--output' => $buildRoot,
        '--jobs' => '2',
        '--no-build' => true,
    ]);

    expect($result)->toBeSuccessfulCommandResult()
        ->and($result['display'])->toContain(
            'Requested modules: QtCore, QtGui',
            'Expanded modules: QtCore, QtGui',
        );
});

it('uses concrete modules when both All and module subset are selected', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-mixed-selection-' . bin2hex(random_bytes(4));
    mkdir($buildRoot, 0777, true);
    $manifestPath = $buildRoot . '/manifest.json';
    $manifest = [
        'schema_version' => 1,
        'qt_major' => 6,
        'modules' => [
            'QtCore' => ['extension_name' => 'qtcore', 'dependencies' => []],
            'QtGui' => ['extension_name' => 'qtgui', 'dependencies' => ['QtCore']],
            'QtWidgets' => ['extension_name' => 'qtwidgets', 'dependencies' => ['QtGui']],
        ],
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $command = new BuildCommand(
        FakeSystemInformation::passing(),
        new FakeExtensionBootstrapper(),
        dependencyResolver: new StaticModuleDependencyResolver($manifestPath),
        moduleSelector: static fn (string $label, array $options, array $default): array => ['__all', 'QtWidgets'],
    );

    $result = qt_command_result($command, [
        '--qt-path' => $fixtureRoot,
        '--output' => $buildRoot,
        '--jobs' => '2',
        '--no-build' => true,
    ]);

    expect($result)->toBeSuccessfulCommandResult()
        ->and($result['display'])->toContain(
            'Requested modules: QtCore, QtGui, QtWidgets',
            'Expanded modules: QtCore, QtGui, QtWidgets',
        )->not->toContain('Auto-added dependency modules:');
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
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeFailureCommandResult()
        ->and($result['display'])->toContain('configure: started', 'configure: failed', 'stdout:', 'stderr:', 'configure failed');

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['bootstrap_error'])->toBe('configure failed');
});

it('supports generating a monolithic extension tree without bootstrapping', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-no-build-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and($bootstrapper->contexts)->toHaveCount(0)
        ->and(is_file($outputDir . '/config.m4'))->toBeTrue()
        ->and(is_file($outputDir . '/php_qt.h'))->toBeTrue()
        ->and(is_file($outputDir . '/qt.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_buildinfo.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qpoint.cpp'))->toBeTrue()
        ->and(is_file($outputDir . '/classes/qt_qpoint.stub.php'))->toBeTrue()
        ->and(is_file($metadataDir . '/runtime_manifest.json'))->toBeTrue()
        ->and(is_file($outputDir . '/configure'))->toBeFalse()
        ->and(is_file($outputDir . '/Makefile'))->toBeFalse()
        ->and(is_file($outputDir . '/build/gen_stub.php'))->toBeFalse()
        ->and($result['display'])->toContain('Skipping bootstrap (--no-build).');

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['bootstrap_disabled'] ?? null)->toBeTrue()
        ->and($summary['bootstrap'])->toBeNull()
        ->and($summary['bootstrap_error'])->toBeNull()
        ->and($summary['runtime_manifest'] ?? null)->toBe($metadataDir . '/runtime_manifest.json');
});

it('rewrites cached allow lists to actual generated classes', function (): void {
    $fixtureRoot = qt_fixture_path('policy-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-stable-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
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
    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeSuccessfulCommandResult();
    expect($result['display'])->toContain('Generate analysis pass 1', 'Module acceptance:', 'Module Name', 'QtCore');
    expect(
        str_contains($result['display'], 'Using cached build metadata:')
        || str_contains($result['display'], 'Discovery cache miss; invoking build:discover.'),
    )->toBeTrue();
    expect(substr_count($result['display'], 'Module acceptance:'))->toBe(1);

    $allowedClasses = qt_decode_json((string) file_get_contents($metadataDir . '/allowed_classes.json'));
    expect($allowedClasses)->toBe(['QCStringHolder', 'QParentThing']);

    $acceptedCandidates = qt_decode_json((string) file_get_contents($metadataDir . '/accepted_candidates.json'));
    expect(array_column($acceptedCandidates, 'class'))->toBe(['QCStringHolder', 'QParentThing']);

    $classmap = qt_decode_json((string) file_get_contents($metadataDir . '/classmap.json'));
    expect(array_column($classmap, 'class'))->toBe(['QCStringHolder', 'QParentThing']);

    $skippedClasses = qt_decode_json((string) file_get_contents($metadataDir . '/skipped_classes.json'));
    $skippedByClass = [];
    foreach ($skippedClasses as $skippedClass) {
        $skippedByClass[$skippedClass['class']] = $skippedClass['reason_code'];
    }
    expect($skippedByClass['QChildThing'] ?? null)->toBe('unsupported_parent_class');

    $supplementalCandidates = qt_decode_json((string) file_get_contents($metadataDir . '/supplemental_candidates.json'));
    expect($supplementalCandidates)->toHaveCount(1)
        ->and($supplementalCandidates[0]['class'])->toBe('QParentThing')
        ->and($supplementalCandidates[0]['discovered_from_class'])->toBe('QChildThing')
        ->and($supplementalCandidates[0]['trigger_reason'])->toBe('unsupported_parent_class');

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['generation_passes'])->toBe(1)
        ->and($summary['generated_classes'])->toBe(2)
        ->and($summary['skipped_classes'])->toBe(1);
});

it('reuses the generation analysis cache on unchanged builds', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-skip-bootstrap-' . bin2hex(random_bytes(4));
    $metadataDir = $buildRoot . '/generated';
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
    expect($first)->toBeSuccessfulCommandResult();

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
        ->and($second['display'])->toContain('Enum holder cache: hit')
        ->and($second['display'])->toContain('Generation analysis cache: hit')
        ->and($second['display'])->not->toContain('Generate analysis pass 1')
        ->and($bootstrapper->contexts)->toHaveCount(2);

    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['cache']['generation_analysis']['hit'] ?? null)->toBeTrue()
        ->and($summary['file_writes']['total']['unchanged'] ?? 0)->toBeGreaterThan(0);
});

it('rejects an ext directory as the build root', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-invalid-' . bin2hex(random_bytes(4));
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot . '/ext',
            '--jobs' => '2',
        ],
    );

    expect($result)->toBeFailureCommandResult()
        ->and($result['display'])->toContain('--output must be a build root directory, not an extension directory.');
});

it('builds mapped Qt modules with static-manifest dependencies', function (): void {
    $fixtureRoot = qt_fixture_path('module-split-qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-unmapped-' . bin2hex(random_bytes(4));
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtSvg',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and($result['display'])->toContain(
            'Requested modules: QtSvg',
            'Auto-added dependency modules: QtCore, QtGui',
            'Expanded modules: QtCore, QtGui, QtSvg',
            'Skipping bootstrap (--no-build).',
        );

    $metadataDir = $buildRoot . '/generated';
    $summary = qt_decode_json((string) file_get_contents($metadataDir . '/build_summary.json'));
    expect($summary['modules'] ?? null)->toBe(['QtCore', 'QtGui', 'QtSvg'])
        ->and($summary['requested_modules'] ?? null)->toBe(['QtSvg'])
        ->and($summary['expanded_modules'] ?? null)->toBe(['QtCore', 'QtGui', 'QtSvg'])
        ->and($summary['generated_classes'] ?? null)->toBe(5);

    $runtimeManifest = qt_decode_json((string) file_get_contents($metadataDir . '/runtime_manifest.json'));
    expect($runtimeManifest['built_modules'] ?? null)->toBe(['QtCore', 'QtGui', 'QtSvg'])
        ->and($runtimeManifest['modules']['QtSvg']['dependencies'] ?? null)->toBe(['QtCore', 'QtGui']);
});

it('clears the build root before building when forced', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-force-' . bin2hex(random_bytes(4));
    $outputDir = $buildRoot . '/ext';
    $metadataDir = $buildRoot . '/generated';
    mkdir($metadataDir, 0777, true);
    file_put_contents($metadataDir . '/stale.txt', "stale\n");

    $bootstrapper = new FakeExtensionBootstrapper();
    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--force' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult()
        ->and($result['display'])->toContain('Cleared build root:', $buildRoot);
    expect(is_file($metadataDir . '/stale.txt'))->toBeFalse()
        ->and(is_file($metadataDir . '/build_summary.json'))->toBeTrue()
        ->and(is_file($outputDir . '/config.m4'))->toBeTrue();
});

it('refuses to force-clear the current working directory', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => '.',
            '--jobs' => '2',
            '--force' => true,
        ],
    );

    expect($result)->toBeFailureCommandResult()
        ->and($result['display'])->toContain('Refusing to clear the current working directory.');
});

it('fails when --ccache is requested but unavailable', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $bootstrapper = new FakeExtensionBootstrapper();
    $system = FakeSystemInformation::passing();
    $system->setExecutable('ccache', null);

    $result = qt_command_result(
        new BuildCommand($system, $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => sys_get_temp_dir() . '/qtbuilder-build-ccache-missing-' . bin2hex(random_bytes(4)),
            '--jobs' => '2',
            '--ccache' => true,
        ],
    );

    expect($result)->toBeFailureCommandResult()
        ->and($result['display'])->toContain('The --ccache option was set but ccache is not available on PATH.');
});

it('records ccache as enabled by default when ccache exists', function (): void {
    $fixtureRoot = qt_fixture_path('qt');
    $buildRoot = sys_get_temp_dir() . '/qtbuilder-build-ccache-enabled-' . bin2hex(random_bytes(4));
    $bootstrapper = new FakeExtensionBootstrapper();

    $result = qt_command_result(
        new BuildCommand(FakeSystemInformation::passing(), $bootstrapper),
        [
            '--qt-path' => $fixtureRoot,
            'modules' => 'QtCore',
            '--output' => $buildRoot,
            '--jobs' => '2',
            '--no-build' => true,
        ],
    );

    expect($result)->toBeSuccessfulCommandResult();

    $summary = qt_decode_json((string) file_get_contents($buildRoot . '/generated/build_summary.json'));
    expect($summary['ccache_enabled'] ?? null)->toBeTrue();
});
