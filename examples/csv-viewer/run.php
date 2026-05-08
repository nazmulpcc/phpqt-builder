<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Examples\Support\AppPaths;
use Qt\Core\Attributes\Signal;
use Qt\Core\Attributes\Slot;
use Qt\Core\QCoreApplication;
use Qt\Core\QObject;
use Qt\Core\QTimerEvent;
use Qt\Core\QUrl;
use Qt\Gui\QGuiApplication;
use Qt\Qml\QQmlComponent;
use Qt\Qml\QQmlEngine;

final class AutoQuitDriver extends QObject
{
    private int $timerId = 0;

    public function __construct(int $seconds)
    {
        parent::__construct();

        if ($seconds > 0) {
            $this->timerId = $this->startTimer($seconds * 1000);
        }
    }

    protected function timerEvent(QTimerEvent $event): void
    {
        if ($event->timerId() !== $this->timerId) {
            return;
        }

        $this->killTimer($this->timerId);
        QCoreApplication::quit();
    }
}

final class CsvGateway extends QObject
{
    /** @var list<string> */
    private array $headers = [];
    /** @var list<list<string>> */
    private array $rows = [];
    /** @var list<list<string>> */
    private array $filteredRows = [];
    private int $page = 1;
    private int $pageSize = 15;
    private string $path = '';
    private string $samplePath = '';
    private string $exportDir = '';

    public function __construct(string $samplePath, string $exportDir)
    {
        parent::__construct();
        $this->samplePath = $samplePath;
        $this->exportDir = $exportDir;
        $this->loadCsvInternal($samplePath);
    }

    #[Slot]
    public function refresh(): void
    {
        $this->emitData();
    }

    #[Slot]
    public function reload(): void
    {
        $this->loadCsvInternal($this->samplePath);
        $this->emitData();
        $this->statusMessage('Reloaded sample data.');
    }

    #[Slot(['string'])]
    public function applyFilter(string $query): void
    {
        $query = strtolower(trim($query));
        if ($query === '') {
            $this->filteredRows = $this->rows;
        } else {
            $this->filteredRows = array_values(array_filter(
                $this->rows,
                static function (array $row) use ($query): bool {
                    foreach ($row as $cell) {
                        if (str_contains(strtolower($cell), $query)) {
                            return true;
                        }
                    }
                    return false;
                },
            ));
        }
        $this->page = 1;
        $this->emitData();
    }

    #[Slot(['int'])]
    public function setPageSize(int $size): void
    {
        $this->pageSize = max(1, $size);
        $this->page = 1;
        $this->emitData();
    }

    #[Slot]
    public function nextPage(): void
    {
        if ($this->page < $this->totalPages()) {
            $this->page++;
            $this->emitData();
        }
    }

    #[Slot]
    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
            $this->emitData();
        }
    }

    #[Slot]
    public function exportFiltered(): void
    {
        $target = $this->exportDir . '/filtered-' . date('Ymd-His') . '.csv';
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            $this->errorMessage('Unable to write export file.');

            return;
        }
        fputcsv($handle, $this->headers);
        foreach ($this->filteredRows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        $this->statusMessage('Exported ' . count($this->filteredRows) . ' rows to ' . basename($target));
    }

    #[Signal(['string'])]
    protected function dataChanged(string $json): void {}

    #[Signal(['string'])]
    protected function statusMessage(string $msg): void {}

    #[Signal(['string'])]
    protected function errorMessage(string $msg): void {}

    private function loadCsvInternal(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->errorMessage('Unable to open CSV: ' . $path);

            return;
        }

        $headers = fgetcsv($handle, escape: '\\');
        $rows = [];
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $rows[] = array_map(static fn ($v): string => (string) $v, $row);
        }
        fclose($handle);

        $this->headers = array_map(static fn ($v): string => (string) $v, $headers ?: []);
        $this->rows = $rows;
        $this->filteredRows = $rows;
        $this->path = $path;
        $this->page = 1;
    }

    private function emitData(): void
    {
        $offset = ($this->page - 1) * $this->pageSize;
        $currentRows = array_slice($this->filteredRows, $offset, $this->pageSize);
        $this->dataChanged(json_encode([
            'headers' => $this->headers,
            'rows' => $currentRows,
            'totalRows' => count($this->rows),
            'filteredCount' => count($this->filteredRows),
            'page' => $this->page,
            'totalPages' => $this->totalPages(),
            'pageSize' => $this->pageSize,
            'fileName' => basename($this->path),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function totalPages(): int
    {
        return max(1, (int) ceil(max(1, count($this->filteredRows)) / $this->pageSize));
    }
}

example_section('CSV Viewer (QML + Signal/Slot)');

$paths = AppPaths::fromExampleRoot(__DIR__);

$argc = 0;
$app = new QGuiApplication($argc, ['csv-viewer']);
$engine = new QQmlEngine();
$gateway = new CsvGateway($paths->dataFile('sample-sales.csv'), $paths->exportsDir());

$engine->rootContext()->setContextProperty('csv', $gateway);

$component = new QQmlComponent($engine);
$component->setData(<<<'QML'
import QtQuick
import QtQuick.Window

Window {
    id: root
    width: 1200
    height: 740
    visible: true
    color: "#0a1221"
    title: "CSV Viewer"

    property var headers: []
    property var rows: []
    property int totalRows: 0
    property int filteredCount: 0
    property int currentPage: 1
    property int totalPages: 1
    property int currentPageSize: 15
    property string fileName: ""
    property string bannerText: ""
    property string bannerBg: "#1a2a4a"
    property string bannerFg: "#7ab8ff"
    property bool bannerVisible: false

    property var colWidths: {
        var w = root.width - 64 - 40
        var n = headers.length
        if (n === 0) return []
        var weights = [1.1, 1.8, 0.8, 1.5, 1.0, 1.0, 1.7]
        var totalWeight = 0
        for (var i = 0; i < n; i++) {
            totalWeight += (i < weights.length ? weights[i] : 1.0)
        }
        var result = []
        for (var i = 0; i < n; i++) {
            result.push(Math.floor(w * (i < weights.length ? weights[i] : 1.0) / totalWeight))
        }
        return result
    }

    function statusColor(s) {
        if (s === "Paid") return "#1ea567"
        if (s === "Pending") return "#d4a843"
        if (s === "Shipped") return "#2d72ff"
        if (s === "Delayed") return "#e67e22"
        if (s === "Refunded") return "#db5d5d"
        return "#93a7c5"
    }

    Connections {
        target: csv

        function onDataChanged(json) {
            var d = JSON.parse(json)
            root.headers = d.headers
            root.rows = d.rows
            root.totalRows = d.totalRows
            root.filteredCount = d.filteredCount
            root.currentPage = d.page
            root.totalPages = d.totalPages
            root.currentPageSize = d.pageSize
            root.fileName = d.fileName
            root.title = "CSV Viewer | " + d.fileName
        }

        function onStatusMessage(msg) {
            bannerText = msg
            bannerBg = "#1a2a4a"
            bannerFg = "#7ab8ff"
            bannerVisible = true
            bannerTimer.restart()
        }

        function onErrorMessage(msg) {
            bannerText = msg
            bannerBg = "#3d1a1a"
            bannerFg = "#ff8b8b"
            bannerVisible = true
            bannerTimer.restart()
        }
    }

    Timer {
        id: bannerTimer
        interval: 4000
        onTriggered: bannerVisible = false
    }

    Rectangle {
        anchors.fill: parent
        gradient: Gradient {
            GradientStop { position: 0.0; color: "#0f1b33" }
            GradientStop { position: 1.0; color: "#070d1a" }
        }
    }

    Row {
        id: headerBar
        anchors.top: parent.top
        anchors.left: parent.left
        anchors.right: parent.right
        anchors.topMargin: 32
        anchors.leftMargin: 32
        anchors.rightMargin: 32
        height: 48
        spacing: 12

        Rectangle {
            width: 4
            height: 48
            radius: 2
            color: "#2d72ff"
        }

        Column {
            width: parent.width - 4 - 12 - 140 - 10 - 130 - 12
            spacing: 2

            Text {
                text: "CSV Viewer"
                color: "#f6f8ff"
                font.pixelSize: 24
                font.bold: true
            }
            Text {
                text: root.fileName ? "Viewing " + root.fileName : "No file loaded"
                color: "#93a7c5"
                font.pixelSize: 13
            }
        }

        Rectangle {
            width: 140
            height: 38
            radius: 8
            color: exportMouse.containsMouse ? "#1a5fd6" : "#2d72ff"
            anchors.verticalCenter: parent.verticalCenter

            Text {
                anchors.centerIn: parent
                text: "Export Filtered"
                color: "#ffffff"
                font.pixelSize: 13
                font.bold: true
            }

            MouseArea {
                id: exportMouse
                anchors.fill: parent
                hoverEnabled: true
                cursorShape: Qt.PointingHandCursor
                onClicked: csv.exportFiltered()
            }
        }

        Rectangle {
            width: 130
            height: 38
            radius: 8
            color: reloadMouse.containsMouse ? "#1c2844" : "#16223a"
            border.width: 1
            border.color: "#22314f"
            anchors.verticalCenter: parent.verticalCenter

            Text {
                anchors.centerIn: parent
                text: "Reload Sample"
                color: "#93a7c5"
                font.pixelSize: 13
            }

            MouseArea {
                id: reloadMouse
                anchors.fill: parent
                hoverEnabled: true
                cursorShape: Qt.PointingHandCursor
                onClicked: csv.reload()
            }
        }
    }

    Rectangle {
        id: searchBar
        anchors.top: headerBar.bottom
        anchors.left: parent.left
        anchors.right: parent.right
        anchors.topMargin: 16
        anchors.leftMargin: 32
        anchors.rightMargin: 32
        height: 46
        radius: 10
        color: "#101a2f"
        border.width: 1
        border.color: searchInput.activeFocus ? "#2d72ff" : "#22314f"

        Text {
            x: 16
            y: 0
            height: parent.height
            verticalAlignment: Text.AlignVCenter
            text: "\u2315"
            color: searchInput.activeFocus ? "#2d72ff" : "#60748f"
            font.pixelSize: 18
        }

        TextInput {
            id: searchInput
            x: 42
            width: parent.width - 58
            height: parent.height
            verticalAlignment: Text.AlignVCenter
            color: "#f6f8ff"
            font.pixelSize: 14
            selectByMouse: true

            Text {
                anchors.fill: parent
                verticalAlignment: Text.AlignVCenter
                text: "Filter by any column..."
                color: "#60748f"
                font.pixelSize: 14
                visible: searchInput.text.length === 0 && !searchInput.activeFocus
            }

            onTextChanged: csv.applyFilter(text)
        }
    }

    Rectangle {
        id: banner
        anchors.top: searchBar.bottom
        anchors.left: parent.left
        anchors.right: parent.right
        anchors.topMargin: 8
        anchors.leftMargin: 32
        anchors.rightMargin: 32
        height: bannerVisible ? 40 : 0
        radius: 8
        color: bannerBg
        visible: bannerVisible
        clip: true

        Text {
            anchors.centerIn: parent
            text: bannerText
            color: bannerFg
            font.pixelSize: 13
        }

        Behavior on height { NumberAnimation { duration: 200 } }
    }

    Rectangle {
        id: tableFrame
        anchors.top: banner.bottom
        anchors.topMargin: 16
        anchors.bottom: paginationBar.top
        anchors.bottomMargin: 16
        anchors.left: parent.left
        anchors.right: parent.right
        anchors.leftMargin: 32
        anchors.rightMargin: 32
        radius: 12
        color: "#0d1526"
        border.width: 1
        border.color: "#1a2540"
        clip: true

        Column {
            anchors.fill: parent
            spacing: 0

            Rectangle {
                width: parent.width
                height: 46
                radius: 12
                color: "#141e36"

                Rectangle {
                    anchors.bottom: parent.bottom
                    width: parent.width
                    height: 13
                    color: "#141e36"
                }

                Row {
                    x: 20
                    width: parent.width - 40
                    height: 46
                    spacing: 0

                    Repeater {
                        model: root.headers
                        delegate: Text {
                            width: root.colWidths[index] || 100
                            height: 46
                            verticalAlignment: Text.AlignVCenter
                            text: modelData
                            color: "#8b9dc3"
                            font.pixelSize: 12
                            font.bold: true
                            font.capitalization: Font.AllUppercase
                            elide: Text.ElideRight
                        }
                    }
                }
            }

            Rectangle {
                width: parent.width
                height: 1
                color: "#1a2a45"
            }

            ListView {
                id: tableView
                width: parent.width
                height: parent.height - 47
                clip: true
                model: root.rows
                spacing: 0

                delegate: Rectangle {
                    id: rowItem
                    width: tableView.width
                    height: 44
                    property var cellValues: modelData
                    property int rowIdx: index
                    color: rowIdx % 2 === 0 ? "transparent" : "#0f1829"

                    Row {
                        x: 20
                        width: parent.width - 40
                        height: 44
                        spacing: 0

                        Repeater {
                            model: rowItem.cellValues
                            delegate: Item {
                                width: root.colWidths[index] || 100
                                height: 44
                                property string cellText: modelData
                                property int colIdx: index

                                Rectangle {
                                    visible: colIdx === 4
                                    anchors.verticalCenter: parent.verticalCenter
                                    width: statusLbl.width + 22
                                    height: 28
                                    radius: 7
                                    color: statusColor(cellText) + "30"

                                    Text {
                                        id: statusLbl
                                        anchors.centerIn: parent
                                        text: cellText
                                        color: statusColor(cellText)
                                        font.pixelSize: 12
                                        font.bold: true
                                    }
                                }

                                Text {
                                    visible: colIdx !== 4
                                    anchors.verticalCenter: parent.verticalCenter
                                    text: cellText
                                    color: colIdx === 0 ? "#7ab8ff" : "#f6f8ff"
                                    font.pixelSize: 13
                                    elide: Text.ElideRight
                                }
                            }
                        }
                    }

                    Rectangle {
                        anchors.bottom: parent.bottom
                        width: parent.width
                        height: 1
                        color: "#111d30"
                    }

                    MouseArea {
                        anchors.fill: parent
                        hoverEnabled: true
                        onEntered: rowItem.color = "#162545"
                        onExited: rowItem.color = rowItem.rowIdx % 2 === 0 ? "transparent" : "#0f1829"
                    }
                }
            }
        }
    }

    Row {
        id: paginationBar
        anchors.bottom: parent.bottom
        anchors.left: parent.left
        anchors.right: parent.right
        anchors.bottomMargin: 32
        anchors.leftMargin: 32
        anchors.rightMargin: 32
        height: 36
        spacing: 12

        Text {
            text: root.totalRows + " rows total  \u2022  " + root.filteredCount + " filtered  \u2022  page " + root.currentPage + " / " + root.totalPages
            color: "#93a7c5"
            font.pixelSize: 13
            anchors.verticalCenter: parent.verticalCenter
        }

        Item {
            width: parent.width - 490 - 200 - 80 - 80 - 12 * 4
            height: 1
        }

        Row {
            spacing: 4
            anchors.verticalCenter: parent.verticalCenter

            Text {
                text: "Rows:"
                color: "#60748f"
                font.pixelSize: 12
                anchors.verticalCenter: parent.verticalCenter
            }

            Repeater {
                model: [10, 15, 25, 50]
                delegate: Rectangle {
                    width: 38
                    height: 28
                    radius: 6
                    color: root.currentPageSize === modelData ? "#2d72ff" : "#16223a"
                    anchors.verticalCenter: parent.verticalCenter

                    Text {
                        anchors.centerIn: parent
                        text: modelData
                        color: root.currentPageSize === modelData ? "#ffffff" : "#93a7c5"
                        font.pixelSize: 12
                        font.bold: root.currentPageSize === modelData
                    }

                    MouseArea {
                        anchors.fill: parent
                        cursorShape: Qt.PointingHandCursor
                        onClicked: csv.setPageSize(modelData)
                    }
                }
            }
        }

        Rectangle {
            width: 80
            height: 32
            radius: 6
            color: prevMouse.containsMouse && root.currentPage > 1 ? "#1c2844" : "#16223a"
            border.width: 1
            border.color: root.currentPage > 1 ? "#22314f" : "transparent"
            anchors.verticalCenter: parent.verticalCenter

            Text {
                anchors.centerIn: parent
                text: "\u25C0 Prev"
                color: root.currentPage > 1 ? "#93a7c5" : "#3a4a60"
                font.pixelSize: 12
            }

            MouseArea {
                id: prevMouse
                anchors.fill: parent
                hoverEnabled: true
                cursorShape: Qt.PointingHandCursor
                onClicked: csv.previousPage()
            }
        }

        Rectangle {
            width: 80
            height: 32
            radius: 6
            color: nextMouse.containsMouse && root.currentPage < root.totalPages ? "#1c2844" : "#16223a"
            border.width: 1
            border.color: root.currentPage < root.totalPages ? "#22314f" : "transparent"
            anchors.verticalCenter: parent.verticalCenter

            Text {
                anchors.centerIn: parent
                text: "Next \u25B6"
                color: root.currentPage < root.totalPages ? "#93a7c5" : "#3a4a60"
                font.pixelSize: 12
            }

            MouseArea {
                id: nextMouse
                anchors.fill: parent
                hoverEnabled: true
                cursorShape: Qt.PointingHandCursor
                onClicked: csv.nextPage()
            }
        }
    }

    Component.onCompleted: csv.refresh()
}
QML, new QUrl());

if (!$component->isReady()) {
    example_fail($component->errorString());
}

$root = $component->create($engine->rootContext());
if (!is_object($root)) {
    example_fail($component->errorString());
}

$autoQuitSeconds = example_auto_quit_seconds();
$autoQuitDriver = new AutoQuitDriver($autoQuitSeconds);

if ($autoQuitSeconds > 0) {
    example_line('auto-quit enabled: ' . $autoQuitSeconds . 's');
}

example_line('csv viewer ready; QML + Signal/Slot');
QGuiApplication::exec();
