# Qt3D Modules Showcase

A focused Qt3D demo that intentionally wires the core class families used across Qt3D wrappers:

- `Qt3DCore`: `QAspectEngine`, `QEntity`
- `Qt3DRender`: `QRenderSettings`, frame-graph nodes, `QCamera`
- `Qt3DExtras`: `QOrbitCameraController`
- `Qt3DInput`: `QInputAspect`, `QInputSettings`, `QLogicalDevice`, `QAction`, `QAxis`

This example is meant as a stable baseline to verify module integration and startup/teardown behavior.

## Required build

```sh
php qtb build QtCore,QtGui,Qt3DCore,Qt3DRender,Qt3DExtras,Qt3DInput
```

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/qt3d-modules-showcase/run.php
```

Optional auto-exit for scripted checks:

```sh
QT_EXAMPLE_AUTO_QUIT_SECONDS=2 php -dextension=$PWD/build/ext/.libs/qt.so examples/qt3d-modules-showcase/run.php
```
