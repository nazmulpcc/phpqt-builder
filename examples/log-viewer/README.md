# Log Viewer

A practical tail-style log monitor with severity filtering, keyword search, and pause/resume polling.

## Modules

- `QtCore`
- `QtGui`
- `QtWidgets`

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/log-viewer/run.php
```

## Data

- seed log: `examples/log-viewer/data/sample.log`
- live runtime log: `examples/log-viewer/runtime/live.log`

## Notes

- Auto-refresh uses timer polling every 500ms.
- A built-in simulator appends demo lines so activity is visible immediately.
