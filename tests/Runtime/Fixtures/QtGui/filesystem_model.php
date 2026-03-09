<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Gui\QFileSystemModel::class, 'QFileSystemModel is unavailable in this build.');
qt_runtime_require_class(\Qt\Widgets\QApplication::class, 'QtWidgets QApplication is unavailable in this build.');

final class RuntimeFilesystemModel extends \Qt\Gui\QFileSystemModel
{
    public function __construct()
    {
        parent::__construct();
    }

    public function parentModelIndex(\Qt\Core\QModelIndex $child): \Qt\Core\QModelIndex
    {
        return parent::parentModelIndex($child);
    }

    public function index(\Qt\Core\QString|int|string $row, int $column = 0, ?\Qt\Core\QModelIndex $parent = null): \Qt\Core\QModelIndex
    {
        return parent::index($row, $column, $parent ?? new \Qt\Core\QModelIndex());
    }
}

final class RuntimeFilesystemDriver extends \Qt\Core\QObject
{
    public int $ticks = 0;
    public string $loadedPath = '';
    private int $timerId = 0;

    public function __construct()
    {
        parent::__construct();
        $this->timerId = $this->startTimer(10);
    }

    protected function timerEvent(\Qt\Core\QTimerEvent $event): void
    {
        if ($event->timerId() !== $this->timerId) {
            return;
        }

        $this->ticks++;
        if ($this->loadedPath !== '' || $this->ticks >= 30) {
            $this->killTimer($this->timerId);
            \Qt\Widgets\QApplication::quit();
        }
    }
}

$tempRoot = sys_get_temp_dir() . '/phpqt-fs-' . bin2hex(random_bytes(4));
mkdir($tempRoot, 0777, true);
file_put_contents($tempRoot . '/alpha.txt', 'alpha');
file_put_contents($tempRoot . '/beta.txt', 'beta');

$app = new \Qt\Widgets\QApplication();
$driver = new RuntimeFilesystemDriver();
$model = new RuntimeFilesystemModel();
$model->onDirectoryLoaded(function (string $path) use ($driver): void {
    $driver->loadedPath = $path;
});

$rootIndex = $model->setRootPath($tempRoot);
\Qt\Widgets\QApplication::exec();

qt_runtime_result([
    'loaded_path' => $driver->loadedPath,
    'row_count' => $model->rowCount($rootIndex),
    'root_index_valid' => $rootIndex->isValid(),
    'ticks' => $driver->ticks,
]);
