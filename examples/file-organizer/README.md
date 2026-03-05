# File Organizer

A lightweight file browser/organizer with favorites, preview pane, and safe bulk rename workflows.

## Modules

- `QtCore`
- `QtGui`
- `QtWidgets`

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/file-organizer/run.php
```

## Data

- favorites store: `examples/file-organizer/data/favorites.json`
- workspace seed template: `examples/file-organizer/data/workspace-template/`
- runtime workspace: `examples/file-organizer/runtime/workspace/`

## Bulk Rename Modes

- `Selected Items`: rename only selected files in the tree.
- `Current Folder Filter`: rename files from the active folder filtered by the search query.
