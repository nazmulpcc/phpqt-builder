# OpenGL Shader Playground

A widget-based OpenGL demo rendered from PHP with `QOpenGLWidget`, `QOpenGLShaderProgram`, `QOpenGLBuffer`, and `QOpenGLVertexArrayObject`.

The scene is a fullscreen fragment shader on a quad, with live uniforms driven by Qt Widgets controls and mouse motion.

Shader sources live in sibling files:

- `shader.vert`
- `shader.frag`

## Required build

```sh
php qtb build QtCore,QtGui,QtWidgets,QtOpenGL,QtOpenGLWidgets
```

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/opengl-shader-playground/run.php
```

## Controls

- `Intensity`: boosts motion and glow energy
- `Scale`: zooms the field structure in and out
- `Hue Shift`: rotates the palette balance
- `Neon Pulse`: higher-energy preset
- `Calm Drift`: softer ambient preset

## What it demonstrates

- `QOpenGLWidget` subclassing from PHP
- OpenGL context initialization and conservative format setup
- shader compile/link via `QOpenGLShaderProgram`
- vertex-buffer upload via `QOpenGLBuffer`
- optional VAO setup via `QOpenGLVertexArrayObject`
- uniform updates every frame
- Qt Widgets + OpenGL integration in one window

## Smoke mode

Set `QT_EXAMPLE_AUTO_QUIT_SECONDS` to have the example launch and exit automatically:

```sh
QT_EXAMPLE_AUTO_QUIT_SECONDS=2 php -dextension=$PWD/build/ext/.libs/qt.so examples/opengl-shader-playground/run.php
```
