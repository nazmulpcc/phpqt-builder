<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QCoreApplication::class, 'QtCore event loop classes are unavailable in this build.');

final class RuntimeLoggingFilter extends \Qt\Core\QObject
{
    public int $filterCount = 0;

    public function eventFilter(\Qt\Core\QObject $watched, \Qt\Core\QEvent $event): bool
    {
        $this->filterCount++;

        return false;
    }
}

final class RuntimeLoggingReceiver extends \Qt\Core\QObject
{
    public int $eventCount = 0;
    public int $customCount = 0;

    public function event(\Qt\Core\QEvent $event): bool
    {
        $this->eventCount++;

        return parent::event($event);
    }

    protected function customEvent(\Qt\Core\QEvent $event): void
    {
        $this->customCount++;
    }
}

final class RuntimeTimerDriver extends \Qt\Core\QObject
{
    public int $ticks = 0;
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
        if ($this->ticks >= 3) {
            $this->killTimer($this->timerId);
            \Qt\Core\QCoreApplication::quit();
        }
    }
}

$argc = 0;
$app = new \Qt\Core\QCoreApplication($argc, ['qt-runtime']);
$receiver = new RuntimeLoggingReceiver();
$filter = new RuntimeLoggingFilter();
$receiver->installEventFilter($filter);

$customType = \Qt\Core\QEvent::registerEventType();
\Qt\Core\QCoreApplication::sendEvent($receiver, new \Qt\Core\QEvent($customType));
\Qt\Core\QCoreApplication::sendEvent($receiver, new \Qt\Core\QEvent($customType));

$driver = new RuntimeTimerDriver();
\Qt\Core\QCoreApplication::exec();

qt_runtime_result([
    'custom_type' => $customType,
    'filter_count' => $filter->filterCount,
    'event_count' => $receiver->eventCount,
    'custom_count' => $receiver->customCount,
    'timer_ticks' => $driver->ticks,
]);
