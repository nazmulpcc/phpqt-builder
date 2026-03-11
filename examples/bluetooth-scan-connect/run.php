<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Examples\Support\Theme\WidgetTheme;
use Examples\Support\Widgets\AppWindow;
use Examples\Support\Widgets\Banner;
use Examples\Support\Widgets\FormFieldRow;
use Examples\Support\Widgets\StatusBarMessage;
use Qt\Bluetooth\QBluetoothAddress;
use Qt\Bluetooth\QBluetoothDeviceDiscoveryAgent;
use Qt\Bluetooth\QBluetoothDeviceInfo;
use Qt\Bluetooth\QBluetoothLocalDevice;
use Qt\Bluetooth\QBluetoothServiceDiscoveryAgent;
use Qt\Bluetooth\QBluetoothServiceInfo;
use Qt\Bluetooth\QBluetoothSocket;
use Qt\Bluetooth\QBluetoothUuid;
use Qt\Core\QIODeviceBase;
use Qt\Core\QTimer;
use Qt\Widgets\QApplication;
use Qt\Widgets\QComboBox;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QLineEdit;
use Qt\Widgets\QListWidget;
use Qt\Widgets\QPlainTextEdit;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

/**
 * @param list<string> $classes
 */
function assert_classes_available(array $classes): void
{
    foreach ($classes as $class) {
        if (!class_exists($class)) {
            throw new RuntimeException(sprintf('Required class is not available: %s', $class));
        }
    }
}

function bt_device_label(QBluetoothDeviceInfo $device): string
{
    $name = trim($device->name());
    if ($name === '') {
        $name = '(unnamed device)';
    }

    $address = $device->address()->toString();
    $rssi = $device->rssi();

    return sprintf('%s | %s | RSSI %d dBm', $name, $address, $rssi);
}

function bt_service_label(QBluetoothServiceInfo $service): string
{
    $name = trim($service->serviceName());
    if ($name === '') {
        $name = '(unnamed service)';
    }

    $uuid = $service->serviceUuid()->toString();
    if ($uuid === '') {
        $uuid = '(no UUID)';
    }

    return sprintf('%s | %s', $name, $uuid);
}

function bt_socket_state_name(int $state): string
{
    return match ($state) {
        QBluetoothSocket::UnconnectedState => 'Unconnected',
        QBluetoothSocket::ServiceLookupState => 'Service lookup',
        QBluetoothSocket::ConnectingState => 'Connecting',
        QBluetoothSocket::ConnectedState => 'Connected',
        QBluetoothSocket::BoundState => 'Bound',
        QBluetoothSocket::ClosingState => 'Closing',
        QBluetoothSocket::ListeningState => 'Listening',
        default => 'Unknown',
    };
}

function bt_host_mode_name(int $mode): string
{
    return match ($mode) {
        QBluetoothLocalDevice::HostPoweredOff => 'Powered off',
        QBluetoothLocalDevice::HostConnectable => 'Connectable',
        QBluetoothLocalDevice::HostDiscoverable => 'Discoverable',
        QBluetoothLocalDevice::HostDiscoverableLimitedInquiry => 'Discoverable (limited)',
        default => 'Unknown',
    };
}

function bt_supported_methods_label(int $methods): string
{
    if ($methods === QBluetoothDeviceDiscoveryAgent::NoMethod) {
        return 'None';
    }

    $labels = [];
    if (($methods & QBluetoothDeviceDiscoveryAgent::ClassicMethod) !== 0) {
        $labels[] = 'Classic';
    }
    if (($methods & QBluetoothDeviceDiscoveryAgent::LowEnergyMethod) !== 0) {
        $labels[] = 'LowEnergy';
    }

    return implode(' + ', $labels);
}

example_section('Bluetooth Scan & Connect');

try {
    assert_classes_available([
        QApplication::class,
        QBluetoothLocalDevice::class,
        QBluetoothDeviceDiscoveryAgent::class,
        QBluetoothServiceDiscoveryAgent::class,
        QBluetoothDeviceInfo::class,
        QBluetoothServiceInfo::class,
        QBluetoothSocket::class,
        QBluetoothUuid::class,
        QBluetoothAddress::class,
    ]);
} catch (Throwable $throwable) {
    example_fail($throwable->getMessage() . ' Build with QtWidgets and QtBluetooth modules.');
}

$app = new QApplication();
$window = new QWidget();
$window->resize(1240, 820);
$window->setWindowTitle('Bluetooth Scan & Connect');
WidgetTheme::apply($window, 'dark');

$shell = new AppWindow(
    'Bluetooth Scan & Connect',
    'Discover nearby devices, inspect services, and try a socket connection from PHP.',
);
$banner = new Banner();
$status = new StatusBarMessage();

$adapterLabel = new QLabel('Adapter: checking...');
$adapterLabel->setProperty('role', 'caption');

$powerOnButton = new QPushButton('Power On Adapter');
$scanButton = new QPushButton('Scan Nearby Devices');
$stopScanButton = new QPushButton('Stop Scan');
$stopScanButton->setProperty('variant', 'secondary');
$stopScanButton->setEnabled(false);

$deviceList = new QListWidget();
$deviceList->setMinimumHeight(260);
$selectedDeviceLabel = new QLabel('Selected device: (none)');
$selectedDeviceLabel->setProperty('role', 'caption');

$discoverServicesButton = new QPushButton('Discover Services');
$discoverServicesButton->setProperty('variant', 'secondary');
$stopServicesButton = new QPushButton('Stop Service Discovery');
$stopServicesButton->setProperty('variant', 'secondary');
$stopServicesButton->setEnabled(false);

$serviceList = new QListWidget();
$serviceList->setMinimumHeight(240);
$selectedServiceLabel = new QLabel('Selected service: (none)');
$selectedServiceLabel->setProperty('role', 'caption');

$uuidPreset = new QComboBox();
$uuidMap = [
    'SerialPort' => QBluetoothUuid::SerialPort,
    'ObexObjectPush' => QBluetoothUuid::ObexObjectPush,
    'AudioSink' => QBluetoothUuid::AudioSink,
];
foreach (array_keys($uuidMap) as $name) {
    $uuidPreset->addItem($name);
}

$uuidInput = new QLineEdit();
$uuidInput->setPlaceholderText('Service UUID (auto-filled from selection or preset)');
$uuidInput->setText((new QBluetoothUuid(QBluetoothUuid::SerialPort))->toString());

$connectButton = new QPushButton('Connect');
$disconnectButton = new QPushButton('Disconnect');
$disconnectButton->setProperty('variant', 'secondary');
$disconnectButton->setEnabled(false);

$sendInput = new QLineEdit();
$sendInput->setPlaceholderText('Optional payload to send after connected');
$sendButton = new QPushButton('Send');
$sendButton->setProperty('variant', 'secondary');
$sendButton->setEnabled(false);

$logOutput = new QPlainTextEdit();
$logOutput->setReadOnly(true);
$logOutput->setMinimumHeight(240);
$logOutput->setPlaceholderText('Bluetooth activity log...');

$scanButtons = new QHBoxLayout();
$scanButtons->addWidget($powerOnButton);
$scanButtons->addWidget($scanButton);
$scanButtons->addWidget($stopScanButton);
$scanButtons->addStretch(1);

$serviceButtons = new QHBoxLayout();
$serviceButtons->addWidget($discoverServicesButton);
$serviceButtons->addWidget($stopServicesButton);
$serviceButtons->addStretch(1);

$connectButtons = new QHBoxLayout();
$connectButtons->addWidget($connectButton);
$connectButtons->addWidget($disconnectButton);
$connectButtons->addWidget($sendButton);
$connectButtons->addStretch(1);

$leftPanel = new QWidget();
$leftLayout = new QVBoxLayout();
$leftLayout->addWidget(new QLabel('1) Scan nearby devices'));
$leftLayout->addLayout($scanButtons);
$leftLayout->addWidget($adapterLabel);
$leftLayout->addWidget($deviceList);
$leftLayout->addWidget($selectedDeviceLabel);
$leftLayout->addSpacing(8);
$leftLayout->addWidget(new QLabel('2) Discover services on selected device (optional)'));
$leftLayout->addLayout($serviceButtons);
$leftLayout->addWidget($serviceList);
$leftLayout->addWidget($selectedServiceLabel);
$leftPanel->setLayout($leftLayout);

$rightPanel = new QWidget();
$rightLayout = new QVBoxLayout();
$rightLayout->addWidget(new QLabel('3) Connect'));
$rightLayout->addWidget(new FormFieldRow('UUID preset', $uuidPreset));
$rightLayout->addWidget(new FormFieldRow('Service UUID', $uuidInput));
$rightLayout->addLayout($connectButtons);
$rightLayout->addWidget(new FormFieldRow('Send text', $sendInput, 'Only works after connection is established.'));
$rightLayout->addWidget(new QLabel('Connection log'));
$rightLayout->addWidget($logOutput);
$rightPanel->setLayout($rightLayout);

$contentRow = new QHBoxLayout();
$contentRow->addWidget($leftPanel, 1);
$contentRow->addWidget($rightPanel, 1);

$shell->bodyLayout()->addWidget($banner);
$shell->bodyLayout()->addLayout($contentRow);
$shell->bodyLayout()->addWidget($status);

$root = new QVBoxLayout();
$root->setContentsMargins(20, 20, 20, 20);
$root->addWidget($shell);
$window->setLayout($root);

$localDevice = new QBluetoothLocalDevice();
$deviceAgent = new QBluetoothDeviceDiscoveryAgent();
$serviceAgent = new QBluetoothServiceDiscoveryAgent();
$scanStopTimer = new QTimer($window);
$scanStopTimer->setSingleShot(true);
$scanStopTimer->setInterval(12000);

/** @var list<QBluetoothDeviceInfo> $devices */
$devices = [];
/** @var array<string, int> $deviceRowByAddress */
$deviceRowByAddress = [];
/** @var list<QBluetoothServiceInfo> $services */
$services = [];
/** @var QBluetoothSocket|null $socket */
$socket = null;

$appendLog = static function (string $message) use ($logOutput): void {
    $logOutput->appendPlainText(sprintf('[%s] %s', date('H:i:s'), $message));
};

$refreshAdapterLabel = static function () use ($localDevice, $adapterLabel): void {
    if (!$localDevice->isValid()) {
        $adapterLabel->setText('Adapter: unavailable on this machine/session.');
        return;
    }

    $adapterLabel->setText(sprintf(
        'Adapter: %s | %s | %s',
        $localDevice->name(),
        $localDevice->address()->toString(),
        bt_host_mode_name($localDevice->hostMode()),
    ));
};

$setScanRunning = static function (bool $running) use ($scanButton, $stopScanButton): void {
    $scanButton->setEnabled(!$running);
    $stopScanButton->setEnabled($running);
};

$setServiceScanRunning = static function (bool $running) use ($discoverServicesButton, $stopServicesButton): void {
    $discoverServicesButton->setEnabled(!$running);
    $stopServicesButton->setEnabled($running);
};

$updateConnectButtons = static function () use (&$socket, $connectButton, $disconnectButton, $sendButton): void {
    $state = $socket instanceof QBluetoothSocket
        ? $socket->state()
        : QBluetoothSocket::UnconnectedState;

    $connected = $state === QBluetoothSocket::ConnectedState;
    $connecting = $state === QBluetoothSocket::ConnectingState
        || $state === QBluetoothSocket::ServiceLookupState;

    $connectButton->setEnabled(!$connected && !$connecting);
    $disconnectButton->setEnabled($connected || $connecting);
    $sendButton->setEnabled($connected);
};

$getSelectedDevice = static function () use (&$devices, $deviceList): ?QBluetoothDeviceInfo {
    $row = $deviceList->currentRow();
    if ($row < 0 || !isset($devices[$row])) {
        return null;
    }

    return $devices[$row];
};

$getSelectedService = static function () use (&$services, $serviceList): ?QBluetoothServiceInfo {
    $row = $serviceList->currentRow();
    if ($row < 0 || !isset($services[$row])) {
        return null;
    }

    return $services[$row];
};

$installSocket = static function (QBluetoothSocket $newSocket) use (
    &$socket,
    $appendLog,
    $banner,
    $status,
    $updateConnectButtons
): void {
    if ($socket instanceof QBluetoothSocket) {
        $socket->abort();
        $socket->close();
    }

    $socket = $newSocket;

    $socket->onConnected(static function () use ($appendLog, $banner, $status, $updateConnectButtons): void {
        $appendLog('Connected.');
        $banner->showInfo('Connected. You can send data now.');
        $status->info('Socket connected.');
        $updateConnectButtons();
    });

    $socket->onDisconnected(static function () use ($appendLog, $banner, $status, $updateConnectButtons): void {
        $appendLog('Disconnected.');
        $banner->showInfo('Disconnected.');
        $status->info('Socket disconnected.');
        $updateConnectButtons();
    });

    $socket->onStateChanged(static function (...$args) use ($appendLog): void {
        $state = null;
        foreach ($args as $arg) {
            if (is_int($arg)) {
                $state = $arg;
                break;
            }
        }

        if ($state !== null) {
            $appendLog('Socket state changed to: ' . bt_socket_state_name($state));
        }
    });

    $socket->onErrorOccurred(static function (...$args) use ($socket, $appendLog, $banner, $status, $updateConnectButtons): void {
        $errorCode = null;
        foreach ($args as $arg) {
            if (is_int($arg)) {
                $errorCode = $arg;
                break;
            }
        }

        $errorText = $socket->errorString();
        if ($errorCode !== null) {
            $appendLog(sprintf('Socket error (%d): %s', $errorCode, $errorText));
        } else {
            $appendLog('Socket error: ' . $errorText);
        }

        $banner->showError('Connection error: ' . $errorText);
        $status->info('Socket error occurred.');
        $updateConnectButtons();
    });

    $socket->onReadyRead(static function () use ($socket, $appendLog): void {
        $payload = $socket->readAll();
        if ($payload !== '') {
            $appendLog('RX: ' . $payload);
        }
    });

    $updateConnectButtons();
};

$uuidPreset->onCurrentTextChanged(static function () use ($uuidPreset, $uuidMap, $uuidInput): void {
    $name = $uuidPreset->currentText();
    $value = $uuidMap[$name] ?? QBluetoothUuid::SerialPort;
    $uuidInput->setText((new QBluetoothUuid($value))->toString());
});

$deviceList->onCurrentRowChanged(static function () use ($getSelectedDevice, $selectedDeviceLabel): void {
    $device = $getSelectedDevice();
    if ($device instanceof QBluetoothDeviceInfo) {
        $selectedDeviceLabel->setText('Selected device: ' . bt_device_label($device));
        return;
    }

    $selectedDeviceLabel->setText('Selected device: (none)');
});

$serviceList->onCurrentRowChanged(static function () use ($getSelectedService, $selectedServiceLabel, $uuidInput): void {
    $service = $getSelectedService();
    if ($service instanceof QBluetoothServiceInfo) {
        $selectedServiceLabel->setText('Selected service: ' . bt_service_label($service));
        $uuidInput->setText($service->serviceUuid()->toString());
        return;
    }

    $selectedServiceLabel->setText('Selected service: (none)');
});

$powerOnButton->onClicked(static function () use ($localDevice, $refreshAdapterLabel, $banner, $status, $appendLog): void {
    try {
        if (!$localDevice->isValid()) {
            throw new RuntimeException('No local Bluetooth adapter is available.');
        }

        $localDevice->powerOn();
        $localDevice->setHostMode(QBluetoothLocalDevice::HostDiscoverable);
        $refreshAdapterLabel();
        $appendLog('Local adapter powered on and set to discoverable mode.');
        $banner->showInfo('Adapter is on and discoverable.');
        $status->info('Local adapter initialized.');
    } catch (Throwable $throwable) {
        $banner->showError($throwable->getMessage());
    }
});

$scanButton->onClicked(static function () use (
    $deviceAgent,
    $serviceAgent,
    $scanStopTimer,
    $deviceList,
    $serviceList,
    &$devices,
    &$deviceRowByAddress,
    &$services,
    $setScanRunning,
    $setServiceScanRunning,
    $status,
    $banner,
    $appendLog
): void {
    try {
        if ($deviceAgent->isActive()) {
            $deviceAgent->stop();
        }
        if ($serviceAgent->isActive()) {
            $serviceAgent->stop();
        }

        $devices = [];
        $deviceRowByAddress = [];
        $services = [];
        $deviceList->clear();
        $serviceList->clear();

        $methods = QBluetoothDeviceDiscoveryAgent::supportedDiscoveryMethods();
        if ($methods === QBluetoothDeviceDiscoveryAgent::NoMethod) {
            throw new RuntimeException('This platform reports no supported discovery methods.');
        }

        $deviceAgent->start($methods);
        $scanStopTimer->start();
        $setScanRunning(true);
        $setServiceScanRunning(false);
        $appendLog('Starting discovery with methods: ' . bt_supported_methods_label($methods));
        $banner->showInfo('Scanning started.');
        $status->info('Scanning for nearby devices...');
    } catch (Throwable $throwable) {
        $setScanRunning(false);
        $banner->showError($throwable->getMessage());
    }
});

$stopScanButton->onClicked(static function () use ($deviceAgent, $scanStopTimer, $setScanRunning, $status, $appendLog, $banner): void {
    if ($deviceAgent->isActive()) {
        $deviceAgent->stop();
        $appendLog('Scan stopped by user.');
    }

    $scanStopTimer->stop();
    $setScanRunning(false);
    $status->info('Scan stopped.');
    $banner->clear();
});

$scanStopTimer->onTimeout(static function () use ($deviceAgent, $setScanRunning, $status, $appendLog): void {
    if ($deviceAgent->isActive()) {
        $deviceAgent->stop();
        $appendLog('Scan timeout reached.');
    }

    $setScanRunning(false);
    $status->info('Scan timeout reached (12 seconds).');
});

$deviceAgent->onDeviceDiscovered(static function (...$args) use (
    &$devices,
    &$deviceRowByAddress,
    $deviceList,
    $status,
    $appendLog
): void {
    $device = null;
    foreach ($args as $arg) {
        if ($arg instanceof QBluetoothDeviceInfo) {
            $device = $arg;
            break;
        }
    }

    if (!$device instanceof QBluetoothDeviceInfo) {
        return;
    }

    $address = $device->address()->toString();
    if (isset($deviceRowByAddress[$address])) {
        $row = $deviceRowByAddress[$address];
        $devices[$row] = $device;
        $item = $deviceList->item($row);
        $item->setText(bt_device_label($device));
        $status->info(sprintf('Updated device: %s', $address));
        return;
    }

    $devices[] = $device;
    $row = count($devices) - 1;
    $deviceRowByAddress[$address] = $row;
    $deviceList->addItem(bt_device_label($device));
    $status->info(sprintf('Discovered %d device(s).', count($devices)));
    $appendLog('Discovered: ' . bt_device_label($device));
});

$deviceAgent->onFinished(static function () use ($scanStopTimer, $setScanRunning, $status, $appendLog, $deviceList): void {
    $scanStopTimer->stop();
    $setScanRunning(false);
    $status->info(sprintf('Scan complete. %d device(s) listed.', $deviceList->count()));
    $appendLog('Device discovery finished.');
});

$deviceAgent->onCanceled(static function () use ($scanStopTimer, $setScanRunning, $status, $appendLog): void {
    $scanStopTimer->stop();
    $setScanRunning(false);
    $status->info('Device discovery canceled.');
    $appendLog('Device discovery canceled.');
});

$deviceAgent->onErrorOccurred(static function (...$args) use ($scanStopTimer, $deviceAgent, $setScanRunning, $banner, $status, $appendLog): void {
    $errorCode = null;
    foreach ($args as $arg) {
        if (is_int($arg)) {
            $errorCode = $arg;
            break;
        }
    }

    $scanStopTimer->stop();
    $setScanRunning(false);

    if ($errorCode !== null) {
        $appendLog(sprintf('Device discovery error (%d): %s', $errorCode, $deviceAgent->errorString()));
    } else {
        $appendLog('Device discovery error: ' . $deviceAgent->errorString());
    }

    $banner->showError('Discovery error: ' . $deviceAgent->errorString());
    $status->info('Discovery failed.');
});

$discoverServicesButton->onClicked(static function () use (
    $getSelectedDevice,
    $serviceAgent,
    &$services,
    $serviceList,
    $setServiceScanRunning,
    $banner,
    $status,
    $appendLog
): void {
    try {
        $device = $getSelectedDevice();
        if (!$device instanceof QBluetoothDeviceInfo) {
            throw new RuntimeException('Select a device first.');
        }

        if ($serviceAgent->isActive()) {
            $serviceAgent->stop();
        }

        $services = [];
        $serviceList->clear();
        $serviceAgent->clear();

        $ok = $serviceAgent->setRemoteAddress($device->address());
        if (!$ok) {
            throw new RuntimeException('Could not set selected device address for service discovery.');
        }

        $serviceAgent->start(QBluetoothServiceDiscoveryAgent::FullDiscovery);
        $setServiceScanRunning(true);
        $appendLog('Service discovery started for ' . $device->address()->toString());
        $banner->showInfo('Discovering services for selected device...');
        $status->info('Service discovery in progress...');
    } catch (Throwable $throwable) {
        $setServiceScanRunning(false);
        $banner->showError($throwable->getMessage());
    }
});

$stopServicesButton->onClicked(static function () use ($serviceAgent, $setServiceScanRunning, $status, $appendLog): void {
    if ($serviceAgent->isActive()) {
        $serviceAgent->stop();
        $appendLog('Service discovery stopped by user.');
    }

    $setServiceScanRunning(false);
    $status->info('Service discovery stopped.');
});

$serviceAgent->onServiceDiscovered(static function (...$args) use (&$services, $serviceList, $status, $appendLog): void {
    $service = null;
    foreach ($args as $arg) {
        if ($arg instanceof QBluetoothServiceInfo) {
            $service = $arg;
            break;
        }
    }

    if (!$service instanceof QBluetoothServiceInfo) {
        return;
    }

    $services[] = $service;
    $serviceList->addItem(bt_service_label($service));
    $status->info(sprintf('Discovered %d service(s).', count($services)));
    $appendLog('Service discovered: ' . bt_service_label($service));
});

$serviceAgent->onFinished(static function () use ($setServiceScanRunning, $serviceList, $status, $appendLog): void {
    $setServiceScanRunning(false);
    $status->info(sprintf('Service discovery complete. %d service(s) listed.', $serviceList->count()));
    $appendLog('Service discovery finished.');
});

$serviceAgent->onCanceled(static function () use ($setServiceScanRunning, $status, $appendLog): void {
    $setServiceScanRunning(false);
    $status->info('Service discovery canceled.');
    $appendLog('Service discovery canceled.');
});

$serviceAgent->onErrorOccurred(static function (...$args) use ($serviceAgent, $setServiceScanRunning, $banner, $status, $appendLog): void {
    $errorCode = null;
    foreach ($args as $arg) {
        if (is_int($arg)) {
            $errorCode = $arg;
            break;
        }
    }

    $setServiceScanRunning(false);
    if ($errorCode !== null) {
        $appendLog(sprintf('Service discovery error (%d): %s', $errorCode, $serviceAgent->errorString()));
    } else {
        $appendLog('Service discovery error: ' . $serviceAgent->errorString());
    }

    $banner->showError('Service discovery error: ' . $serviceAgent->errorString());
    $status->info('Service discovery failed.');
});

$connectButton->onClicked(static function () use (
    $getSelectedDevice,
    $getSelectedService,
    $uuidInput,
    $uuidPreset,
    $uuidMap,
    $window,
    $installSocket,
    $banner,
    $status,
    $appendLog
): void {
    try {
        $device = $getSelectedDevice();
        if (!$device instanceof QBluetoothDeviceInfo) {
            throw new RuntimeException('Select a device before connecting.');
        }

        $service = $getSelectedService();
        $uuidText = trim($uuidInput->text());

        if ($uuidText === '' && $service instanceof QBluetoothServiceInfo) {
            $uuidText = $service->serviceUuid()->toString();
        }

        if ($uuidText === '') {
            $presetName = $uuidPreset->currentText();
            $uuidValue = $uuidMap[$presetName] ?? QBluetoothUuid::SerialPort;
            $uuid = new QBluetoothUuid($uuidValue);
            $uuidText = $uuid->toString();
            $uuidInput->setText($uuidText);
        } else {
            $uuid = new QBluetoothUuid($uuidText);
        }

        $socket = new QBluetoothSocket(QBluetoothServiceInfo::RfcommProtocol, $window);
        $installSocket($socket);

        $appendLog(sprintf(
            'Connecting to %s with UUID %s',
            $device->address()->toString(),
            $uuid->toString(),
        ));

        $socket->connectToService($device->address(), $uuid, QIODeviceBase::ReadWrite);
        $banner->showInfo('Connection attempt started.');
        $status->info('Connecting...');
    } catch (Throwable $throwable) {
        $banner->showError($throwable->getMessage());
        $status->info('Connection attempt failed to start.');
    }
});

$disconnectButton->onClicked(static function () use (&$socket, $updateConnectButtons, $banner, $status, $appendLog): void {
    if ($socket instanceof QBluetoothSocket) {
        $socket->disconnectFromService();
        $socket->close();
        $appendLog('Disconnect requested by user.');
    }

    $updateConnectButtons();
    $banner->showInfo('Disconnected.');
    $status->info('Disconnected.');
});

$sendButton->onClicked(static function () use (&$socket, $sendInput, $banner, $status, $appendLog): void {
    try {
        if (!$socket instanceof QBluetoothSocket || $socket->state() !== QBluetoothSocket::ConnectedState) {
            throw new RuntimeException('Socket is not connected.');
        }

        $payload = $sendInput->text();
        if ($payload === '') {
            throw new RuntimeException('Enter text to send.');
        }

        $written = $socket->write($payload);
        if ($written <= 0) {
            throw new RuntimeException('No bytes were written to the socket.');
        }

        $appendLog(sprintf('TX: %s (%d byte(s))', $payload, $written));
        $status->info(sprintf('Sent %d byte(s).', $written));
        $banner->clear();
    } catch (Throwable $throwable) {
        $banner->showError($throwable->getMessage());
    }
});

$refreshAdapterLabel();
$appendLog('Start with "Power On Adapter", then scan and select a device.');
$appendLog('If a service is listed, select it to auto-fill UUID before connecting.');
$status->info('Ready.');

$window->show();
example_line('bluetooth scan/connect demo ready');
QApplication::exec();
