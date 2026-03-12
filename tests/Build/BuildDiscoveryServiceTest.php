<?php

declare(strict_types=1);

use QtBuilder\Build\BuildDiscoveryService;
use QtBuilder\Build\BuildDiscoveryResult;
use QtBuilder\Scanning\HeaderCandidate;
use Symfony\Component\Console\Output\BufferedOutput;
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

it('reuses class cache entries for promoted nested classes on subsequent runs', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-cache-reuse-');
    $publicHeader = $fixtureDir . '/QNestedOwnerCache';
    $parseHeader = $fixtureDir . '/qnestedownercache.h';
    $outputDir = $fixtureDir . '/ext';
    $metadataDir = $fixtureDir . '/generated';

    file_put_contents($publicHeader, "#include \"qnestedownercache.h\"\n");
    file_put_contents($parseHeader, <<<'CPP'
#ifndef TEST_QNESTEDOWNERCACHE_H
#define TEST_QNESTEDOWNERCACHE_H

class QNestedOwnerCache
{
public:
    class Used
    {
    public:
        Used() {}
    };

    void setUsed(const QNestedOwnerCache::Used &value);
};

inline void QNestedOwnerCache::setUsed(const QNestedOwnerCache::Used &value) { (void) value; }

#endif
CPP);

    $candidate = new HeaderCandidate(
        module: 'QtCore',
        className: 'QNestedOwnerCache',
        publicHeader: $publicHeader,
        parseHeader: $parseHeader,
    );

    $service = new BuildDiscoveryService();
    $first = $service->prepareClassStructures(
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
        $first['accepted_candidates'],
    );
    expect($acceptedKeys)->toContain('QNestedOwnerCache', 'QNestedOwnerCache::Used');

    $buffer = new BufferedOutput();
    $second = $service->prepareClassStructures(
        $first['accepted_candidates'],
        $outputDir,
        [$fixtureDir],
        $metadataDir,
        1,
        $buffer,
        'qt',
    );

    expect($second['errors'])->toBe([])
        ->and($buffer->fetch())->toContain('Class structure cache: 2 hit(s), 0 miss(es)');
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

it('does not promote macro-declared non-public nested classes into prepared candidates', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-macro-access-discovery-');
    $publicHeader = $fixtureDir . '/QNestedMacroOwner';
    $parseHeader = $fixtureDir . '/qnestedmacroowner.h';
    $outputDir = $fixtureDir . '/ext';
    $metadataDir = $fixtureDir . '/generated';

    file_put_contents($publicHeader, "#include \"qnestedmacroowner.h\"\n");
    file_put_contents($parseHeader, <<<'CPP'
#ifndef TEST_QNESTEDMACROOWNER_H
#define TEST_QNESTEDMACROOWNER_H

#define DECL_TAG(name) struct name {}
class QNestedMacroOwner
{
private:
    DECL_TAG(HiddenPrivate);
protected:
    DECL_TAG(HiddenProtected);
public:
    class PublicType
    {
    public:
        PublicType() {}
    };

    void usePrivate(const QNestedMacroOwner::HiddenPrivate &value);
    void useProtected(const QNestedMacroOwner::HiddenProtected &value);
    void usePublic(const QNestedMacroOwner::PublicType &value);
};

inline void QNestedMacroOwner::usePrivate(const QNestedMacroOwner::HiddenPrivate &value) { (void) value; }
inline void QNestedMacroOwner::useProtected(const QNestedMacroOwner::HiddenProtected &value) { (void) value; }
inline void QNestedMacroOwner::usePublic(const QNestedMacroOwner::PublicType &value) { (void) value; }

#endif
CPP);

    $candidate = new HeaderCandidate(
        module: 'QtCore',
        className: 'QNestedMacroOwner',
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
        ->and($acceptedKeys)->toContain('QNestedMacroOwner', 'QNestedMacroOwner::PublicType')
        ->and($acceptedKeys)->not->toContain('QNestedMacroOwner::HiddenPrivate')
        ->and($acceptedKeys)->not->toContain('QNestedMacroOwner::HiddenProtected')
        ->and(array_keys($result['prepared_class_data']))->toContain('QNestedMacroOwner', 'QNestedMacroOwner::PublicType')
        ->and(array_keys($result['prepared_class_data']))->not->toContain('QNestedMacroOwner::HiddenPrivate')
        ->and(array_keys($result['prepared_class_data']))->not->toContain('QNestedMacroOwner::HiddenProtected');
});

it('does not promote foreign-header macro nested classes when access cannot be safely attributed', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-nested-foreign-macro-discovery-');
    $publicHeader = $fixtureDir . '/QNestedForeignMacroOwner';
    $macroHeader = $fixtureDir . '/qmacro_tags.h';
    $parseHeader = $fixtureDir . '/qnestedforeignmacroowner.h';
    $outputDir = $fixtureDir . '/ext';
    $metadataDir = $fixtureDir . '/generated';

    file_put_contents($publicHeader, "#include \"qnestedforeignmacroowner.h\"\n");
    file_put_contents($macroHeader, <<<'CPP'
#define DECL_FOREIGN_TAG(name) struct name {}
CPP);
    file_put_contents($parseHeader, <<<'CPP'
#ifndef TEST_QNESTEDFOREIGNMACROOWNER_H
#define TEST_QNESTEDFOREIGNMACROOWNER_H

#include "qmacro_tags.h"

class QNestedForeignMacroOwner
{
private:
    DECL_FOREIGN_TAG(HiddenPrivate);
public:
    class PublicType
    {
    public:
        PublicType() {}
    };

    void usePrivate(const QNestedForeignMacroOwner::HiddenPrivate &value);
    void usePublic(const QNestedForeignMacroOwner::PublicType &value);
};

inline void QNestedForeignMacroOwner::usePrivate(const QNestedForeignMacroOwner::HiddenPrivate &value) { (void) value; }
inline void QNestedForeignMacroOwner::usePublic(const QNestedForeignMacroOwner::PublicType &value) { (void) value; }

#endif
CPP);

    $candidate = new HeaderCandidate(
        module: 'QtCore',
        className: 'QNestedForeignMacroOwner',
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
        ->and($acceptedKeys)->toContain('QNestedForeignMacroOwner', 'QNestedForeignMacroOwner::PublicType')
        ->and($acceptedKeys)->not->toContain('QNestedForeignMacroOwner::HiddenPrivate')
        ->and(array_keys($result['prepared_class_data']))->toContain('QNestedForeignMacroOwner', 'QNestedForeignMacroOwner::PublicType')
        ->and(array_keys($result['prepared_class_data']))->not->toContain('QNestedForeignMacroOwner::HiddenPrivate');
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

it('reuses class structure cache when candidate identity upgrades from provisional to qualified', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-class-cache-qualified-fallback-');
    $publicHeader = $fixtureDir . '/QNamespacedCacheThing';
    $parseHeader = $fixtureDir . '/qnamespacedcachething.h';
    $outputDir = $fixtureDir . '/ext';
    $metadataDir = $fixtureDir . '/generated';

    file_put_contents($publicHeader, "#include \"qnamespacedcachething.h\"\n");
    file_put_contents($parseHeader, <<<'CPP'
#ifndef TEST_QNAMESPACEDCACHETHING_H
#define TEST_QNAMESPACEDCACHETHING_H

namespace QCacheScope
{
class QNamespacedCacheThing
{
public:
    QNamespacedCacheThing() = default;
};
}

#endif
CPP);

    $candidate = new HeaderCandidate(
        module: 'QtCore',
        className: 'QNamespacedCacheThing',
        publicHeader: $publicHeader,
        parseHeader: $parseHeader,
    );

    $service = new BuildDiscoveryService();
    $first = $service->prepareClassStructures(
        [$candidate],
        $outputDir,
        [$fixtureDir],
        $metadataDir,
        1,
        new NullOutput(),
        'qt',
    );

    $resolvedCandidate = $first['accepted_candidates'][0] ?? null;
    expect($resolvedCandidate)->toBeInstanceOf(HeaderCandidate::class);
    expect($resolvedCandidate->qualifiedClassName)->not->toBeNull();

    $buffer = new BufferedOutput();
    $second = $service->prepareClassStructures(
        [$resolvedCandidate],
        $outputDir,
        [$fixtureDir],
        $metadataDir,
        1,
        $buffer,
        'qt',
    );

    expect($second['errors'])->toBe([])
        ->and($buffer->fetch())->toContain('Class structure cache: 1 hit(s), 0 miss(es)');
});

it('invalidates discovery cache when schema metadata is missing', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-discovery-cache-schema-miss-');
    $metadataDir = $fixtureDir . '/generated';
    @mkdir($metadataDir, 0777, true);

    file_put_contents($metadataDir . '/discovery_cache.json', json_encode([
        'modules' => ['QtCore'],
        'qt_path' => '/qt',
        'accepted_candidates' => [[
            'module' => 'QtCore',
            'class' => 'QObject',
            'qualified_name' => 'QObject',
            'generation_id' => 'qobject',
            'public_header' => '/qt/QObject',
            'parse_header' => '/qt/qobject.h',
        ]],
        'allowed_classes' => ['QObject'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $service = new BuildDiscoveryService();
    $loaded = $service->loadCache($metadataDir, ['QtCore'], '/qt');

    expect($loaded)->toBeNull();
});

it('invalidates discovery cache when class-cache schema does not match', function (): void {
    $fixtureDir = qt_temp_dir('qtbuilder-discovery-cache-class-schema-miss-');
    $metadataDir = $fixtureDir . '/generated';
    @mkdir($metadataDir, 0777, true);

    $candidate = new HeaderCandidate(
        module: 'QtCore',
        className: 'QObject',
        publicHeader: '/qt/QObject',
        parseHeader: '/qt/qobject.h',
        qualifiedClassName: 'QObject',
        generationId: 'qobject',
    );
    $result = new BuildDiscoveryResult(
        acceptedCandidates: [$candidate],
        skippedClasses: [],
        allowedClasses: ['QObject'],
        candidateCount: 1,
    );

    $service = new BuildDiscoveryService();
    $service->writeCache($metadataDir, ['QtCore'], '/qt', $result);

    $cachePath = $metadataDir . '/discovery_cache.json';
    $payload = json_decode((string) file_get_contents($cachePath), true);
    expect($payload)->toBeArray();
    $payload['class_cache_schema_version'] = -1;
    file_put_contents($cachePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $loaded = $service->loadCache($metadataDir, ['QtCore'], '/qt');
    expect($loaded)->toBeNull();
});
