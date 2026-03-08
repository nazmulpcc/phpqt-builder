<?php

declare(strict_types=1);

use QtBuilder\Support\SmartPointerAliasResolver;

it('discovers qsharedpointer typedef aliases from the current header and local includes', function (): void {
    $root = sys_get_temp_dir() . '/qt-smart-pointer-alias-' . bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    $mainHeader = $root . '/qaspectengine.h';
    $entityHeader = $root . '/qentity.h';

    file_put_contents($entityHeader, <<<'CPP'
class QEntity;
CPP);

    file_put_contents($mainHeader, <<<'CPP'
#include "qentity.h"
template <typename T> class QSharedPointer;
typedef QSharedPointer<QEntity> QEntityPtr;
using QOtherEntityPtr = QSharedPointer<QEntity>;
CPP);

    $resolver = new SmartPointerAliasResolver();
    $aliases = $resolver->discover($mainHeader);

    expect($aliases)->toHaveKey('QEntityPtr', 'QEntity')
        ->and($aliases)->toHaveKey('QOtherEntityPtr', 'QEntity')
        ->and($resolver->resolve($mainHeader, 'QEntityPtr'))->toBe('QEntity');
});
