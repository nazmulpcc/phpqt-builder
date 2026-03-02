<?php

declare(strict_types=1);

namespace QtBuilder\Filtering;

use QtBuilder\Scanning\HeaderCandidate;

class ClassExposurePolicy
{
    /** @var list<string> */
    private const array EXACT_SKIP = [
        'QMetaObject',
        'QMetaMethod',
        'QMetaEnum',
        'QMetaProperty',
        'QMetaType',
        'QObjectData',
        'QArgument',
        'QGenericArgument',
        'QGenericReturnArgument',
        'QFlag',
        'QFlags',
        'QArrayData',
        'QArrayDataPointer',
        'QArrayDataOps',
        'QFutureInterface',
        'QFutureInterfaceBase',
        'QFutureWatcherBase',
    ];

    /** @var list<string> */
    private const array PREFIX_SKIP = [
        'QMeta',
        'QArrayData',
        'QListSpecialMethods',
        'QAssociative',
        'QMutable',
        'QConst',
        'QIterator',
        'QSequential',
        'QProperty',
        'QBinding',
        'QInternal',
    ];

    /** @var list<string> */
    private const array SUFFIX_SKIP = [
        'Iterator',
        'Iterable',
        'View',
        'List',
        'Map',
        'Hash',
        'Set',
        'Matcher',
        'Ref',
        'Private',
    ];

    public function decideCandidate(HeaderCandidate $candidate): ExposureDecision
    {
        return $this->decideClassName($candidate->className);
    }

    public function decideClassName(string $className): ExposureDecision
    {
        if (!preg_match('/^Q[A-Z][A-Za-z0-9_]*$/', $className)) {
            return ExposureDecision::skip('invalid_class_name', sprintf('Class %s does not look like a public Qt class.', $className));
        }

        if (in_array($className, self::EXACT_SKIP, true)) {
            return ExposureDecision::skip('class_filtered', sprintf('Class %s is explicitly filtered.', $className));
        }

        foreach (self::PREFIX_SKIP as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return ExposureDecision::skip('class_filtered', sprintf('Class %s matches filtered prefix %s.', $className, $prefix));
            }
        }

        foreach (self::SUFFIX_SKIP as $suffix) {
            if (str_ends_with($className, $suffix)) {
                return ExposureDecision::skip('class_filtered', sprintf('Class %s matches filtered suffix %s.', $className, $suffix));
            }
        }

        return ExposureDecision::accept();
    }
}
