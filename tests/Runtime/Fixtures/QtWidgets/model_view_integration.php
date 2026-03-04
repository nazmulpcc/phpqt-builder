<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Widgets\QApplication::class, 'QtWidgets classes are unavailable in this build.');

final class RuntimeStandardItemModel extends \Qt\Gui\QStandardItemModel
{
    public function __construct()
    {
        parent::__construct();
    }

    public function parentModelIndex(\Qt\Core\QModelIndex $child): \Qt\Core\QModelIndex
    {
        return parent::parentModelIndex($child);
    }
}

final class RuntimeIdentityProxyModel extends \Qt\Core\QIdentityProxyModel
{
    public function __construct()
    {
        parent::__construct();
    }

    public function parentModelIndex(\Qt\Core\QModelIndex $child): \Qt\Core\QModelIndex
    {
        return parent::parentModelIndex($child);
    }
}

final class RuntimeStringListModel extends \Qt\Core\QStringListModel
{
    public function __construct()
    {
        parent::__construct();
    }

    public function columnCount(?\Qt\Core\QModelIndex $parent = null): int
    {
        return parent::columnCount($parent);
    }

    public function parentModelIndex(\Qt\Core\QModelIndex $child): \Qt\Core\QModelIndex
    {
        return parent::parentModelIndex($child);
    }
}

final class RuntimeSortFilterProxyModel extends \Qt\Core\QSortFilterProxyModel
{
    public function __construct()
    {
        parent::__construct();
    }

    public function parentModelIndex(\Qt\Core\QModelIndex $child): \Qt\Core\QModelIndex
    {
        return parent::parentModelIndex($child);
    }
}

$app = new \Qt\Widgets\QApplication();

$sourceModel = new RuntimeStandardItemModel();
$sourceModel->setHorizontalHeaderItem(0, new \Qt\Gui\QStandardItem('Order'));
$sourceModel->setHorizontalHeaderItem(1, new \Qt\Gui\QStandardItem('Region'));

$rows = [
    ['SO-001', 'EMEA'],
    ['SO-002', 'APAC'],
    ['SO-003', 'EMEA'],
];

foreach ($rows as $rowIndex => $row) {
    foreach ($row as $columnIndex => $value) {
        $item = new \Qt\Gui\QStandardItem();
        $item->setText($value);
        $sourceModel->setItem($rowIndex, $columnIndex, $item);
    }
}

$identityProxy = new RuntimeIdentityProxyModel();
$identityProxy->setSourceModel($sourceModel);

$stringSource = new RuntimeStringListModel();
$stringSource->insertRows(0, 4);
foreach (['Ticket 1 | EMEA', 'Ticket 2 | APAC', 'Ticket 3 | EMEA', 'Ticket 4 | LATAM'] as $row => $value) {
    $stringSource->setData($stringSource->index($row, 0), new \Qt\Core\QVariant($value));
}

$filterProxy = new RuntimeSortFilterProxyModel();
$filterProxy->setSourceModel($stringSource);
$filterProxy->setFilterKeyColumn(0);

$table = new \Qt\Widgets\QTableView();
$table->setModel($identityProxy);
$list = new \Qt\Widgets\QListView();
$list->setModel($filterProxy);

$addButton = new \Qt\Widgets\QPushButton('Add');
$clickCount = 0;
$addButton->onClicked(function (bool $checked = false) use (&$clickCount, $sourceModel): void {
    $clickCount++;
    $row = $sourceModel->rowCount();
    $order = new \Qt\Gui\QStandardItem();
    $order->setText('SO-00' . ($row + 1));
    $region = new \Qt\Gui\QStandardItem();
    $region->setText('AMER');
    $sourceModel->setItem($row, 0, $order);
    $sourceModel->setItem($row, 1, $region);
});

$addButton->click();
$filterProxy->setFilterFixedString('EMEA');
$filteredRows = $filterProxy->rowCount();
$filterProxy->setFilterFixedString('');

qt_runtime_result([
    'source_rows' => $sourceModel->rowCount(),
    'identity_rows' => $identityProxy->rowCount(),
    'filtered_rows' => $filteredRows,
    'cleared_rows' => $filterProxy->rowCount(),
    'button_clicks' => $clickCount,
]);
