# Examples

These examples are fuller, more product-like applications than the low-level `manual-tests/` runtime checks.

## Recommended build

```sh
php qtb build QtCore,QtGui,QtWidgets,QtQml,QtQuick,QtOpenGL,QtOpenGLWidgets,Qt3DCore,Qt3DRender,Qt3DExtras,Qt3DInput
```

For Bluetooth examples, include `QtBluetooth` as well.

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
- `log-viewer`
  A tail-like log monitor with severity filtering, keyword search, and pause/resume polling.
- `file-organizer`
  A lightweight file browser with favorites, text preview, and safe bulk rename workflows.
- `calculator-basic`
  A macOS Calculator-inspired basic mode clone with dedicated styling and standard arithmetic behavior.
- `depth-playground`
  A 2.5D visual demo with layered cards, animated parallax drift, and depth-reactive shadows.
- `opengl-shader-playground`
  A widget-based OpenGL shader playground with live controls, animated uniforms, and native GL rendering from PHP.
- `obj-browser`
  A widget-based OBJ + MTL + texture viewer with orbit camera controls, file loading, and native OpenGL rendering from PHP.
- `qt3d-scene-loader`
  A low-level Qt3D window that loads a scene with `QSceneLoader`, builds a manual frame graph, and supports orbit + zoom camera controls.
- `qt3d-modules-showcase`
  A focused Qt3D integration baseline that wires Qt3DCore + Qt3DRender + Qt3DExtras + Qt3DInput in one scene graph.
- `true-3d`
  A native QtQuick3D scene rendered from PHP via inline QML, with animated meshes, camera, and lighting.
- `gpu-stress`
  An interactive QtQuick3D stress scene with presets, camera controls, dynamic instancing, and FPS HUD.
- `bluetooth-scan-connect`
  An easy Bluetooth demo to scan nearby devices, inspect services, and attempt a socket connection.
- `threaded-downloader`
  A range-based downloader using `QThread` task mode and `QFuture` workers for per-part downloads and a final merge worker.

## Data model

Each example stores its writable data inside its own folder. Nothing is written to user-home directories.
