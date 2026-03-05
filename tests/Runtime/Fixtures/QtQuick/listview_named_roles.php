<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Quick\QQuickWindow::class, 'QtQuick window classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QAbstractListModel::class, 'QtCore abstract list model classes are unavailable in this build.');

final class RuntimeQuickNamedRoleModel extends \Qt\Core\QAbstractListModel
{
    /** @var list<array{label:string,region:string,amount:string}> */
    private array $rows = [];

    public function __construct()
    {
        parent::__construct();
        $this->appendRows([
            ['label' => '#SO-0401', 'region' => 'EMEA', 'amount' => '$910'],
            ['label' => '#SO-0402', 'region' => 'APAC', 'amount' => '$945'],
        ]);
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

    public function roleNames(): array
    {
        return [
            256 => 'label',
            257 => 'region',
            258 => 'amount',
        ];
    }

    public function data(\Qt\Core\QModelIndex $index, int $role = 0): \Qt\Core\QVariant
    {
        if (!$index->isValid()) {
            return new \Qt\Core\QVariant();
        }

        $row = $this->rows[$index->row()] ?? null;
        if ($row === null) {
            return new \Qt\Core\QVariant();
        }

        return match ($role) {
            256 => new \Qt\Core\QVariant($row['label']),
            257 => new \Qt\Core\QVariant($row['region']),
            258 => new \Qt\Core\QVariant($row['amount']),
            default => new \Qt\Core\QVariant(),
        };
    }

    public function parentModelIndex(\Qt\Core\QModelIndex $child): \Qt\Core\QModelIndex
    {
        return new \Qt\Core\QModelIndex();
    }

    /**
     * @param list<array{label:string,region:string,amount:string}> $rows
     */
    public function appendRows(array $rows): void
    {
        $first = count($this->rows);
        $last = $first + count($rows) - 1;
        $this->beginInsertRows(new \Qt\Core\QModelIndex(), $first, $last);
        array_push($this->rows, ...$rows);
        $this->endInsertRows();
    }

    public function latestLabel(): string
    {
        return $this->rows[array_key_last($this->rows)]['label'] ?? '';
    }
}

final class RuntimeQuickNamedRoleDriver extends \Qt\Core\QObject
{
    private int $timerId = 0;
    private int $ticks = 0;

    public function __construct(private readonly RuntimeQuickNamedRoleModel $model)
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
            $this->model->appendRows([
                ['label' => '#SO-0403', 'region' => 'AMER', 'amount' => '$980'],
            ]);
            return;
        }

        $this->killTimer($this->timerId);
        \Qt\Core\QCoreApplication::quit();
    }
}

$argc = 0;
$app = new \Qt\Gui\QGuiApplication($argc, []);
$model = new RuntimeQuickNamedRoleModel();
$engine = new \Qt\Qml\QQmlApplicationEngine();
$engine->rootContext()->setContextProperty('salesFeedModel', $model);
$engine->loadData(<<<'QML'
import QtQuick
import QtQuick.Window

Window {
    visible: true
    width: 560
    height: 360
    property int listCount: salesList.count
    property string latestLabel: salesList.count > 0 ? String(salesFeedModel.data(salesFeedModel.index(salesList.count - 1, 0), 256)) : ""
    property string latestSummary: salesList.count > 0 ? String(salesFeedModel.data(salesFeedModel.index(salesList.count - 1, 0), 257)) + " " + String(salesFeedModel.data(salesFeedModel.index(salesList.count - 1, 0), 258)) : ""

    ListView {
        id: salesList
        anchors.fill: parent
        model: salesFeedModel

        delegate: Text {
            required property string label
            required property string region
            required property string amount
            text: label + "  " + region + "  " + amount
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
$initialLatestLabel = $window->latestLabel;
$initialLatestSummary = $window->latestSummary;
$driver = new RuntimeQuickNamedRoleDriver($model);
\Qt\Gui\QGuiApplication::exec();

qt_runtime_result([
    'initial_count' => $initialCount,
    'updated_count' => $window->listCount,
    'initial_latest_label' => $initialLatestLabel,
    'updated_latest_label' => $window->latestLabel,
    'initial_latest_summary' => $initialLatestSummary,
    'updated_latest_summary' => $window->latestSummary,
    'model_latest_label' => $model->latestLabel(),
]);
