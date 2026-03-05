# depth-playground

A stylized 2.5D demo scene using Qt Widgets (no Qt3D/Quick3D).

## What it shows

- Layered overlapping cards with distinct depth levels
- Animated drift/parallax motion per layer
- Dynamic soft shadows driven by depth
- Runtime intensity toggle (`Pulse Focus` vs `Calm Drift`)

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/depth-playground/run.php
```
