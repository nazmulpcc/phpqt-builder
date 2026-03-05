# gpu-stress

An interactive QtQuick3D stress scene intended to push rendering harder than basic shape demos.

## What it shows

- Hundreds to thousands of animated 3D instances
- Dynamic lighting and physically based materials
- Runtime stress presets and controls
- Live FPS + frame-time HUD

## Controls

- `1`: Low preset
- `2`: Medium preset
- `3`: High preset
- `4`: Extreme preset
- `[` / `]`: decrease/increase instance count
- `-` / `=`: decrease/increase animation speed
- `S`: toggle shadows
- `R`: reset camera

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/gpu-stress/run.php
```

Optional auto quit:

```sh
QT_EXAMPLE_AUTO_QUIT_SECONDS=5 php -dextension=$PWD/build/ext/.libs/qt.so examples/gpu-stress/run.php
```
