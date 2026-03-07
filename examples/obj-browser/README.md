# OBJ Browser

A widget-based OpenGL OBJ viewer rendered from PHP with `QOpenGLWidget`, `QOpenGLShaderProgram`, `QOpenGLBuffer`, `QOpenGLVertexArrayObject`, and `QOpenGLTexture`.

The example starts with a bundled textured OBJ asset and also lets you load your own `.obj` files with matching `.mtl` and diffuse texture maps.

## Required build

```sh
php qtb build QtCore,QtGui,QtWidgets,QtOpenGL,QtOpenGLWidgets
```

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/obj-browser/run.php
```

## Controls

- `Open...`: pick a Wavefront OBJ file
- `Reset View`: restore the default fitted orbit camera
- drag on canvas: orbit
- mouse wheel: zoom

## What it demonstrates

- `QOpenGLWidget` subclassing from PHP
- OBJ parsing with fan triangulation and generated normals
- MTL parsing for `Kd`, `d` / `Tr`, and `map_Kd`
- textured OpenGL mesh rendering with a simple lit shader
- Qt Widgets + file dialog + native OpenGL integration in one window

## Smoke mode

Set `QT_EXAMPLE_AUTO_QUIT_SECONDS` to have the example launch and exit automatically:

```sh
QT_EXAMPLE_AUTO_QUIT_SECONDS=2 php -dextension=$PWD/build/ext/.libs/qt.so examples/obj-browser/run.php
```
