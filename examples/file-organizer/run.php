<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Examples\Support\AppPaths;
use Examples\Support\DemoStorage;
use Examples\Support\Theme\WidgetTheme;
use Examples\Support\Widgets\AppWindow;
use Examples\Support\Widgets\Banner;
use Examples\Support\Widgets\SearchBar;
use Examples\Support\Widgets\StatusBarMessage;
use Qt\Core\QModelIndex;
use Qt\Core\QString;
use Qt\Core\QSortFilterProxyModel;
use Qt\Core\QVariant;
use Qt\Widgets\QApplication;
use Qt\Widgets\QFileDialog;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QListWidget;
use Qt\Widgets\QListWidgetItem;
use Qt\Widgets\QPlainTextEdit;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QSplitter;
use Qt\Widgets\QTreeView;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class OrganizerFileSystemModel extends \Qt\Gui\QFileSystemModel
{
    public function __construct()
    {
        parent::__construct();
    }

    public function parentModelIndex(QModelIndex $child): QModelIndex
    {
        return parent::parentModelIndex($child);
    }

    public function index(QString|string|int $row, int $column = 0, ?QModelIndex $parent = null): QModelIndex
    {
        return parent::index($row, $column, $parent ?? new QModelIndex());
    }
}

final class OrganizerProxyModel extends QSortFilterProxyModel
{
    public function __construct(?\Qt\Core\QObject $parent = null)
    {
        parent::__construct($parent);
    }

    public function parentModelIndex(QModelIndex $child): QModelIndex
    {
        return parent::parentModelIndex($child);
    }
}

/**
 * @return list<string>
 */
function fo_collect_text_preview(string $path, int $maxBytes = 65536): array
{
    $data = @file_get_contents($path, false, null, 0, $maxBytes);
    if (!is_string($data)) {
        return [];
    }

    $lines = preg_split('/\r\n|\n|\r/', $data) ?: [];

    return array_slice($lines, 0, 200);
}

function fo_copy_dir(string $source, string $target): void
{
    if (!is_dir($source)) {
        return;
    }

    if (!is_dir($target)) {
        mkdir($target, 0777, true);
    }

    $items = scandir($source) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $src = $source . '/' . $item;
        $dst = $target . '/' . $item;

        if (is_dir($src)) {
            fo_copy_dir($src, $dst);
        } else {
            copy($src, $dst);
        }
    }
}

example_section('File Organizer');

$paths = AppPaths::fromExampleRoot(__DIR__);
$storage = new DemoStorage($paths);

$workspace = $paths->runtimeFile('workspace');
if (!is_dir($workspace) || (scandir($workspace) ?: []) === ['.', '..']) {
    fo_copy_dir($paths->dataFile('workspace-template'), $workspace);
}

/** @var list<string> $favorites */
$favorites = array_values(array_filter(
    $storage->loadList('favorites.json', [$workspace]),
    static fn (mixed $path): bool => is_string($path) && $path !== ''
));

if ($favorites === []) {
    $favorites = [$workspace];
}

$app = new QApplication();
$window = new QWidget();
$window->resize(1340, 820);
$window->setWindowTitle('File Organizer');
WidgetTheme::apply($window, 'dark');

$shell = new AppWindow('File Organizer', 'Browse folders, preview files, run safe bulk renames, and persist favorite locations.');
$banner = new Banner();
$status = new StatusBarMessage();
$search = new SearchBar('Filter file/folder names');

$sourceModel = new OrganizerFileSystemModel();
$sourceModel->setRootPath($workspace);
$proxy = new OrganizerProxyModel($window);
$proxy->setSourceModel($sourceModel);
$proxy->setRecursiveFilteringEnabled(true);
$proxy->setFilterKeyColumn(0);

$tree = new QTreeView();
$tree->setModel($proxy);
$tree->setRootIsDecorated(true);
$tree->setSortingEnabled(true);
$tree->sortByColumn(0, 0);

$favoritesList = new QListWidget();
$favoritesList->setMinimumWidth(250);
$favAdd = new QPushButton('Add Current');
$favAdd->setProperty('variant', 'secondary');
$favRemove = new QPushButton('Remove');
$favRemove->setProperty('variant', 'secondary');
$favOpen = new QPushButton('Open Folder');
$favOpen->setProperty('variant', 'secondary');

$leftPane = new QWidget();
$leftLayout = new QVBoxLayout();
$leftLayout->addWidget(new QLabel('Favorites'));
$leftLayout->addWidget($favoritesList);
$leftButtons = new QHBoxLayout();
$leftButtons->addWidget($favAdd);
$leftButtons->addWidget($favRemove);
$leftButtons->addWidget($favOpen);
$leftLayout->addLayout($leftButtons);
$leftPane->setLayout($leftLayout);

$metadataLabel = new QLabel('Select a file or folder to preview.');
$metadataLabel->setWordWrap(true);
$metadataLabel->setProperty('role', 'caption');
$previewText = new QPlainTextEdit();
$previewText->setReadOnly(true);
$previewText->setPlaceholderText('Text preview appears here for text-like files.');

$rightPane = new QWidget();
$rightLayout = new QVBoxLayout();
$rightLayout->addWidget(new QLabel('Preview'));
$rightLayout->addWidget($metadataLabel);
$rightLayout->addWidget($previewText);
$rightPane->setLayout($rightLayout);

$splitter = new QSplitter();
$splitter->addWidget($leftPane);
$splitter->addWidget($tree);
$splitter->addWidget($rightPane);
$splitter->setStretchFactor(1, 3);
$splitter->setStretchFactor(2, 2);

$renameMode = new \Qt\Widgets\QComboBox();
$renameMode->addItem('Selected Items');
$renameMode->addItem('Current Folder Filter');

$findInput = new \Qt\Widgets\QLineEdit();
$findInput->setPlaceholderText('Find text in file stem');
$replaceInput = new \Qt\Widgets\QLineEdit();
$replaceInput->setPlaceholderText('Replace with');
$prefixInput = new \Qt\Widgets\QLineEdit();
$prefixInput->setPlaceholderText('Prefix');
$suffixInput = new \Qt\Widgets\QLineEdit();
$suffixInput->setPlaceholderText('Suffix');
$renamePreview = new QPushButton('Preview Rename');
$renamePreview->setProperty('variant', 'secondary');
$renameApply = new QPushButton('Apply Rename');

$renameBar = new QHBoxLayout();
$renameBar->addWidget(new QLabel('Mode'));
$renameBar->addWidget($renameMode);
$renameBar->addWidget(new QLabel('Find'));
$renameBar->addWidget($findInput);
$renameBar->addWidget(new QLabel('Replace'));
$renameBar->addWidget($replaceInput);
$renameBar->addWidget(new QLabel('Prefix'));
$renameBar->addWidget($prefixInput);
$renameBar->addWidget(new QLabel('Suffix'));
$renameBar->addWidget($suffixInput);
$renameBar->addWidget($renamePreview);
$renameBar->addWidget($renameApply);

$shell->bodyLayout()->addWidget($banner);
$shell->bodyLayout()->addWidget($search);
$shell->bodyLayout()->addWidget($splitter);
$shell->bodyLayout()->addLayout($renameBar);
$shell->bodyLayout()->addWidget($status);

$root = new QVBoxLayout();
$root->setContentsMargins(20, 20, 20, 20);
$root->addWidget($shell);
$window->setLayout($root);

$currentRootPath = $workspace;
$activeSearch = '';

$setRootPath = static function (string $path) use (&$currentRootPath, $sourceModel, $proxy, $tree, $banner): void {
    $real = realpath($path);
    if (!is_string($real) || !is_dir($real)) {
        $banner->showError('Cannot open folder: ' . $path);
        return;
    }

    $currentRootPath = $real;
    $sourceIndex = $sourceModel->setRootPath($real);
    $tree->setRootIndex($proxy->mapFromSource($sourceIndex));
};

$persistFavorites = static function () use (&$favorites, $storage): void {
    $favorites = array_values(array_unique(array_filter($favorites, static fn (string $path): bool => $path !== '')));
    $favorites = array_slice($favorites, 0, 20);
    $storage->saveData('favorites.json', $favorites);
};

$refreshFavorites = static function () use (&$favorites, $favoritesList): void {
    $favoritesList->clear();
    foreach ($favorites as $path) {
        $item = new QListWidgetItem(basename($path) !== '' ? basename($path) : $path);
        $item->setData(0, new QVariant($path));
        $favoritesList->addItem($item);
    }
};

$updatePreview = static function (?QModelIndex $proxyIndex) use ($proxy, $sourceModel, $metadataLabel, $previewText): void {
    if ($proxyIndex === null || !$proxyIndex->isValid()) {
        $metadataLabel->setText('Select a file or folder to preview.');
        $previewText->setPlainText('');
        return;
    }

    $sourceIndex = $proxy->mapToSource($proxyIndex);
    $path = $sourceModel->filePath($sourceIndex);
    if (!is_string($path) || $path === '') {
        $metadataLabel->setText('Unable to resolve selected path.');
        $previewText->setPlainText('');
        return;
    }

    $exists = file_exists($path);
    $isDir = is_dir($path);
    $size = $exists && !$isDir ? (int) filesize($path) : 0;
    $mtime = $exists ? date('Y-m-d H:i:s', (int) filemtime($path)) : 'n/a';

    $metadataLabel->setText(sprintf(
        "Path: %s\nType: %s\nSize: %d bytes\nModified: %s\nReadable: %s\nWritable: %s",
        $path,
        $isDir ? 'directory' : 'file',
        $size,
        $mtime,
        is_readable($path) ? 'yes' : 'no',
        is_writable($path) ? 'yes' : 'no',
    ));

    if ($isDir) {
        $children = scandir($path) ?: [];
        $previewText->setPlainText('Directory with ' . max(0, count($children) - 2) . ' entries.');
        return;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $textLike = in_array($extension, ['txt', 'md', 'log', 'csv', 'json', 'xml', 'yml', 'yaml', 'ini', 'php'], true);
    if (!$textLike) {
        $previewText->setPlainText('Preview unavailable for this file type.');
        return;
    }

    $lines = fo_collect_text_preview($path);
    $previewText->setPlainText(implode(PHP_EOL, $lines));
};

$collectRenameTargets = static function () use (&$currentRootPath, &$activeSearch, $renameMode, $tree, $proxy, $sourceModel): array {
    $targets = [];

    if ($renameMode->currentText() === 'Selected Items') {
        $selection = $tree->selectionModel();
        if ($selection !== null) {
            foreach ($selection->selectedRows(0) as $proxyIndex) {
                if (!$proxyIndex instanceof QModelIndex || !$proxyIndex->isValid()) {
                    continue;
                }

                $sourceIndex = $proxy->mapToSource($proxyIndex);
                $path = $sourceModel->filePath($sourceIndex);
                if (is_string($path) && is_file($path)) {
                    $targets[] = $path;
                }
            }
        }
    } else {
        $entries = scandir($currentRootPath) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($activeSearch !== '' && !str_contains(strtolower($entry), strtolower($activeSearch))) {
                continue;
            }

            $path = $currentRootPath . '/' . $entry;
            if (is_file($path)) {
                $targets[] = $path;
            }
        }
    }

    return array_values(array_unique($targets));
};

$runRename = static function (bool $apply) use (
    $collectRenameTargets,
    $findInput,
    $replaceInput,
    $prefixInput,
    $suffixInput,
    $banner,
    $status,
    $setRootPath,
    &$currentRootPath,
    $tree,
    $updatePreview,
): void {
    $targets = $collectRenameTargets();
    if ($targets === []) {
        $banner->showError('No files available for rename in the selected mode.');
        return;
    }

    $find = $findInput->text();
    $replace = $replaceInput->text();
    $prefix = $prefixInput->text();
    $suffix = $suffixInput->text();

    $attempted = 0;
    $renamed = 0;
    $unchanged = 0;
    $conflicts = 0;
    $failed = 0;

    foreach ($targets as $path) {
        $attempted++;
        $info = pathinfo($path);
        $dir = (string) ($info['dirname'] ?? '');
        $stem = (string) ($info['filename'] ?? '');
        $ext = (string) ($info['extension'] ?? '');

        $newStem = $prefix . str_replace($find, $replace, $stem) . $suffix;
        if ($newStem === '' || $newStem === $stem) {
            $unchanged++;
            continue;
        }

        $newName = $newStem . ($ext !== '' ? '.' . $ext : '');
        $target = $dir . '/' . $newName;

        if ($target === $path) {
            $unchanged++;
            continue;
        }

        if (file_exists($target)) {
            $conflicts++;
            continue;
        }

        if (!$apply) {
            $renamed++;
            continue;
        }

        if (@rename($path, $target)) {
            $renamed++;
        } else {
            $failed++;
        }
    }

    if ($apply) {
        $setRootPath($currentRootPath);
        $updatePreview($tree->currentIndex());
    }

    $prefixText = $apply ? 'Rename applied.' : 'Preview only.';
    $message = sprintf(
        '%s attempted=%d renamed=%d unchanged=%d conflicts=%d failed=%d',
        $prefixText,
        $attempted,
        $renamed,
        $unchanged,
        $conflicts,
        $failed,
    );

    if ($failed > 0 || $conflicts > 0) {
        $banner->showError($message);
    } else {
        $banner->showInfo($message);
    }
    $status->info($message);
};

$search->input()->onTextChanged(static function (string $query) use (&$activeSearch, $proxy, $status): void {
    $activeSearch = $query;
    $proxy->setFilterFixedString($query);
    $status->info('Filter query: ' . ($query !== '' ? $query : '(none)'));
});

$tree->onClicked(static function (QModelIndex $index) use ($updatePreview): void {
    $updatePreview($index);
});

$favAdd->onClicked(static function () use (&$favorites, $currentRootPath, $persistFavorites, $refreshFavorites, $banner): void {
    if (!in_array($currentRootPath, $favorites, true)) {
        $favorites[] = $currentRootPath;
        $persistFavorites();
        $refreshFavorites();
        $banner->showInfo('Added favorite: ' . basename($currentRootPath));
    }
});

$favRemove->onClicked(static function () use (&$favorites, $favoritesList, $persistFavorites, $refreshFavorites, $banner): void {
    $item = $favoritesList->currentItem();
    if ($item === null) {
        return;
    }

    $path = $item->data(0)->toString();
    $favorites = array_values(array_filter($favorites, static fn (string $value): bool => $value !== $path));
    $persistFavorites();
    $refreshFavorites();
    $banner->showInfo('Removed favorite.');
});

$favoritesList->onItemDoubleClicked(static function (QListWidgetItem $item) use ($setRootPath, $banner, $status): void {
    $path = $item->data(0)->toString();
    $setRootPath($path);
    $banner->showInfo('Opened favorite: ' . basename($path));
    $status->info('Navigated to ' . $path);
});

$favOpen->onClicked(static function () use ($setRootPath, $banner, $status, $window): void {
    if (!method_exists(QFileDialog::class, 'getExistingDirectory')) {
        $banner->showError('Folder picker is unavailable in this build.');
        return;
    }

    $path = QFileDialog::getExistingDirectory($window, 'Open Folder', getcwd());
    if (!is_string($path) || $path === '') {
        return;
    }

    $setRootPath($path);
    $banner->showInfo('Opened folder: ' . basename($path));
    $status->info('Navigated to ' . $path);
});

$renamePreview->onClicked(static function () use ($runRename): void {
    $runRename(false);
});

$renameApply->onClicked(static function () use ($runRename): void {
    $runRename(true);
});

$setRootPath($workspace);
$refreshFavorites();
if ($favoritesList->count() > 0) {
    $favoritesList->setCurrentRow(0);
}
$status->info('Workspace loaded: ' . $workspace);
$window->show();

example_line('file organizer ready; workspace initialized in examples/file-organizer/runtime/workspace');
QApplication::exec();
