# bluetooth-scan-connect

Beginner-friendly Bluetooth demo for real device discovery and connection attempts.

What it does:

- Powers on local adapter (when available)
- Scans nearby devices
- Lets you inspect services for a selected device
- Connects with `QBluetoothSocket` using selected or typed UUID
- Allows sending a text payload after connection

## Build requirements

Build with at least these modules:

```sh
php qtb build QtCore,QtGui,QtWidgets,QtBluetooth
```

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/bluetooth-scan-connect/run.php
```

Optional:

```sh
php qtb example bluetooth-scan-connect
```

## Basic flow

1. Click `Power On Adapter`.
2. Click `Scan Nearby Devices` and wait for entries.
3. Select a device.
4. Optional: click `Discover Services` and pick a service (UUID auto-fills).
5. Click `Connect`.
6. If connected, type text and click `Send`.

## Notes

- Results depend on host Bluetooth permissions and nearby device availability.
- Some devices expose no RFCOMM service; connection can still fail even after discovery.
- This is a demo utility, not a full production pairing/client workflow.
