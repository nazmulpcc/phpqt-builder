<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Examples\Support\AppPaths;
use Examples\Support\Theme\WidgetTheme;
use Examples\Support\Widgets\AppWindow;
use Examples\Support\Widgets\Banner;
use Examples\Support\Widgets\SearchBar;
use Examples\Support\Widgets\StatusBarMessage;
use Qt\Core\QModelIndex;
use Qt\Core\QObject;
use Qt\Core\QSortFilterProxyModel;
use Qt\Core\QTimerEvent;
use Qt\Core\QVariant;
use Qt\Gui\QStandardItem;
use Qt\Gui\QStandardItemModel;
use Qt\Widgets\QApplication;
use Qt\Widgets\QComboBox;
use Qt\Widgets\QFileDialog;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QHeaderView;
use Qt\Widgets\QLabel;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QTableView;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class LogStandardItemModel extends QStandardItemModel
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

final class LogProxyModel extends QSortFilterProxyModel
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

final class LogViewerController
{
    private string $path = '';
    private int $offset = 0;
    private int $lastSize = 0;
    private string $pending = '';

    public function __construct(
        private readonly QStandardItemModel $model,
        private readonly \Qt\Core\QFileSystemWatcher $watcher,
    ) {
    }

    public function setPath(string $path): void
    {
        $path = trim($path);
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Log file does not exist: ' . $path);
        }

        if ($this->path !== '' && $this->path !== $path) {
            $this->watcher->removePath($this->path);
        }

        $this->path = $path;
        $this->offset = 0;
        $this->lastSize = 0;
        $this->pending = '';

        $this->model->removeRows(0, $this->model->rowCount());
        $this->watcher->addPath($this->path);
        $this->ingestAppended();
    }

    public function path(): string
    {
        return $this->path;
    }

    public function ingestAppended(): int
    {
        if ($this->path === '' || !is_file($this->path)) {
            return 0;
        }

        $size = filesize($this->path);
        if (!is_int($size)) {
            return 0;
        }

        if ($size < $this->offset) {
            $this->offset = 0;
            $this->pending = '';
        }

        $newBytes = $size - $this->offset;
        if ($newBytes <= 0) {
            $this->lastSize = $size;
            return 0;
        }

        $chunk = file_get_contents($this->path, false, null, $this->offset, $newBytes);
        if (!is_string($chunk) || $chunk === '') {
            $this->lastSize = $size;
            return 0;
        }

        $this->offset += strlen($chunk);
        $this->lastSize = $size;

        $buffer = $this->pending . $chunk;
        $lines = preg_split('/\r\n|\n|\r/', $buffer) ?: [];

        if (!str_ends_with($buffer, "\n") && !str_ends_with($buffer, "\r")) {
            $this->pending = (string) array_pop($lines);
        } else {
            $this->pending = '';
        }

        $added = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $this->appendLine($line);
            $added++;
        }

        return $added;
    }

    public function rowCount(): int
    {
        return $this->model->rowCount();
    }

    public function enforceMaxRows(int $limit): void
    {
        $overflow = $this->model->rowCount() - max(1, $limit);
        if ($overflow > 0) {
            $this->model->removeRows(0, $overflow);
        }
    }

    private function appendLine(string $raw): void
    {
        $timestamp = \Qt\Core\QDateTime::currentDateTime()->toString('yyyy-MM-dd HH:mm:ss');
        $level = 'INFO';
        $message = trim($raw);

        if (preg_match('/^\[(.+?)\]\s+\[([A-Za-z]+)\]\s+(.*)$/', $raw, $match) === 1) {
            $timestamp = trim((string) $match[1]);
            $level = strtoupper(trim((string) $match[2]));
        $message = trim((string) $match[3]);
    }

        $source = basename($this->path);
        $searchText = $message . ' [' . $source . ']';
        $row = $this->model->rowCount();
        $this->model->setItem($row, 0, new QStandardItem($timestamp));
        $this->model->setItem($row, 1, new QStandardItem($level));
        $this->model->setItem($row, 2, new QStandardItem($searchText));
        $this->model->setItem($row, 3, new QStandardItem($source));
    }
}

final class LogTickDriver extends QObject
{
    private int $pollTimerId = 0;
    private int $simTimerId = 0;

    private bool $paused = false;

    /** @var callable():void */
    private $pollCallback;

    /** @var callable():void */
    private $simCallback;

    public function __construct(callable $pollCallback, callable $simCallback)
    {
        parent::__construct();
        $this->pollCallback = $pollCallback;
        $this->simCallback = $simCallback;
    }

    public function start(): void
    {
        if ($this->pollTimerId <= 0) {
            $this->pollTimerId = $this->startTimer(500);
        }

        if ($this->simTimerId <= 0) {
            $this->simTimerId = $this->startTimer(1200);
        }
    }

    public function paused(): bool
    {
        return $this->paused;
    }

    public function setPaused(bool $paused): void
    {
        $this->paused = $paused;
    }

    protected function timerEvent(QTimerEvent $event): void
    {
        $id = $event->timerId();

        if ($id === $this->pollTimerId) {
            if (!$this->paused) {
                ($this->pollCallback)();
            }

            return;
        }

        if ($id === $this->simTimerId) {
            ($this->simCallback)();
        }
    }
}

example_section('Log Viewer');

$paths = AppPaths::fromExampleRoot(__DIR__);
$app = new QApplication();
$window = new QWidget();
$window->resize(1220, 760);
$window->setWindowTitle('Log Viewer');
WidgetTheme::apply($window, 'dark');

$runtimeLogPath = $paths->runtimeFile('live.log');
copy($paths->dataFile('sample.log'), $runtimeLogPath);

$model = new LogStandardItemModel($window);
$model->setHorizontalHeaderLabels(['Time', 'Level', 'Message', 'Source']);
$severityProxy = new LogProxyModel($window);
$severityProxy->setSourceModel($model);
$severityProxy->setFilterKeyColumn(1);

$keywordProxy = new LogProxyModel($window);
$keywordProxy->setSourceModel($severityProxy);
$keywordProxy->setFilterKeyColumn(2);

$watcher = new \Qt\Core\QFileSystemWatcher($window);
$controller = new LogViewerController($model, $watcher);
$controller->setPath($runtimeLogPath);

$shell = new AppWindow('Log Viewer', 'Tail-like log stream with severity filters, pause/resume polling, and keyword search.');
$banner = new Banner();
$status = new StatusBarMessage();
$search = new SearchBar('Search message or source');

$table = new QTableView();
$table->setModel($keywordProxy);
$table->setAlternatingRowColors(true);
$table->setSortingEnabled(false);
$table->horizontalHeader()->setStretchLastSection(true);
$table->horizontalHeader()->setSectionResizeMode(2, QHeaderView::Stretch);

$severity = new QComboBox();
foreach (['ALL', 'DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'] as $value) {
    $severity->addItem($value);
}

$openLog = new QPushButton('Open Log');
$openLog->setProperty('variant', 'secondary');
$pause = new QPushButton('Pause');
$clear = new QPushButton('Clear');
$clear->setProperty('variant', 'secondary');

$toolbar = new QHBoxLayout();
$toolbar->addWidget($openLog);
$toolbar->addWidget(new QLabel('Severity'));
$toolbar->addWidget($severity);
$toolbar->addWidget($pause);
$toolbar->addWidget($clear);
$toolbar->addStretch(1);
$pathLabel = new QLabel('');
$pathLabel->setProperty('role', 'caption');
$toolbar->addWidget($pathLabel);

$shell->bodyLayout()->addWidget($banner);
$shell->bodyLayout()->addLayout($toolbar);
$shell->bodyLayout()->addWidget($search);
$shell->bodyLayout()->addWidget($table);
$shell->bodyLayout()->addWidget($status);

$root = new QVBoxLayout();
$root->setContentsMargins(20, 20, 20, 20);
$root->addWidget($shell);
$window->setLayout($root);

$maxRows = 5000;
$simCounter = 0;

$refresh = static function () use ($controller, $status, $watcher, $pathLabel, $window, $pause): void {
    $path = $controller->path();
    $base = basename($path);
    $watchCount = count($watcher->files());
    $state = $pause->text() === 'Resume' ? 'paused' : 'running';

    $pathLabel->setText(sprintf('%s  • watcher files: %d', $path, $watchCount));
    $status->info(sprintf('Rows: %d • poll: 500ms • state: %s • file: %s', $controller->rowCount(), $state, $base));
    $window->setWindowTitle('Log Viewer | ' . $base);
};

$driver = new LogTickDriver(
    static function () use ($controller, $refresh, $maxRows): void {
        $added = $controller->ingestAppended();
        if ($added > 0) {
            $controller->enforceMaxRows($maxRows);
        }

        $refresh();
    },
    static function () use (&$simCounter, $runtimeLogPath): void {
        $simCounter++;
        $levels = ['INFO', 'WARN', 'ERROR', 'DEBUG'];
        $level = $levels[$simCounter % count($levels)];
        $message = match ($level) {
            'WARN' => 'queue depth high, throttling worker',
            'ERROR' => 'upstream timeout during checkpoint write',
            'DEBUG' => 'poll heartbeat from simulator',
            default => 'background sync completed',
        };

        $line = sprintf(
            "[%s] [%s] %s\n",
            \Qt\Core\QDateTime::currentDateTime()->toString('yyyy-MM-dd HH:mm:ss'),
            $level,
            $message,
        );
        file_put_contents($runtimeLogPath, $line, FILE_APPEND);
    },
);

$severity->onCurrentTextChanged(static function (string $value) use ($severityProxy, $refresh): void {
    $severityProxy->setFilterFixedString($value === 'ALL' ? '' : $value);
    $refresh();
});

$search->input()->onTextChanged(static function (string $value) use ($keywordProxy, $refresh): void {
    $keywordProxy->setFilterFixedString($value);
    $refresh();
});

$openLog->onClicked(static function () use ($controller, $banner, $refresh, $window): void {
    if (!method_exists(QFileDialog::class, 'getOpenFileName')) {
        $banner->showError('File dialog is unavailable in this build.');
        return;
    }

    $path = QFileDialog::getOpenFileName($window, 'Open Log File', getcwd(), 'Log Files (*.log *.txt);;All Files (*)');
    if (!is_string($path) || $path === '') {
        return;
    }

    if (!is_file($path)) {
        $banner->showError('Selected file does not exist.');
        return;
    }

    try {
        $controller->setPath($path);
    } catch (Throwable $e) {
        $banner->showError($e->getMessage());
        return;
    }

    $banner->showInfo('Watching ' . basename($path));
    $refresh();
});

$pause->onClicked(static function () use ($pause, $driver, $banner, $refresh): void {
    $paused = $pause->text() !== 'Resume';
    $driver->setPaused($paused);
    $pause->setText($paused ? 'Resume' : 'Pause');
    $banner->showInfo($paused ? 'Auto-refresh paused.' : 'Auto-refresh resumed.');
    $refresh();
});

$clear->onClicked(static function () use ($model, $banner, $refresh): void {
    $count = $model->rowCount();
    if ($count > 0) {
        $model->removeRows(0, $count);
    }

    $banner->showInfo('Cleared visible log rows.');
    $refresh();
});

$driver->start();
$refresh();
$window->show();

example_line('log viewer ready; live.log is auto-appended by simulator');
QApplication::exec();
