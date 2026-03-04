<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QAbstractTableModel::class, 'QtCore abstract item model classes are unavailable in this build.');

final class RuntimeTableModel extends \Qt\Core\QAbstractTableModel
{
    /** @var list<array{0:string,1:string}> */
    private array $rows = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function rowCount(?\Qt\Core\QModelIndex $parent = null): int
    {
        if ($parent !== null && $parent->isValid()) {
            return 0;
        }

        return count($this->rows);
    }

    public function columnCount(?\Qt\Core\QModelIndex $parent = null): int
    {
        if ($parent !== null && $parent->isValid()) {
            return 0;
        }

        return 2;
    }

    public function data(\Qt\Core\QModelIndex $index, int $role = 0): \Qt\Core\QVariant
    {
        if (!$index->isValid() || $role !== 0) {
            return new \Qt\Core\QVariant();
        }

        return new \Qt\Core\QVariant($this->rows[$index->row()][$index->column()] ?? '');
    }

    public function parentModelIndex(\Qt\Core\QModelIndex $child): \Qt\Core\QModelIndex
    {
        return new \Qt\Core\QModelIndex();
    }

    /**
     * @param list<array{0:string,1:string}> $rows
     */
    public function appendRows(array $rows): void
    {
        $first = count($this->rows);
        $last = $first + count($rows) - 1;
        $this->beginInsertRows(new \Qt\Core\QModelIndex(), $first, $last);
        array_push($this->rows, ...$rows);
        $this->endInsertRows();
    }
}

final class RuntimeListModel extends \Qt\Core\QAbstractListModel
{
    /** @var list<string> */
    private array $items = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function rowCount(?\Qt\Core\QModelIndex $parent = null): int
    {
        if ($parent !== null && $parent->isValid()) {
            return 0;
        }

        return count($this->items);
    }

    public function columnCount(?\Qt\Core\QModelIndex $parent = null): int
    {
        if ($parent !== null && $parent->isValid()) {
            return 0;
        }

        return 1;
    }

    public function data(\Qt\Core\QModelIndex $index, int $role = 0): \Qt\Core\QVariant
    {
        if (!$index->isValid() || $role !== 0) {
            return new \Qt\Core\QVariant();
        }

        return new \Qt\Core\QVariant($this->items[$index->row()] ?? '');
    }

    public function parentModelIndex(\Qt\Core\QModelIndex $child): \Qt\Core\QModelIndex
    {
        return new \Qt\Core\QModelIndex();
    }

    /**
     * @param list<string> $items
     */
    public function appendItems(array $items): void
    {
        $first = count($this->items);
        $last = $first + count($items) - 1;
        $this->beginInsertRows(new \Qt\Core\QModelIndex(), $first, $last);
        array_push($this->items, ...$items);
        $this->endInsertRows();
    }
}

$table = new RuntimeTableModel();
$table->appendRows([
    ['SO-001', 'EMEA'],
    ['SO-002', 'APAC'],
    ['SO-003', 'AMER'],
]);

$list = new RuntimeListModel();
$list->appendItems([
    'Invoice paid',
    'Refund requested',
    'Order synced',
]);

$tableCell = $table->data($table->index(1, 0))->toString();
$listItem = $list->data($list->index(2, 0))->toString();

qt_runtime_result([
    'table_rows' => $table->rowCount(),
    'table_columns' => $table->columnCount(),
    'table_cell' => $tableCell,
    'list_rows' => $list->rowCount(),
    'list_item' => $listItem,
]);
