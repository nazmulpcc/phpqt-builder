<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');
qt_runtime_require_class(\Qt\Core\QObject::class, 'QtCore QObject classes are unavailable in this build.');

final class RuntimeShutdownDriver extends \Qt\Core\QObject
{
    public int $ticks = 0;
    private int $timerId = 0;

    public function __construct()
    {
        parent::__construct();
        $this->timerId = $this->startTimer(5);
    }

    protected function timerEvent(\Qt\Core\QTimerEvent $event): void
    {
        if ($event->timerId() !== $this->timerId) {
            return;
        }

        $this->ticks++;
        $this->killTimer($this->timerId);
        \Qt\Core\QCoreApplication::quit();
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);

$aboutToQuitHits = 0;
$notifyHits = 0;

$parent = new \Qt\Core\QObject();
$child = new \Qt\Core\QObject();
$child->setParent($parent);

$notifyConnection = $child->connectPropertyNotify('objectName', static function () use (&$notifyHits): void {
    $notifyHits++;
});

$aboutConnection = $app->onAboutToQuit(static function () use (&$aboutToQuitHits, $child): void {
    $aboutToQuitHits++;
    $child->objectName = 'quit-phase';
});

$child->objectName = 'ready';
$driver = new RuntimeShutdownDriver();
\Qt\Core\QCoreApplication::exec();

qt_runtime_result([
    'ticks' => $driver->ticks,
    'about_to_quit_hits' => $aboutToQuitHits,
    'notify_hits' => $notifyHits,
    'connections_are_objects' => is_object($notifyConnection) && is_object($aboutConnection),
]);
