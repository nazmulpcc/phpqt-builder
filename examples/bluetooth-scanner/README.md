# Bluetooth Scanner

A small runtime validation demo for `QtBluetooth` that:

- lists local Bluetooth adapters
- starts device discovery via `QBluetoothDeviceDiscoveryAgent`
- reports discovered devices in real time through signal callbacks
- exits cleanly on finish/error/timeout

## Required build

```sh
php qtb build QtCore,QtNetwork,QtBluetooth
```

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/bluetooth-scanner/run.php
```

Optional scan timeout override (milliseconds):

```sh
QT_BLUETOOTH_SCAN_TIMEOUT_MS=10000 php -dextension=$PWD/build/ext/.libs/qt.so examples/bluetooth-scanner/run.php
```
