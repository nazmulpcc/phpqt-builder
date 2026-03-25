<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Quick\QQuickWindow::class, 'QtQuick window classes are unavailable in this build.');

final class RuntimeQuickDriver extends \Qt\Core\QObject
{
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

        $this->killTimer($this->timerId);
        \Qt\Core\QCoreApplication::quit();
    }
}

$argc = 0;
$app = new \Qt\Gui\QGuiApplication($argc, []);
$sales = new \Qt\Core\QObject();
$sales->objectName = 'sales-1';
$notifyHits = 0;
$connection = $sales->connectPropertyNotify('objectName', function () use (&$notifyHits): void {
    $notifyHits++;
});

$engine = new \Qt\Qml\QQmlApplicationEngine();
$engine->rootContext()->setContextProperty('sales', $sales);
$engine->loadData(<<<'QML'
import QtQuick
import QtQuick.Window

Window {
    visible: true
    objectName: "salesWindow"
    title: sales.objectName
    property string seenName: sales.objectName
    width: 320
    height: 180
}
QML, new \Qt\Core\QUrl());

$roots = $engine->rootObjects();
$root = $roots[0] ?? null;
if (!is_object($root)) {
    fwrite(STDERR, "No root window was created.\n");
    exit(1);
}

$initialTitle = $root->title;
$sales->objectName = 'sales-2';
$driver = new RuntimeQuickDriver();
\Qt\Gui\QGuiApplication::exec();

qt_runtime_result([
    'root_count' => count($roots),
    'initial_title' => $initialTitle,
    'updated_title' => $root->title,
    'updated_seen_name' => $root->seenName,
    'notify_hits' => $notifyHits,
]);
