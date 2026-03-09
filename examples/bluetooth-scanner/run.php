<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Qt\Bluetooth\QBluetoothAddress;
use Qt\Bluetooth\QBluetoothDeviceDiscoveryAgent;
use Qt\Bluetooth\QBluetoothDeviceInfo;
use Qt\Bluetooth\QBluetoothHostInfo;
use Qt\Bluetooth\QBluetoothLocalDevice;
use Qt\Core\QCoreApplication;
use Qt\Core\QTimer;

if (!class_exists(QBluetoothLocalDevice::class) || !class_exists(QBluetoothDeviceDiscoveryAgent::class)) {
    example_fail('QtBluetooth classes are unavailable in this build. Rebuild with QtBluetooth support.');
}

example_section('Bluetooth Scanner');

$argc = 0;
$app = new QCoreApplication($argc, []);
$seenAddresses = [];
$devicesFound = 0;

/** @var array<int, QBluetoothHostInfo> $adapters */
$adapters = QBluetoothLocalDevice::allDevices();
example_line(sprintf('Adapters found: %d', count($adapters)));

foreach ($adapters as $index => $adapter) {
    $address = $adapter->address()->toString();
    $name = trim($adapter->name());
    if ($name === '') {
        $name = '(unnamed adapter)';
    }

    example_line(sprintf('  [%d] %s  %s', $index, $address, $name));
}

$agent = null;
if ($adapters !== []) {
    $agent = new QBluetoothDeviceDiscoveryAgent($adapters[0]->address());
    example_line(sprintf('Using adapter: %s', $adapters[0]->address()->toString()));
} else {
    $agent = new QBluetoothDeviceDiscoveryAgent();
    example_line('No local adapter metadata returned; using default discovery agent.');
}

$agent->setLowEnergyDiscoveryTimeout(4000);

$agent->onDeviceDiscovered(function (QBluetoothDeviceInfo $device) use (&$seenAddresses, &$devicesFound): void {
    $address = $device->address()->toString();
    if (isset($seenAddresses[$address])) {
        return;
    }

    $seenAddresses[$address] = true;
    $devicesFound++;

    $name = trim($device->name());
    if ($name === '') {
        $name = '(unnamed device)';
    }

    example_line(sprintf('  Device: %s  %s  RSSI=%d', $address, $name, $device->rssi()));
});

$agent->onErrorOccurred(function (int $error) use ($agent): void {
    example_line(sprintf('Discovery error %d: %s', $error, $agent->errorString()));
    QCoreApplication::quit();
});

$agent->onFinished(function () use (&$devicesFound): void {
    example_line(sprintf('Discovery finished. Unique devices found: %d', $devicesFound));
    QCoreApplication::quit();
});

$agent->onCanceled(function () use (&$devicesFound): void {
    example_line(sprintf('Discovery canceled. Unique devices found: %d', $devicesFound));
    QCoreApplication::quit();
});

$timeoutMs = (int) getenv('QT_BLUETOOTH_SCAN_TIMEOUT_MS');
if ($timeoutMs <= 0) {
    $timeoutMs = 6500;
}

$timeout = new QTimer();
$timeout->setSingleShot(true);
$timeout->onTimeout(function () use ($agent, &$devicesFound): void {
    if ($agent->isActive()) {
        example_line(sprintf('Timeout reached; stopping scan. Devices found so far: %d', $devicesFound));
        $agent->stop();
        return;
    }

    QCoreApplication::quit();
});

$method = QBluetoothDeviceDiscoveryAgent::LowEnergyMethod;
example_line('Starting discovery (LowEnergyMethod).');
$agent->start($method);
$timeout->start($timeoutMs);

$exitCode = QCoreApplication::exec();
example_line(sprintf('Scanner exiting with code %d', $exitCode));

exit($exitCode);
