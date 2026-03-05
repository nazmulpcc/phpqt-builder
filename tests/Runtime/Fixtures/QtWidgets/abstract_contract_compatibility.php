<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$targets = [
    'Qt\\Widgets\\QHeaderView' => [
        'visualRect' => 'visualRectModelIndex',
        'scrollTo' => 'scrollToModelIndexInt',
        'indexAt' => 'indexAtPoint',
    ],
    'Qt\\Gui\\QFileSystemModel' => [
        'index' => 'indexIntOrStringIntModelIndex',
        'parentModelIndex' => 'parentModelIndexAsModelIndex',
    ],
    'Qt\\Core\\QSortFilterProxyModel' => [
        'parentModelIndex' => 'parentModelIndexAsModelIndex',
    ],
    'Qt\\Gui\\QStandardItemModel' => [
        'parentModelIndex' => 'parentModelIndexAsModelIndex',
    ],
    'Qt\\Core\\QIdentityProxyModel' => [
        'parentModelIndex' => 'parentModelIndexAsModelIndex',
    ],
    'Qt\\Core\\QTransposeProxyModel' => [
        'parentModelIndex' => 'parentModelIndexAsModelIndex',
    ],
    'Qt\\Core\\QConcatenateTablesProxyModel' => [
        'parentModelIndex' => 'parentModelIndexAsModelIndex',
    ],
    'Qt\\Core\\QRangeModel' => [
        'parentModelIndex' => 'parentModelIndexAsModelIndex',
    ],
    'Qt\\Widgets\\QGraphicsPixmapItem' => [
        'paint' => 'paintPainterStyleOptionGraphicsItemWidget',
    ],
    'Qt\\Widgets\\QGraphicsSimpleTextItem' => [
        'paint' => 'paintPainterStyleOptionGraphicsItemWidget',
    ],
];

$offenders = [];

foreach ($targets as $class => $pairs) {
    qt_runtime_require_class($class, sprintf('%s is unavailable in this build.', $class));

    $rc = new ReflectionClass($class);
    $classMethods = [];
    foreach ($rc->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() === $class) {
            $classMethods[$method->getName()] = $method;
        }
    }

    $mismatches = [];
    foreach ($pairs as $expected => $renamed) {
        $expectedImplemented = isset($classMethods[$expected]) && !$classMethods[$expected]->isAbstract();
        $renamedImplemented = isset($classMethods[$renamed]) && !$classMethods[$renamed]->isAbstract();

        if (!$expectedImplemented && $renamedImplemented) {
            $mismatches[] = [
                'missing' => $expected,
                'renamed' => $renamed,
            ];
        }
    }

    if ($mismatches !== [] || $rc->isAbstract()) {
        $offenders[] = [
            'class' => $class,
            'is_abstract' => $rc->isAbstract(),
            'mismatches' => $mismatches,
        ];
    }
}

qt_runtime_result([
    'offenders' => $offenders,
]);
