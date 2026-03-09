# Qt3D Scene Loader

A low-level Qt3D demo rendered from PHP with `QAspectEngine`, `QRenderSettings`, a manual frame graph, `QCamera`, `QSceneLoader`, plus input/camera-control helpers.

It uses a plain `QWindow` (no `QtWidgets`) and integrates:

- `Qt3DCore`
- `Qt3DRender`
- `Qt3DExtras` (`QOrbitCameraController`)
- `Qt3DInput` (`QInputAspect`, `QInputSettings`)

## Required build

```sh
php qtb build QtCore,QtGui,Qt3DCore,Qt3DRender,Qt3DExtras,Qt3DInput
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
