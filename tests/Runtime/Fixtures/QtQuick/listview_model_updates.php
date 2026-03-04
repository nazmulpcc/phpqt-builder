<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Quick\QQuickWindow::class, 'QtQuick window classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QAbstractListModel::class, 'QtCore abstract list model classes are unavailable in this build.');

final class RuntimeQuickListModel extends \Qt\Core\QAbstractListModel
{
    /** @var list<string> */
    private array $rows = [];

    public function __construct()
    {
        parent::__construct();
        $this->appendRows(['#SO-0320  EMEA  $900', '#SO-0321  APAC  $935']);
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

        return 1;
    }

    public function data(\Qt\Core\QModelIndex $index, int $role = 0): \Qt\Core\QVariant
    {
        if (!$index->isValid() || $role !== 0) {
            return new \Qt\Core\QVariant();
        }

        return new \Qt\Core\QVariant($this->rows[$index->row()] ?? '');
    }

    public function parentModelIndex(\Qt\Core\QModelIndex $child): \Qt\Core\QModelIndex
    {
        return new \Qt\Core\QModelIndex();
    }

    /**
     * @param list<string> $rows
     */
    public function appendRows(array $rows): void
    {
        $first = count($this->rows);
        $last = $first + count($rows) - 1;
        $this->beginInsertRows(new \Qt\Core\QModelIndex(), $first, $last);
        array_push($this->rows, ...$rows);
        $this->endInsertRows();
    }

    public function latestEntry(): string
    {
        return $this->rows[array_key_last($this->rows)] ?? '';
    }
}

final class RuntimeQuickListDriver extends \Qt\Core\QObject
{
    private int $timerId = 0;
    private int $ticks = 0;

    public function __construct(private readonly RuntimeQuickListModel $model)
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
        if ($this->ticks === 1) {
            $this->model->appendRows(['#SO-0322  AMER  $970']);
            return;
        }

        $this->killTimer($this->timerId);
        \Qt\Core\QCoreApplication::quit();
    }
}

$app = new \Qt\Gui\QGuiApplication(0, []);
$model = new RuntimeQuickListModel();
$engine = new \Qt\Qml\QQmlApplicationEngine();
$engine->rootContext()->setContextProperty('salesFeedModel', $model);
$engine->loadData(<<<'QML'
import QtQuick
import QtQuick.Window

Window {
    visible: true
    width: 520
    height: 360
    property int listCount: salesList.count
    property string latestEntry: salesList.count > 0 ? String(salesFeedModel.data(salesFeedModel.index(salesList.count - 1, 0))) : ""

    ListView {
        id: salesList
        anchors.fill: parent
        model: salesFeedModel
        delegate: Text {
            required property var modelData
            text: String(modelData)
        }
    }
}
QML, new \Qt\Core\QUrl());

$window = $engine->rootObjects()[0] ?? null;
if (!is_object($window)) {
    fwrite(STDERR, "No QtQuick window was created.\n");
    exit(1);
}

$initialCount = $window->listCount;
$initialLatest = $window->latestEntry;
$driver = new RuntimeQuickListDriver($model);
\Qt\Gui\QGuiApplication::exec();

qt_runtime_result([
    'initial_count' => $initialCount,
    'updated_count' => $window->listCount,
    'initial_latest' => $initialLatest,
    'updated_latest' => $window->latestEntry,
    'model_latest' => $model->latestEntry(),
]);
