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
