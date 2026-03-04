<?php

declare(strict_types=1);

namespace Examples\Support\Models;

use Qt\Core\QAbstractTableModel;
use Qt\Core\QModelIndex;
use Qt\Core\QObject;
use Qt\Core\QVariant;

final class CsvTableModel extends QAbstractTableModel
{
    /** @var list<string> */
    private array $headers = [];

    /** @var list<list<string>> */
    private array $rows = [];

    public function __construct(?QObject $parent = null)
    {
        parent::__construct($parent);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function replaceData(array $headers, array $rows): void
    {
        $this->beginResetModel();
        $this->headers = array_values($headers);
        $this->rows = array_values($rows);
        $this->endResetModel();
    }

    public function rowCount(?QModelIndex $parent = null): int
    {
        if ($parent !== null && $parent->isValid()) {
            return 0;
        }

        return count($this->rows);
    }

    public function columnCount(?QModelIndex $parent = null): int
    {
        if ($parent !== null && $parent->isValid()) {
            return 0;
        }

        return count($this->headers);
    }

    public function data(QModelIndex $index, int $role = 0): QVariant
    {
        if (!$index->isValid() || $role !== 0) {
            return new QVariant();
        }

        return new QVariant($this->rows[$index->row()][$index->column()] ?? '');
    }

    public function headerData(int $section, int $orientation, int $role = 0): QVariant
    {
        if ($role !== 0) {
            return new QVariant();
        }

        if ($orientation === 1) {
            return new QVariant($this->headers[$section] ?? '');
        }

        return new QVariant((string) ($section + 1));
    }

    public function parentModelIndex(QModelIndex $child): QModelIndex
    {
        return new QModelIndex();
    }
}
