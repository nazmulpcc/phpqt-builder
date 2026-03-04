# Markdown Notes

A small three-pane notes app with autosave and repo-local markdown files.

## Modules

- `QtCore`
- `QtGui`
- `QtWidgets`

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/markdown-notes/run.php
```

## Data

- notes live in `examples/markdown-notes/data/notes`
- autosave writes back to the same files
