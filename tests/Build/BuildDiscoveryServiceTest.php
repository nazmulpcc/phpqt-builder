<?php

declare(strict_types=1);

use QtBuilder\Build\BuildDiscoveryService;
use QtBuilder\Scanning\HeaderCandidate;
use Symfony\Component\Console\Output\NullOutput;

it('promotes referenced nested class facts into prepared candidates in one class-structure pass', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-discovery-');
    $publicHeader = $fixtureDir . '/QNestedOwner';
    $parseHeader = $fixtureDir . '/qnestedowner.h';
    $outputDir = $fixtureDir . '/ext';
    $metadataDir = $fixtureDir . '/generated';

    file_put_contents($publicHeader, "#include \"qnestedowner.h\"\n");
    file_put_contents($parseHeader, <<<'CPP'
#ifndef TEST_QNESTEDOWNER_H
#define TEST_QNESTEDOWNER_H

class QNestedOwner
{
public:
    class Used
    {
    public:
        Used() {}
    };

    class Unused
    {
    public:
        Unused() {}
    };

    void setUsed(const QNestedOwner::Used &value);
    QNestedOwner::Used used() const;
};

inline void QNestedOwner::setUsed(const QNestedOwner::Used &value) { (void) value; }
inline QNestedOwner::Used QNestedOwner::used() const { return QNestedOwner::Used(); }

#endif
CPP);

    $candidate = new HeaderCandidate(
        module: 'QtCore',
        className: 'QNestedOwner',
        publicHeader: $publicHeader,
        parseHeader: $parseHeader,
    );

    $service = new BuildDiscoveryService();
    $result = $service->prepareClassStructures(
        [$candidate],
        $outputDir,
        [$fixtureDir],
        $metadataDir,
        1,
        new NullOutput(),
        'qt',
    );

    $acceptedKeys = array_map(
        static fn (HeaderCandidate $item): string => $item->identityKey(),
        $result['accepted_candidates'],
    );

    expect($result['errors'])->toBe([])
        ->and($acceptedKeys)->toContain('QNestedOwner', 'QNestedOwner::Used')
        ->and($acceptedKeys)->not->toContain('QNestedOwner::Unused')
        ->and(array_keys($result['prepared_class_data']))->toContain('QNestedOwner', 'QNestedOwner::Used')
        ->and(($result['prepared_class_data']['QNestedOwner::Used']['module'] ?? null))->toBe('QtCore');
});

it('does not promote referenced non-public nested classes into prepared candidates', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-non-public-discovery-');
    $publicHeader = $fixtureDir . '/QNestedAccessOwner';
    $parseHeader = $fixtureDir . '/qnestedaccessowner.h';
    $outputDir = $fixtureDir . '/ext';
    $metadataDir = $fixtureDir . '/generated';

    file_put_contents($publicHeader, "#include \"qnestedaccessowner.h\"\n");
    file_put_contents($parseHeader, <<<'CPP'
#ifndef TEST_QNESTEDACCESSOWNER_H
#define TEST_QNESTEDACCESSOWNER_H

class QNestedAccessOwner
{
public:
    class PublicType
    {
    public:
        PublicType() {}
    };

protected:
    class ProtectedType
    {
    public:
        ProtectedType() {}
    };

public:
    void usePublic(const QNestedAccessOwner::PublicType &value);
    void useProtected(const QNestedAccessOwner::ProtectedType &value);
};

inline void QNestedAccessOwner::usePublic(const QNestedAccessOwner::PublicType &value) { (void) value; }
inline void QNestedAccessOwner::useProtected(const QNestedAccessOwner::ProtectedType &value) { (void) value; }

#endif
CPP);

    $candidate = new HeaderCandidate(
        module: 'QtCore',
        className: 'QNestedAccessOwner',
        publicHeader: $publicHeader,
        parseHeader: $parseHeader,
    );

    $service = new BuildDiscoveryService();
    $result = $service->prepareClassStructures(
        [$candidate],
        $outputDir,
        [$fixtureDir],
        $metadataDir,
        1,
        new NullOutput(),
        'qt',
    );

    $acceptedKeys = array_map(
        static fn (HeaderCandidate $item): string => $item->identityKey(),
        $result['accepted_candidates'],
    );

    expect($result['errors'])->toBe([])
        ->and($acceptedKeys)->toContain('QNestedAccessOwner', 'QNestedAccessOwner::PublicType')
        ->and($acceptedKeys)->not->toContain('QNestedAccessOwner::ProtectedType')
        ->and(array_keys($result['prepared_class_data']))->toContain('QNestedAccessOwner', 'QNestedAccessOwner::PublicType')
        ->and(array_keys($result['prepared_class_data']))->not->toContain('QNestedAccessOwner::ProtectedType');
});

it('does not promote referenced nested classes whose names are php reserved identifiers', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-reserved-discovery-');
    $publicHeader = $fixtureDir . '/QNestedReservedOwner';
    $parseHeader = $fixtureDir . '/qnestedreservedowner.h';
    $outputDir = $fixtureDir . '/ext';
    $metadataDir = $fixtureDir . '/generated';

    file_put_contents($publicHeader, "#include \"qnestedreservedowner.h\"\n");
    file_put_contents($parseHeader, <<<'CPP'
#ifndef TEST_QNESTEDRESERVEDOWNER_H
#define TEST_QNESTEDRESERVEDOWNER_H

class QNestedReservedOwner
{
public:
    class NormalType
    {
    public:
        NormalType() {}
    };

    class Private
    {
    public:
        Private() {}
    };

    void useNormal(const QNestedReservedOwner::NormalType &value);
    void useReserved(const QNestedReservedOwner::Private &value);
};

inline void QNestedReservedOwner::useNormal(const QNestedReservedOwner::NormalType &value) { (void) value; }
inline void QNestedReservedOwner::useReserved(const QNestedReservedOwner::Private &value) { (void) value; }

#endif
CPP);

    $candidate = new HeaderCandidate(
        module: 'QtCore',
        className: 'QNestedReservedOwner',
        publicHeader: $publicHeader,
        parseHeader: $parseHeader,
    );

    $service = new BuildDiscoveryService();
    $result = $service->prepareClassStructures(
        [$candidate],
        $outputDir,
        [$fixtureDir],
        $metadataDir,
        1,
        new NullOutput(),
        'qt',
    );

    $acceptedKeys = array_map(
        static fn (HeaderCandidate $item): string => $item->identityKey(),
        $result['accepted_candidates'],
    );

    expect($result['errors'])->toBe([])
        ->and($acceptedKeys)->toContain('QNestedReservedOwner', 'QNestedReservedOwner::NormalType')
        ->and($acceptedKeys)->not->toContain('QNestedReservedOwner::Private')
        ->and(array_keys($result['prepared_class_data']))->toContain('QNestedReservedOwner', 'QNestedReservedOwner::NormalType')
        ->and(array_keys($result['prepared_class_data']))->not->toContain('QNestedReservedOwner::Private');
});
