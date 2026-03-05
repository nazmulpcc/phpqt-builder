<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Examples\Support\AppPaths;
use Examples\Support\Markdown;
use Examples\Support\Theme\WidgetTheme;
use Examples\Support\Widgets\AppWindow;
use Examples\Support\Widgets\Banner;
use Examples\Support\Widgets\SearchBar;
use Examples\Support\Widgets\StatusBarMessage;
use Qt\Widgets\QApplication;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QInputDialog;
use Qt\Widgets\QLabel;
use Qt\Widgets\QLineEdit;
use Qt\Widgets\QListWidget;
use Qt\Widgets\QListWidgetItem;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QSplitter;
use Qt\Widgets\QTextBrowser;
use Qt\Widgets\QTextEdit;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class NotesController extends \Qt\Core\QObject
{
    private AppPaths $paths;
    private string $currentFile = '';
    private bool $dirty = false;
    private int $timerId = 0;
    private ?QLineEdit $titleInput = null;
    private ?QTextEdit $editor = null;
    private ?QTextBrowser $preview = null;
    private ?StatusBarMessage $status = null;
    private ?QWidget $window = null;

    public function __construct(AppPaths $paths)
    {
        parent::__construct();
        $this->paths = $paths;
        $this->timerId = $this->startTimer(5000);
    }

    public function bind(QLineEdit $titleInput, QTextEdit $editor, QTextBrowser $preview, StatusBarMessage $status, QWidget $window): void
    {
        $this->titleInput = $titleInput;
        $this->editor = $editor;
        $this->preview = $preview;
        $this->status = $status;
        $this->window = $window;
    }

    public function __destruct()
    {
        if ($this->timerId > 0) {
            $this->killTimer($this->timerId);
        }
    }

    /**
     * @return list<string>
     */
    public function noteFiles(): array
    {
        $files = glob($this->paths->dataDir() . '/notes/*.md') ?: [];
        sort($files);
        return $files;
    }

    public function load(string $file): void
    {
        if (!is_file($file) || $this->titleInput === null || $this->editor === null) {
            return;
        }

        $this->currentFile = $file;
        $contents = (string) file_get_contents($file);
        $lines = preg_split('/\R/', $contents) ?: [''];
        $title = preg_replace('/^#\s+/', '', (string) array_shift($lines));
        $body = ltrim(implode(PHP_EOL, $lines));

        $this->titleInput->setText($title !== '' ? $title : basename($file, '.md'));
        $this->editor->setPlainText($body);
        $this->dirty = false;
        $this->renderPreview();
        $this->refreshWindow();
        $this->status?->info('Loaded ' . basename($file));
    }

    public function markDirty(): void
    {
        $this->dirty = true;
        $this->renderPreview();
        $this->refreshWindow();
    }

    public function saveCurrent(): void
    {
        if ($this->currentFile === '' || $this->titleInput === null || $this->editor === null) {
            return;
        }

        $contents = '# ' . trim($this->titleInput->text()) . PHP_EOL . PHP_EOL . trim($this->editor->toPlainText()) . PHP_EOL;
        file_put_contents($this->currentFile, $contents);
        $this->dirty = false;
        $this->refreshWindow();
        $this->status?->info('Saved ' . basename($this->currentFile) . ' at ' . date('H:i:s'));
    }

    public function createNote(string $slug): string
    {
        $slug = trim($slug);
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? 'note');
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'note-' . date('His');
        }

        $file = $this->paths->dataDir() . '/notes/' . $slug . '.md';
        if (!is_file($file)) {
            file_put_contents($file, '# ' . ucwords(str_replace('-', ' ', $slug)) . PHP_EOL . PHP_EOL . 'Write here.' . PHP_EOL);
        }

        return $file;
    }

    public function deleteCurrent(): void
    {
        if ($this->currentFile !== '' && is_file($this->currentFile)) {
            unlink($this->currentFile);
            $this->currentFile = '';
            $this->status?->info('Deleted note.');
        }
    }

    protected function timerEvent(\Qt\Core\QTimerEvent $event): void
    {
        if ($event->timerId() !== $this->timerId || !$this->dirty) {
            return;
        }

        $this->saveCurrent();
    }

    private function renderPreview(): void
    {
        if ($this->preview === null || $this->titleInput === null || $this->editor === null) {
            return;
        }

        $markdown = '# ' . $this->titleInput->text() . PHP_EOL . PHP_EOL . $this->editor->toPlainText();
        $this->preview->setHtml(Markdown::toHtml($markdown));
    }

    private function refreshWindow(): void
    {
        if ($this->window === null || $this->titleInput === null) {
            return;
        }

        $this->window->setWindowTitle('Markdown Notes' . ($this->dirty ? ' • autosave pending' : '') . ' | ' . $this->titleInput->text());
    }
}

example_section('Markdown Notes');

$paths = AppPaths::fromExampleRoot(__DIR__);
$paths->dataFile('notes');
$app = new QApplication();
$window = new QWidget();
$window->resize(1180, 720);
$window->setWindowTitle('Markdown Notes');
WidgetTheme::apply($window, 'dark');

$controller = new NotesController($paths);

$shell = new AppWindow('Markdown Notes', 'Sidebar + editor + preview, backed by local markdown files with autosave.');
$banner = new Banner();
$status = new StatusBarMessage();
$splitter = new QSplitter();

$leftPane = new QWidget();
$leftLayout = new QVBoxLayout();
$search = new SearchBar('Filter note titles');
$list = new QListWidget();
$newButton = new QPushButton('New note');
$deleteButton = new QPushButton('Delete note');
$deleteButton->setProperty('variant', 'danger');
$leftLayout->addWidget($search);
$leftLayout->addWidget($list);
$leftLayout->addWidget($newButton);
$leftLayout->addWidget($deleteButton);
$leftPane->setLayout($leftLayout);

$centerPane = new QWidget();
$centerLayout = new QVBoxLayout();
$title = new QLineEdit();
$title->setPlaceholderText('Note title');
$editor = new QTextEdit();
$editor->setPlaceholderText("Write markdown here…");
$centerLayout->addWidget(new QLabel('Title'));
$centerLayout->addWidget($title);
$centerLayout->addWidget(new QLabel('Markdown'));
$centerLayout->addWidget($editor);
$centerPane->setLayout($centerLayout);

$rightPane = new QWidget();
$rightLayout = new QVBoxLayout();
$preview = new QTextBrowser();
$rightLayout->addWidget(new QLabel('Preview'));
$rightLayout->addWidget($preview);
$rightPane->setLayout($rightLayout);

$splitter->addWidget($leftPane);
$splitter->addWidget($centerPane);
$splitter->addWidget($rightPane);

$shell->bodyLayout()->addWidget($banner);
$shell->bodyLayout()->addWidget($splitter);
$shell->bodyLayout()->addWidget($status);

$root = new QVBoxLayout();
$root->setContentsMargins(20, 20, 20, 20);
$root->addWidget($shell);
$window->setLayout($root);

$controller->bind($title, $editor, $preview, $status, $window);

$reloadList = static function () use ($controller, $list, $search): void {
    $query = strtolower(trim($search->input()->text()));
    $list->clear();
    foreach ($controller->noteFiles() as $file) {
        $label = basename($file, '.md');
        if ($query !== '' && !str_contains(strtolower($label), $query)) {
            continue;
        }
        $item = new QListWidgetItem(str_replace('-', ' ', $label));
        $item->setData(0, new \Qt\Core\QVariant($file));
        $list->addItem($item);
    }
};

$reloadList();

$search->input()->connect('textChanged(QString)', $reloadList);
$title->connect('textChanged(QString)', [$controller, 'markDirty']);
$editor->connect('textChanged()', [$controller, 'markDirty']);

$list->connect('currentRowChanged(int)', static function (int $row) use ($list, $controller, $banner): void {
    $item = $list->item($row);
    if ($item === null) {
        return;
    }
    $banner->clear();
    $controller->load((string) $item->data(0));
});

$newButton->onClicked(static function () use ($controller, $reloadList, $list, $banner): void {
    $slug = 'note-' . date('His');
    $file = $controller->createNote($slug);
    $reloadList();
    $banner->showInfo('Created ' . basename($file));
    if ($list->count() > 0) {
        $list->setCurrentRow($list->count() - 1);
    }
});

$deleteButton->onClicked(static function () use ($controller, $reloadList, $list, $banner, $title, $editor, $preview): void {
    $controller->deleteCurrent();
    $reloadList();
    $title->setText('');
    $editor->setPlainText('');
    $preview->setHtml('');
    $banner->showInfo('Deleted the current note.');
    if ($list->count() > 0) {
        $list->setCurrentRow(0);
    }
});

if ($list->count() > 0) {
    $list->setCurrentRow(0);
}

$window->show();
example_line('markdown notes ready; edits autosave every 5 seconds when dirty');
QApplication::exec();
