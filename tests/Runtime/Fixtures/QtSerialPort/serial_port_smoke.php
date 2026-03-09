<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\SerialPort\QSerialPort;
use Qt\SerialPort\QSerialPortInfo;

qt_runtime_require_class(QSerialPort::class, 'QtSerialPort classes are unavailable in this build.');

$baudRates = QSerialPortInfo::standardBaudRates();

$port = new QSerialPort();
$port->setPortName('ttyUSB0');
$port->setBaudRate(QSerialPort::Baud9600);

qt_runtime_result([
    'has_standard_baud_rates' => count($baudRates) > 0,
    'has_9600'                => in_array(9600, $baudRates, true),
    'has_115200'              => in_array(115200, $baudRates, true),
    'port_name'               => $port->portName(),
    'baud_rate'               => $port->baudRate(),
    'is_open'                 => $port->isOpen(),
]);
