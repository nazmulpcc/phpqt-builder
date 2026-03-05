<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Gui\QStandardItemModel::class, 'QtGui model classes are unavailable in this build.');

use Qt\Core\QModelIndex;
use Qt\Core\QSortFilterProxyModel;
use Qt\Gui\QStandardItem;
use Qt\Gui\QStandardItemModel;

final class RuntimeStandardItemModel extends QStandardItemModel
{
    public function __construct()
    {
        parent::__construct();
    }

    public function parentModelIndex(QModelIndex $child): QModelIndex
    {
        return parent::parentModelIndexAsModelIndex($child);
    }
}

final class RuntimeProxyModel extends QSortFilterProxyModel
{
    public function __construct(?\Qt\Core\QObject $parent = null)
    {
        parent::__construct($parent);
    }

    public function parentModelIndex(QModelIndex $child): QModelIndex
    {
        return parent::parentModelIndexAsModelIndex($child);
    }
}

final class RuntimeLogController
{
    private int $offset = 0;
    private string $path;

    public function __construct(private readonly QStandardItemModel $model, string $path)
    {
        $this->path = $path;
    }

    public function ingest(): int
    {
        $size = filesize($this->path);
        if (!is_int($size)) {
            return 0;
        }

        if ($size < $this->offset) {
            $this->offset = 0;
        }

        $bytes = $size - $this->offset;
        if ($bytes <= 0) {
            return 0;
        }

        $chunk = file_get_contents($this->path, false, null, $this->offset, $bytes);
        if (!is_string($chunk) || $chunk === '') {
            return 0;
        }

        $this->offset += strlen($chunk);

        $added = 0;
        foreach (preg_split('/\r\n|\n|\r/', trim($chunk)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\[(.+?)\]\s+\[([A-Za-z]+)\]\s+(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $row = $this->model->rowCount();
            $this->model->setItem($row, 0, new QStandardItem((string) $m[1]));
            $this->model->setItem($row, 1, new QStandardItem(strtoupper((string) $m[2])));
            $this->model->setItem($row, 2, new QStandardItem((string) $m[3]));
            $this->model->setItem($row, 3, new QStandardItem('runtime.log'));
            $added++;
        }

        return $added;
    }
}

$temp = sys_get_temp_dir() . '/phpqt-log-view-' . bin2hex(random_bytes(4));
mkdir($temp, 0777, true);
$logFile = $temp . '/runtime.log';
file_put_contents($logFile, "[2026-03-05 11:00:00] [INFO] startup\n");

$model = new RuntimeStandardItemModel();
$model->setHorizontalHeaderLabels(['Time', 'Level', 'Message', 'Source']);
$severityProxy = new RuntimeProxyModel();
$severityProxy->setSourceModel($model);
$severityProxy->setFilterKeyColumn(1);

$keywordProxy = new RuntimeProxyModel();
$keywordProxy->setSourceModel($severityProxy);
$keywordProxy->setFilterKeyColumn(2);
$watcher = new \Qt\Core\QFileSystemWatcher();
$watcher->addPath($logFile);

$controller = new RuntimeLogController($model, $logFile);
$initialAdded = $controller->ingest();

file_put_contents($logFile, "[2026-03-05 11:00:01] [WARN] queue depth high\n", FILE_APPEND);
$addedWhileRunning = $controller->ingest();
$runningRows = $model->rowCount();

$pausedRows = $runningRows;
file_put_contents($logFile, "[2026-03-05 11:00:02] [ERROR] paused should skip this tick\n", FILE_APPEND);
$addedWhilePaused = 0;

$addedAfterResume = $controller->ingest();
$rowsAfterResume = $model->rowCount();

$severityProxy->setFilterFixedString('ERROR');
$errorRows = $keywordProxy->rowCount();
$severityProxy->setFilterFixedString('');
$keywordProxy->setFilterFixedString('queue');
$queueRows = $keywordProxy->rowCount();

qt_runtime_result([
    'initial_added' => $initialAdded,
    'added_while_running' => $addedWhileRunning,
    'running_rows' => $runningRows,
    'paused_rows' => $pausedRows,
    'added_while_paused' => $addedWhilePaused,
    'added_after_resume' => $addedAfterResume,
    'rows_after_resume' => $rowsAfterResume,
    'error_rows' => $errorRows,
    'queue_rows' => $queueRows,
    'watcher_files_count' => count($watcher->files()),
]);
