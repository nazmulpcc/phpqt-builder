# CSV Viewer

A QML-powered CSV viewer using PHP Signal/Slot attributes for bidirectional communication between a PHP backend and a modern QML frontend.

## What it shows

- `#[Signal]` / `#[Slot]` PHP attributes driving a QML UI
- `QQmlEngine` + `setContextProperty` exposing a PHP `QObject` to QML
- `Connections` in QML listening to PHP signals
- Dark-themed table with search, pagination, status badges, and export

## Modules

- `QtCore`
- `QtGui`
- `QtQml`

## Run

```sh
DYLD_FRAMEWORK_PATH=/opt/homebrew/Frameworks php -dextension=build/ext/.libs/qt.so examples/csv-viewer/run.php
```

## Data

- bundled CSV: `examples/csv-viewer/data/sample-sales.csv`
- filtered exports: `examples/csv-viewer/runtime/exports/`
