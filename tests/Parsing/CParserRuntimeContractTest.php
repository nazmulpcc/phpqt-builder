<?php

declare(strict_types=1);

use CParser\Access;
use CParser\ClassCursor;
use CParser\CursorKind;
use CParser\MethodCursor;
use CParser\TranslationUnit;
use CParser\TranslationUnitFlags;

it('exposes runtime cursor kind constants', function (): void {
    $constants = (new ReflectionClass(CursorKind::class))->getConstants();

    expect($constants)->not->toBe([]);
});

it('reports include graph entries and alias declarations from translation unit', function (): void {
    $fixtureDir = qt_temp_dir('cparser-contract-');
    $depHeader = $fixtureDir . '/dep.h';
    $mainHeader = $fixtureDir . '/sample.h';

    file_put_contents($depHeader, <<<'CPP'
class Dep {};
CPP);

    file_put_contents($mainHeader, <<<'CPP'
#include "dep.h"

template <class T>
class Vec {};

using IntVec = Vec<int>;
typedef Vec<double> DoubleVec;
CPP);

    $tu = TranslationUnit::fromFile(
        $mainHeader,
        ['-x', 'c++', '-std=c++17', '-I' . $fixtureDir],
        TranslationUnitFlags::KeepGoing
            | TranslationUnitFlags::SkipFunctionBodies
            | TranslationUnitFlags::DetailedPreprocessingRecord,
    );

    $includeEntries = iterator_to_array($tu->includes(), false);
    expect($includeEntries)->not->toBe([]);

    $includeForDep = null;
    foreach ($includeEntries as $entry) {
        if (basename((string) $entry->getIncludedFile()) === 'dep.h') {
            $includeForDep = $entry;
            break;
        }
    }

    expect($includeForDep)->not->toBeNull()
        ->and($includeForDep->getSpelling())->toBe('"dep.h"')
        ->and($includeForDep->isAngled())->toBeFalse()
        ->and((string) $includeForDep->getSourceFile())->toBe($mainHeader);

    $aliases = iterator_to_array($tu->aliases(), false);
    $aliasesByName = [];
    foreach ($aliases as $alias) {
        $aliasesByName[$alias->getSpelling()] = $alias->getUnderlyingType()->toString();
    }

    expect($aliasesByName)->toHaveKeys(['IntVec', 'DoubleVec'])
        ->and($aliasesByName['IntVec'])->toContain('Vec')
        ->and($aliasesByName['DoubleVec'])->toContain('Vec');
});

it('exposes constructor semantic flags and rich base specifiers', function (): void {
    $fixtureDir = qt_temp_dir('cparser-contract-');
    $header = $fixtureDir . '/semantics.h';

    file_put_contents($header, <<<'CPP'
template <class T>
class Vec {};

class B {};

class A : public virtual Vec<int>, private B
{
public:
    A() = default;
    A(const A&) = delete;
    A(A&&) = default;
    explicit A(int);
    virtual void f() final {}
};
CPP);

    $tu = TranslationUnit::fromFile(
        $header,
        ['-x', 'c++', '-std=c++17', '-I' . $fixtureDir],
        TranslationUnitFlags::KeepGoing | TranslationUnitFlags::SkipFunctionBodies,
    );

    $classA = null;
    foreach ($tu->classes() as $class) {
        if ($class->getSpelling() === 'A') {
            $classA = $class;
            break;
        }
    }

    expect($classA)->toBeInstanceOf(ClassCursor::class);

    $baseSpecifiers = iterator_to_array($classA->getBaseSpecifiers(), false);
    expect($baseSpecifiers)->toHaveCount(2);

    $vecBase = null;
    $bBase = null;
    foreach ($baseSpecifiers as $base) {
        $type = $base->getType()?->toString() ?? '';
        if (str_contains($type, 'Vec')) {
            $vecBase = $base;
            continue;
        }
        if ($type === 'B') {
            $bBase = $base;
        }
    }

    expect($vecBase)->not->toBeNull()
        ->and($vecBase->isVirtual())->toBeTrue()
        ->and($vecBase->getAccessSpecifier())->toBe(Access::Public)
        ->and($vecBase->getReferenced())->not->toBeNull();

    expect($bBase)->not->toBeNull()
        ->and($bBase->isVirtual())->toBeFalse()
        ->and($bBase->getAccessSpecifier())->toBe(Access::Private)
        ->and($bBase->getReferenced())->toBeInstanceOf(ClassCursor::class);

    expect(defined(CursorKind::class . '::CXXConstructor'))->toBeTrue();
    $constructorKind = constant(CursorKind::class . '::CXXConstructor');
    $constructors = iterator_to_array($classA->getChildren($constructorKind), false);

    expect($constructors)->not->toBe([]);

    $defaultCtor = null;
    $copyCtor = null;
    $moveCtor = null;
    $explicitCtor = null;

    foreach ($constructors as $ctor) {
        expect($ctor)->toBeInstanceOf(MethodCursor::class);

        /** @var MethodCursor $ctor */
        $params = iterator_to_array($ctor->getParameters(), false);
        if (count($params) === 0) {
            $defaultCtor = $ctor;
            continue;
        }

        $type = $params[0]->getType()?->toString() ?? '';
        if (preg_match('/\bconst\s+A\s*&/', $type) === 1) {
            $copyCtor = $ctor;
            continue;
        }

        if (preg_match('/\bA\s*&&/', $type) === 1) {
            $moveCtor = $ctor;
            continue;
        }

        if ($type === 'int') {
            $explicitCtor = $ctor;
        }
    }

    expect($defaultCtor)->not->toBeNull()
        ->and($defaultCtor->isDefaultConstructor())->toBeTrue()
        ->and($defaultCtor->isDefaulted())->toBeTrue()
        ->and($defaultCtor->isDeleted())->toBeFalse();

    expect($copyCtor)->not->toBeNull()
        ->and($copyCtor->isCopyConstructor())->toBeTrue()
        ->and($copyCtor->isDeleted())->toBeTrue();

    expect($moveCtor)->not->toBeNull()
        ->and($moveCtor->isMoveConstructor())->toBeTrue()
        ->and($moveCtor->isDefaulted())->toBeTrue();

    expect($explicitCtor)->not->toBeNull()
        ->and($explicitCtor->isExplicit())->toBeTrue();

    $fMethod = null;
    foreach ($classA->getMethods() as $method) {
        if ($method->getSpelling() === 'f') {
            $fMethod = $method;
            break;
        }
    }

    expect($fMethod)->toBeInstanceOf(MethodCursor::class)
        ->and($fMethod->isFinal())->toBeTrue()
        ->and($fMethod->isVirtual())->toBeTrue();
});
