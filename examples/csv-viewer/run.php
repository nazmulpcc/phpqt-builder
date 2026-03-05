<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Examples\Support\AppPaths;
use Examples\Support\Models\CsvTableModel;
use Examples\Support\Theme\WidgetTheme;
use Examples\Support\Widgets\AppWindow;
use Examples\Support\Widgets\Banner;
use Examples\Support\Widgets\PaginationBar;
use Examples\Support\Widgets\SearchBar;
use Examples\Support\Widgets\StatusBarMessage;
use Qt\Widgets\QApplication;
use Qt\Widgets\QFileDialog;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QTableView;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class CsvViewerController
{
    /** @var list<string> */
    private array $headers = [];
    /** @var list<list<string>> */
    private array $rows = [];
    /** @var list<list<string>> */
    private array $filteredRows = [];
    private int $page = 1;
    private int $pageSize = 25;
    private string $path = '';

    public function loadCsv(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV: ' . $path);
        }

        $headers = fgetcsv($handle, escape: '\\');
        $rows = [];
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $rows[] = array_map(static fn ($value): string => (string) $value, $row);
        }
        fclose($handle);

        $this->headers = array_map(static fn ($value): string => (string) $value, $headers ?: []);
        $this->rows = $rows;
        $this->filteredRows = $rows;
        $this->path = $path;
        $this->page = 1;
    }

    public function applyFilter(string $query): void
    {
        $query = strtolower(trim($query));
        if ($query === '') {
            $this->filteredRows = $this->rows;
            $this->page = 1;
            return;
        }

        $this->filteredRows = array_values(array_filter(
            $this->rows,
            static function (array $row) use ($query): bool {
                foreach ($row as $cell) {
                    if (str_contains(strtolower($cell), $query)) {
                        return true;
                    }
                }
                return false;
            }
        ));
        $this->page = 1;
    }

    public function setPageSize(int $size): void
    {
        $this->pageSize = max(1, $size);
        $this->page = 1;
    }

    public function nextPage(): void
    {
        $this->page = min($this->page + 1, $this->totalPages());
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return list<list<string>>
     */
    public function currentRows(): array
    {
        $offset = ($this->page - 1) * $this->pageSize;
        return array_slice($this->filteredRows, $offset, $this->pageSize);
    }

    public function totalRows(): int
    {
        return count($this->rows);
    }

    public function filteredCount(): int
    {
        return count($this->filteredRows);
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil(max(1, $this->filteredCount()) / $this->pageSize));
    }

    public function currentPage(): int
    {
        return $this->page;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exportFiltered(string $outputPath): void
    {
        $handle = fopen($outputPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to write export: ' . $outputPath);
        }

        fputcsv($handle, $this->headers);
        foreach ($this->filteredRows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }
}

example_section('CSV Viewer');

$paths = AppPaths::fromExampleRoot(__DIR__);
$controller = new CsvViewerController();
$controller->loadCsv($paths->dataFile('sample-sales.csv'));

$app = new QApplication();
$window = new QWidget();
$window->resize(1180, 720);
$window->setWindowTitle('CSV Viewer');
WidgetTheme::apply($window, 'dark');

$model = new CsvTableModel($window);
$shell = new AppWindow('CSV Viewer', 'Open a CSV file, filter rows, paginate large datasets, and export the filtered slice.');
$banner = new Banner();
$status = new StatusBarMessage();
$search = new SearchBar('Filter by any column');
$table = new QTableView();
$table->setAlternatingRowColors(true);
$table->setModel($model);
$pagination = new PaginationBar();

$loadSample = new QPushButton('Reload sample');
$loadSample->setProperty('variant', 'secondary');
$browse = new QPushButton('Open CSV');
$browse->setProperty('variant', 'secondary');
$export = new QPushButton('Export filtered');

$toolbar = new QHBoxLayout();
$toolbar->addWidget($loadSample);
$toolbar->addWidget($browse);
$toolbar->addWidget($export);
$toolbar->addStretch(1);
$toolbar->addWidget(new QLabel('Current file'));
$pathLabel = new QLabel('');
$pathLabel->setProperty('role', 'caption');
$toolbar->addWidget($pathLabel);

$shell->bodyLayout()->addWidget($banner);
$shell->bodyLayout()->addLayout($toolbar);
$shell->bodyLayout()->addWidget($search);
$shell->bodyLayout()->addWidget($table);
$shell->bodyLayout()->addWidget($pagination);
$shell->bodyLayout()->addWidget($status);

$root = new QVBoxLayout();
$root->setContentsMargins(20, 20, 20, 20);
$root->addWidget($shell);
$window->setLayout($root);

$refresh = static function () use ($controller, $model, $pagination, $status, $window, $pathLabel): void {
    $model->replaceData($controller->headers(), $controller->currentRows());
    $pagination->summary()->setText(sprintf(
        '%d rows total • %d filtered • page %d / %d',
        $controller->totalRows(),
        $controller->filteredCount(),
        $controller->currentPage(),
        $controller->totalPages(),
    ));
    $pathLabel->setText(basename($controller->path()));
    $status->info(sprintf(
        'Showing %d rows per page from %s.',
        $controller->pageSize(),
        basename($controller->path()),
    ));
    $window->setWindowTitle('CSV Viewer | ' . basename($controller->path()));
};

$pagination->pageSizeBox()->setCurrentText((string) $controller->pageSize());
$pagination->pageSizeBox()->connect('currentIndexChanged(int)', static function () use ($controller, $pagination, $refresh): void {
    $controller->setPageSize((int) $pagination->pageSizeBox()->currentText());
    $refresh();
});

$pagination->previousButton()->onClicked(static function () use ($controller, $refresh): void {
    $controller->previousPage();
    $refresh();
});

$pagination->nextButton()->onClicked(static function () use ($controller, $refresh): void {
    $controller->nextPage();
    $refresh();
});

$search->input()->connect('textChanged(QString)', static function (string $query) use ($controller, $refresh): void {
    $controller->applyFilter($query);
    $refresh();
});

$loadSample->onClicked(static function () use ($controller, $paths, $search, $banner, $refresh): void {
    $controller->loadCsv($paths->dataFile('sample-sales.csv'));
    $search->input()->setText('');
    $banner->showInfo('Reloaded the sample sales file.');
    $refresh();
});

$browse->onClicked(static function () use ($controller, $search, $banner, $refresh, $window): void {
    if (method_exists(QFileDialog::class, 'getOpenFileName')) {
        $path = QFileDialog::getOpenFileName($window, 'Open CSV', getcwd(), 'CSV Files (*.csv)');
        if (is_string($path) && $path !== '' && is_file($path)) {
            $controller->loadCsv($path);
            $search->input()->setText('');
            $banner->showInfo('Loaded ' . basename($path));
            $refresh();
            return;
        }
    }

    $banner->showError('File picker is unavailable in this build. Use Reload sample for the bundled dataset.');
});

$export->onClicked(static function () use ($controller, $paths, $banner, $status): void {
    $target = $paths->exportFile('filtered-' . date('Ymd-His') . '.csv');
    $controller->exportFiltered($target);
    $banner->showInfo('Exported filtered rows to ' . basename($target));
    $status->info('Export saved to ' . $target);
});

$refresh();
$window->show();

example_line('csv viewer ready; sample-sales.csv loads by default');
QApplication::exec();
