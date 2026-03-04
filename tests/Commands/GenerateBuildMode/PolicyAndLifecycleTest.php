<?php

declare(strict_types=1);

use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Tests\Support\GenerateBuildModeRunner;
use Symfony\Component\Console\Command\Command;

dataset('private lifecycle classes', [
    'explicit private lifecycle' => [
        'qprivatelifecyclething.h',
        'QPrivateLifecycleThing',
        'RETURN_LONG((zend_long)(QPrivateLifecycleThing::version()));',
    ],
    'implicit private lifecycle' => [
        'qdefaultprivatelifecyclething.h',
        'QDefaultPrivateLifecycleThing',
        'RETURN_LONG((zend_long)(QDefaultPrivateLifecycleThing::version()));',
    ],
]);

it('skips template classes', function (): void {
    $result = GenerateBuildModeRunner::run('policy-qt', [
        'header' => qt_fixture_path('policy-qt/include/QtCore/qtemplatething.h'),
        'class' => 'QTemplateThing',
        '--qt-path' => qt_fixture_path('policy-qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => 'QTemplateThing',
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('skipped')
        ->and($result->payload['reason_code'])->toBe('template_class')
        ->and(is_file($result->path('QTemplateThing', 'cpp')))->toBeFalse();
});

it('skips classes with unsupported parents', function (): void {
    $result = GenerateBuildModeRunner::run('policy-qt', [
        'header' => qt_fixture_path('policy-qt/include/QtCore/qchildthing.h'),
        'class' => 'QChildThing',
        '--qt-path' => qt_fixture_path('policy-qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => 'QChildThing',
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('skipped')
        ->and($result->payload['reason_code'])->toBe('unsupported_parent_class')
        ->and(is_file($result->path('QChildThing', 'cpp')))->toBeFalse();
});

it('detects qdisablecopy and skips copy constructor exposure', function (): void {
    $fixtureRoot = qt_fixture_path('policy-qt');
    $result = (new ClassGenerationService())->generate(
        $fixtureRoot . '/include/QtCore/qnocopything.h',
        'QNoCopyThing',
        [$fixtureRoot . '/include', $fixtureRoot . '/include/QtCore'],
        ['QNoCopyThing'],
    );

    expect($result->status)->toBe('ok')
        ->and($result->phpClass)->not->toBeNull()
        ->and($result->phpClass->isCopyConstructible)->toBeFalse();

    $valueMethods = array_values(array_filter(
        $result->phpClass->methods,
        static fn(PhpMethod $method): bool => $method->name === 'value',
    ));

    expect($valueMethods)->toHaveCount(1);
});

it('skips protected default constructors and keeps public constructors', function (): void {
    $result = GenerateBuildModeRunner::run('policy-qt', [
        'header' => qt_fixture_path('policy-qt/include/QtCore/qprotecteddefaultthing.h'),
        'class' => 'QProtectedDefaultThing',
        '--qt-path' => qt_fixture_path('policy-qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => 'QProtectedDefaultThing',
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and(array_column($result->payload['skipped_methods'], 'name'))->toContain('QProtectedDefaultThing')
        ->and(array_column($result->payload['skipped_methods'], 'reason_code'))->toContain('non_public_constructor')
        ->and($result->stub('QProtectedDefaultThing'))->toContain('public function __construct(int $value)')
        ->and($result->cpp('QProtectedDefaultThing'))->toContain('new QProtectedDefaultThing((int)value)')
        ->and($result->cpp('QProtectedDefaultThing'))->not->toContain('new QProtectedDefaultThing()');
});

it('keeps private lifecycle classes non-constructible and non-deletable', function (string $header, string $class, string $versionFragment): void {
    $result = GenerateBuildModeRunner::run('policy-qt', [
        'header' => qt_fixture_path('policy-qt/include/QtCore/' . $header),
        'class' => $class,
        '--qt-path' => qt_fixture_path('policy-qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => $class,
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and(array_column($result->payload['skipped_methods'], 'name'))->toContain($class)
        ->and($result->cpp($class))->not->toContain('ZEND_METHOD(Qt_Core_' . $class . ', __construct)')
        ->and($result->cpp($class))->not->toContain('delete intern->native_ptr;')
        ->and($result->cpp($class))->toContain($versionFragment);
})->with('private lifecycle classes');

it('skips qt disambiguation tag parameters', function (): void {
    $result = GenerateBuildModeRunner::run('policy-qt', [
        'header' => qt_fixture_path('policy-qt/include/QtCore/qdisambiguationholder.h'),
        'class' => 'QDisambiguationHolder',
        '--qt-path' => qt_fixture_path('policy-qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => 'QDisambiguationHolder',
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and(array_column($result->payload['skipped_methods'], 'name'))->toContain('count')
        ->and(array_column($result->payload['skipped_methods'], 'reason_code'))->toContain('unsupported_parameter_type')
        ->and($result->stub('QDisambiguationHolder'))->toContain('public function value(): int {}')
        ->and($result->stub('QDisambiguationHolder'))->not->toContain('public function count')
        ->and($result->cpp('QDisambiguationHolder'))->not->toContain('ZEND_METHOD(Qt_Core_QDisambiguationHolder, count)');
});
