# Examples

These examples are fuller, more product-like applications than the low-level `manual-tests/` runtime checks.

## Recommended build

```sh
php qtb build --modules=QtCore,QtGui,QtWidgets,QtQml,QtQuick -F
```

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/<example>/run.php
```

## Included examples

- `login-form`
  A polished desktop sign-in screen with validation, remember-me persistence, and inline banners.
- `settings-editor`
  A settings/preferences window with tabs, live dirty state, save/reset/defaults, and JSON persistence.
- `markdown-notes`
  A notes app with sidebar, editor, preview pane, and autosave to repo-local markdown files.
- `csv-viewer`
  A CSV utility with filtering, pagination, and export of filtered rows.

## Data model

Each example stores its writable data inside its own folder. Nothing is written to user-home directories.
