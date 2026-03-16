<?php

declare(strict_types=1);

use QtBuilder\Tests\Support\GenerateBuildModeRunner;
use Symfony\Component\Console\Command\Command;

it('returns json and writes files in build mode', function (): void {
    $result = GenerateBuildModeRunner::run('qt', [
        'header' => qt_fixture_path('qt/include/QtCore/qpoint.h'),
        'class' => 'QPoint',
        '--qt-path' => qt_fixture_path('qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => 'QPoint',
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and($result->payload['class'])->toBe('QPoint')
        ->and(is_file($result->path('QPoint', 'cpp')))->toBeTrue()
        ->and(is_file($result->path('QPoint', 'h')))->toBeTrue()
        ->and(is_file($result->path('QPoint', 'stub.php')))->toBeTrue()
        ->and(array_column($result->payload['skipped_methods'], 'name'))->toContain('rx');
});

it('returns json without writing files in probe mode', function (): void {
    $result = GenerateBuildModeRunner::run('qt', [
        'header' => qt_fixture_path('qt/include/QtCore/qpoint.h'),
        'class' => 'QPoint',
        '--qt-path' => qt_fixture_path('qt'),
        '--module' => 'QtCore',
        '--worker-mode' => 'probe',
        '--allowed-classes' => 'QPoint',
    ], 'qtbuilder-probe-');

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and($result->payload['class'])->toBe('QPoint')
        ->and(array_key_exists('generated_files', $result->payload))->toBeFalse()
        ->and(is_file($result->path('QPoint', 'cpp')))->toBeFalse()
        ->and(is_file($result->path('QPoint', 'h')))->toBeFalse()
        ->and(is_file($result->path('QPoint', 'stub.php')))->toBeFalse();
});

it('uses explicit includes even when qt path is invalid', function (): void {
    $result = GenerateBuildModeRunner::run('qt', [
        'header' => qt_fixture_path('qt/include/QtCore/qpoint.h'),
        'class' => 'QPoint',
        '--qt-path' => '/definitely/not/a/qt/root',
        '--include' => [
            qt_fixture_path('qt/include'),
            qt_fixture_path('qt/include/QtCore'),
        ],
        '--module' => 'QtCore',
        '--allowed-classes' => 'QPoint',
    ], 'qtbuilder-generate-includes-');

    expect($result->exitCode)->toBe(Command::SUCCESS, $result->display)
        ->and($result->payload['status'])->toBe('ok')
        ->and(is_file($result->path('QPoint', 'cpp')))->toBeTrue();
});

it('can load allowed classes from a json file', function (): void {
    $outputDir = qt_temp_dir('qtbuilder-generate-');
    $allowedClassesFile = $outputDir . '/allowed_classes.json';
    file_put_contents($allowedClassesFile, json_encode(['QTree', 'QNode'], JSON_THROW_ON_ERROR));

    $result = GenerateBuildModeRunner::run('qt', [
        'header' => qt_fixture_path('qt/include/QtCore/qtree.h'),
        'class' => 'QTree',
        '--qt-path' => qt_fixture_path('qt'),
        '--module' => 'QtCore',
        '--allowed-classes-file' => $allowedClassesFile,
        '--output' => $outputDir,
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and(is_file($result->path('QTree', 'cpp')))->toBeTrue();
});

it('returns multiple class facts from a single facts-batch worker payload', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-facts-batch-');
    $headerPath = $fixtureDir . '/qbatchfacts.h';
    $batchFile = $fixtureDir . '/facts_batch.json';

    file_put_contents($headerPath, <<<'CPP'
class QBatchFirst
{
public:
    int value() const;
};

class QBatchSecond
{
public:
    int value() const;
};

inline int QBatchFirst::value() const { return 1; }
inline int QBatchSecond::value() const { return 2; }
CPP);

    file_put_contents($batchFile, json_encode([
        ['class' => 'QBatchFirst', 'task_key' => 'task-first'],
        ['class' => 'QBatchSecond', 'task_key' => 'task-second'],
    ], JSON_THROW_ON_ERROR));

    $result = GenerateBuildModeRunner::run('qt', [
        'header' => $headerPath,
        'class' => 'QBatchFirst',
        '--module' => 'QtCore',
        '--worker-mode' => 'facts-batch',
        '--class-batch-file' => $batchFile,
        '--include' => [$fixtureDir],
    ], 'qtbuilder-facts-batch-');

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'] ?? null)->toBe('ok')
        ->and(is_array($result->payload['results'] ?? null))->toBeTrue()
        ->and(array_column($result->payload['results'], 'class'))->toBe(['QBatchFirst', 'QBatchSecond'])
        ->and(array_column($result->payload['results'], 'task_key'))->toBe(['task-first', 'task-second'])
        ->and(array_column($result->payload['results'], 'status'))->toBe(['ok', 'ok']);
});

it('guards qmenu dock menu helper on ios builds', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-qmenu-ios-');
    $headerPath = $fixtureDir . '/qmenu.h';

    file_put_contents($headerPath, <<<'CPP'
class QMenu
{
public:
    void setAsDockMenu();
};
CPP);

    $result = GenerateBuildModeRunner::run('qt', [
        'header' => $headerPath,
        'class' => 'QMenu',
        '--module' => 'QtWidgets',
        '--include' => [$fixtureDir],
        '--allowed-classes' => 'QMenu',
    ], 'qtbuilder-qmenu-ios-');

    $cpp = (string) file_get_contents($result->path('QMenu', 'cpp'));

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($cpp)->toContain('#if defined(Q_OS_IOS)')
        ->and($cpp)->toContain('Qt\\\\Widgets\\\\QMenu::setAsDockMenu() is not available on iOS.')
        ->and($cpp)->toContain('intern->native_ptr->setAsDockMenu();');
});
