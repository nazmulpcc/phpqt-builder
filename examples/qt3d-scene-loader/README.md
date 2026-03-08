# Qt3D Scene Loader

A minimal low-level Qt3D demo rendered from PHP with `QAspectEngine`, `QRenderSettings`, a manual frame graph, `QCamera`, and `QSceneLoader`.

This example intentionally avoids `QtWidgets` and `Qt3DExtras`. It uses a plain `QWindow` so it stays close to the currently proven wrapper surface for `Qt3DCore` and `Qt3DRender`.

## Required build

```sh
php qtb build QtCore,QtGui,Qt3DCore,Qt3DRender
```

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/qt3d-scene-loader/run.php
```

You can also point it at a custom scene file:

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/qt3d-scene-loader/run.php /path/to/model.obj
```

By default it reuses the bundled OBJ asset from the `obj-browser` example:

- `/Users/nazmul/lab/phpqt-builder-codex/examples/obj-browser/sample.obj`

## Controls

- Left-drag: orbit camera
- Mouse wheel: zoom

## Notes

- The window title reflects loader state so failures are visible even without widget chrome.
- `QSceneLoader` support depends on the Qt scene import plugins available on the local machine.
