<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Widgets\QApplication::class, 'QtWidgets classes are unavailable in this build.');

use Qt\Core\QModelIndex;
use Qt\Core\QObject;
use Qt\Core\QSortFilterProxyModel;
use Qt\Core\QTimerEvent;

final class RuntimeFilesystemModel extends \Qt\Gui\QFileSystemModel
{
    public function __construct()
    {
        parent::__construct();
    }

    public function parentModelIndex(QModelIndex $child): QModelIndex
    {
        return parent::parentModelIndexAsModelIndex($child);
    }

    public function index(int $row, int $column, ?QModelIndex $parent = null): QModelIndex
    {
        return parent::indexIntOrStringIntModelIndex($row, $column, $parent);
    }
}

final class RuntimeProxyModel extends QSortFilterProxyModel
{
    public function __construct(?QObject $parent = null)
    {
        parent::__construct($parent);
    }

    public function parentModelIndex(QModelIndex $child): QModelIndex
    {
        return parent::parentModelIndexAsModelIndex($child);
    }
}

final class RuntimeQuitDriver extends QObject
{
    public int $ticks = 0;
    public string $loadedPath = '';
    private int $timerId = 0;

    public function __construct()
    {
        parent::__construct();
        $this->timerId = $this->startTimer(10);
    }

    protected function timerEvent(QTimerEvent $event): void
    {
        if ($event->timerId() !== $this->timerId) {
            return;
        }

        $this->ticks++;
        if ($this->loadedPath !== '' || $this->ticks >= 40) {
            $this->killTimer($this->timerId);
            \Qt\Widgets\QApplication::quit();
        }
    }
}

/**
 * @return array{attempted:int,renamed:int,unchanged:int,conflicts:int,failed:int}
 */
function runtime_apply_rename(array $paths, string $find, string $replace, string $prefix, string $suffix): array
{
    $stats = ['attempted' => 0, 'renamed' => 0, 'unchanged' => 0, 'conflicts' => 0, 'failed' => 0];

    foreach ($paths as $path) {
        if (!is_string($path) || !is_file($path)) {
            continue;
        }

        $stats['attempted']++;

        $info = pathinfo($path);
        $dir = (string) ($info['dirname'] ?? '');
        $stem = (string) ($info['filename'] ?? '');
        $ext = (string) ($info['extension'] ?? '');

        $newStem = $prefix . str_replace($find, $replace, $stem) . $suffix;
        if ($newStem === '' || $newStem === $stem) {
            $stats['unchanged']++;
            continue;
        }

        $target = $dir . '/' . $newStem . ($ext !== '' ? '.' . $ext : '');
        if ($target === $path) {
            $stats['unchanged']++;
            continue;
        }

        if (file_exists($target)) {
            $stats['conflicts']++;
            continue;
        }

        if (@rename($path, $target)) {
            $stats['renamed']++;
        } else {
            $stats['failed']++;
        }
    }

    return $stats;
}

$tempRoot = sys_get_temp_dir() . '/phpqt-org-' . bin2hex(random_bytes(4));
mkdir($tempRoot, 0777, true);
mkdir($tempRoot . '/docs', 0777, true);
mkdir($tempRoot . '/reports', 0777, true);
file_put_contents($tempRoot . '/docs/readme.txt', "hello\nworld\n");
file_put_contents($tempRoot . '/reports/sales.csv', "region,amount\nemea,1000\n");
file_put_contents($tempRoot . '/reports/app.log', "line1\nline2\n");
file_put_contents($tempRoot . '/reports/graph.bin', random_bytes(16));

$app = new \Qt\Widgets\QApplication();
$driver = new RuntimeQuitDriver();
$model = new RuntimeFilesystemModel();
$model->onDirectoryLoaded(function (string $path) use ($driver): void {
    $driver->loadedPath = $path;
});

$rootIndex = $model->setRootPath($tempRoot);
\Qt\Widgets\QApplication::exec();

$proxy = new RuntimeProxyModel();
$proxy->setSourceModel($model);
$proxy->setRecursiveFilteringEnabled(true);
$proxy->setFilterKeyColumn(0);

$rowsBeforeFilter = $proxy->rowCount($proxy->mapFromSource($rootIndex));
$proxy->setFilterFixedString('report');
$rowsAfterFilter = $proxy->rowCount($proxy->mapFromSource($rootIndex));

$favoritesPath = $tempRoot . '/favorites.json';
$favorites = [$tempRoot, $tempRoot . '/reports'];
file_put_contents($favoritesPath, json_encode($favorites, JSON_THROW_ON_ERROR));
$loadedFavorites = json_decode((string) file_get_contents($favoritesPath), true, 512, JSON_THROW_ON_ERROR);

$selectedStats = runtime_apply_rename([
    $tempRoot . '/reports/sales.csv',
], 'sales', 'orders', '', '_2026');

$folderCandidates = [];
foreach (scandir($tempRoot . '/reports') ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $path = $tempRoot . '/reports/' . $entry;
    if (is_file($path) && str_contains(strtolower($entry), 'app')) {
        $folderCandidates[] = $path;
    }
}
$folderStats = runtime_apply_rename($folderCandidates, 'app', 'service', 'archived_', '');

$previewPath = $tempRoot . '/docs/readme.txt';
$preview = file_get_contents($previewPath, false, null, 0, 65536);
$previewLines = preg_split('/\r\n|\n|\r/', (string) $preview) ?: [];

qt_runtime_result([
    'loaded_path' => $driver->loadedPath,
    'root_index_valid' => $rootIndex->isValid(),
    'rows_before_filter' => $rowsBeforeFilter,
    'rows_after_filter' => $rowsAfterFilter,
    'favorites_count' => count($loadedFavorites),
    'selected_renamed' => $selectedStats['renamed'],
    'folder_renamed' => $folderStats['renamed'],
    'renamed_orders_exists' => is_file($tempRoot . '/reports/orders_2026.csv'),
    'renamed_service_exists' => is_file($tempRoot . '/reports/archived_service.log'),
    'preview_first_line' => (string) ($previewLines[0] ?? ''),
    'preview_has_world' => in_array('world', $previewLines, true),
]);
