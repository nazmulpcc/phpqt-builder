# PHP Qt Builder

> Build real desktop applications with PHP.

If you know PHP and have wanted to build native GUI apps, this tool is for you. PHP Qt Builder generates a PHP extension that wraps Qt 6 — the same framework behind KDE, Autodesk, and countless cross-platform applications. You write PHP. It runs as a native desktop app.

- **Write PHP** — familiar syntax, no C++ required
- **Build native UIs** — Qt Widgets, Qt Quick, OpenGL, 3D, Bluetooth, and more
- **Compile once** — the builder generates and builds the extension for you
- **Linux & macOS ready** — primary development platforms; Windows and iOS have additional constraints

### What works today

Linux and macOS are the main happy path: clone, install dependencies, run `php qtb build`, and you have a working Qt extension. Windows header inspection works but the extension bootstrap path is not yet implemented. iOS builds are possible via static staging into php-src but require extra setup.

---

## Table of Contents

- [Quick Start](#quick-start)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Supported Qt Modules](#supported-qt-modules)
- [Examples](#examples)
- [Architecture](#architecture)
- [Threading](#threading)
- [Platform Support](#platform-support)
- [Version Compatibility](#version-compatibility)
- [Related Projects](#related-projects)
- [License](#license)

---

## Requirements

### Critical

| Requirement | Version / Note |
|-------------|----------------|
| PHP | `^8.4` |
| `ext-cparser` | [php-cparser](https://github.com/nazmulpcc/php-cparser) — PHP extension wrapping libclang |
| Qt | 6.x (latest tested LTS recommended) |
| C++ compiler | `c++`, `g++`, or `clang++` with C++17 support |
| `phpize` | From PHP development headers |
| Build tool | `make` |

### Recommended

- `pkg-config` — for automatic Qt6 link flags
- `ccache` — speeds up rebuilds

### For Threading Features

Threading support (QThread workers, cross-thread signals, QFuture/QPromise) requires a **ZTS (Zend Thread Safety)** build of PHP. On NTS builds, the extension compiles and all non-threading APIs work normally.

---

## Installation

```bash
# Clone the builder
git clone https://github.com/nazmulpcc/phpqt-builder.git
cd phpqt-builder

# Install PHP dependencies
composer install

# Verify your environment
php qtb doctor
```

### Installing `ext-cparser`

`ext-cparser` is a hard dependency. On Linux/macOS/Windows, build it from source:

```bash
git clone https://github.com/nazmulpcc/php-cparser.git
cd php-cparser
phpize && ./configure && make && sudo make install
# Then add `extension=cparser.so` to your php.ini
```

See [`cparser.stub.php`](cparser.stub.php) in this repo for the complete API reference.

### First successful run

After installing, verify everything works with the smallest possible build:

```bash
php qtb doctor
php qtb build QtCore,QtGui,QtWidgets
php qtb example calculator-basic
```

---

## Usage

### `doctor` — Check your environment

```bash
php qtb doctor              # Human-readable report
php qtb doctor --format=json # Machine-readable output
```

### `inspect` — Explore a Qt class

```bash
php qtb inspect QWidget --qt-path /path/to/qt
```

Dumps properties, methods, access specifiers, and signals in human or JSON format.

### `build` — Full multi-module extension build

```bash
# Interactive module selection
php qtb build

# Or specify modules explicitly
php qtb build QtCore,QtGui,QtWidgets,QtQml,QtQuick

# Force clean rebuild with parallel jobs
php qtb build QtCore,QtGui,QtWidgets --force --jobs=8

# Generate sources only (skip phpize/configure/make), default in Windows
php qtb build QtCore,QtGui,QtWidgets --no-build
```

`build` handles the entire pipeline:
1. Discovers Qt headers and candidate classes
2. Resolves inter-module dependencies from [`manifest.json`](manifest.json)
3. Extracts enums, flags, and nested types
4. Generates C++ wrappers for all viable classes
5. Scaffolds `config.m4`, `php_compat.h`, and support files
6. Runs `phpize`, `configure`, and `make`

### `example` — Run example applications

```bash
# List available examples
php qtb example --list

# Run a specific example
php qtb example calculator-basic
```

---

## Supported Qt Modules

PHP Qt Builder currently supports **37 Qt 6 modules**. The build system automatically resolves inter-module dependencies.

| Module | Extension | Dependencies |
|--------|-----------|--------------|
| `QtCore` | `qtcore` | — |
| `QtGui` | `qtgui` | QtCore |
| `QtWidgets` | `qtwidgets` | QtCore, QtGui |
| `QtTest` | `qttest` | QtCore, QtGui, QtWidgets |
| `QtNetwork` | `qtnetwork` | QtCore |
| `QtBluetooth` | `qtbluetooth` | QtCore, QtNetwork |
| `QtSql` | `qtsql` | QtCore |
| `QtPrintSupport` | `qtprintsupport` | QtCore, QtGui, QtWidgets |
| `QtMultimedia` | `qtmultimedia` | QtCore, QtGui, QtNetwork |
| `QtOpenGL` | `qtopengl` | QtCore, QtGui |
| `QtOpenGLWidgets` | `qtopenglwidgets` | QtCore, QtGui, QtOpenGL, QtWidgets |
| `Qt3DCore` | `qt3dcore` | QtCore, QtGui |
| `Qt3DRender` | `qt3drender` | QtCore, QtGui, Qt3DCore |
| `Qt3DInput` | `qt3dinput` | QtCore, QtGui, Qt3DCore |
| `Qt3DExtras` | `qt3dextras` | QtCore, QtGui, Qt3DCore, Qt3DRender, Qt3DInput |
| `Qt3DAnimation` | `qt3danimation` | QtCore, QtGui, Qt3DCore, Qt3DRender, Qt3DExtras |
| `QtQml` | `qtqml` | QtCore, QtNetwork |
| `QtQuick` | `qtquick` | QtCore, QtGui, QtQml |
| `QtQuickControls2` | `qtquickcontrols2` | QtCore, QtGui, QtQuick |
| `QtQuick3D` | `qtquick3d` | QtCore, QtGui, QtQml, QtQuick |
| `QtWebSockets` | `qtwebsockets` | QtCore, QtNetwork |
| `QtWebChannel` | `qtwebchannel` | QtQml |
| `QtWebEngineCore` | `qtwebenginecore` | QtCore, QtGui, QtNetwork, QtQuick, QtWebChannel |
| `QtWebEngineQuick` | `qtwebenginequick` | QtCore, QtGui, QtQml, QtQuick, QtWebEngineCore |
| `QtWebEngineWidgets` | `qtwebenginewidgets` | QtCore, QtGui, QtWidgets, QtWebEngineCore, QtPrintSupport |
| `QtXml` | `qtxml` | QtCore |
| `QtSvg` | `qtsvg` | QtCore, QtGui |
| `QtSvgWidgets` | `qtsvgwidgets` | QtCore, QtGui, QtWidgets |
| `QtStateMachine` | `qtstatemachine` | QtCore, QtGui |
| `QtMultimediaWidgets` | `qtmultimediawidgets` | QtCore, QtGui, QtMultimedia, QtWidgets |
| `QtSerialPort` | `qtserialport` | QtCore |
| `QtTextToSpeech` | `qttexttospeech` | QtCore |
| `QtNetworkAuth` | `qtnetworkauth` | QtCore, QtNetwork |
| `QtHttpServer` | `qthttpserver` | QtCore, QtNetwork, QtWebSockets |
| `QtPdf` | `qtpdf` | QtCore, QtGui |
| `QtPdfWidgets` | `qtpdfwidgets` | QtCore, QtGui, QtWidgets, QtPdf |
| `QtCharts` | `qtcharts` | QtCore, QtGui, QtWidgets |

Other Qt modules may work but have not been tested yet.

---

## Examples

The [`examples/`](examples/) directory contains full product-like applications demonstrating different Qt APIs from PHP. Build only the modules you need for the example you want to run.

### Recommended first examples

These use only QtCore, QtGui, and QtWidgets — the fastest build path.

| Example | Modules | What it shows |
|---------|---------|---------------|
| `calculator-basic` | QtWidgets | macOS-style calculator with styling and keyboard shortcuts |
| `markdown-notes` | QtWidgets | Three-pane notes app with live preview and autosave |
| `login-form` | QtWidgets | Validation, remember-me, inline banners |
| `settings-editor` | QtWidgets | Preferences window with tabs, dirty state, and JSON persistence |

```bash
php qtb build QtCore,QtGui,QtWidgets
php qtb example calculator-basic
```

### Specialized demos

These require additional Qt modules. Build only what the example needs.

| Example | Modules | What it shows |
|---------|---------|---------------|
| `csv-viewer` | QtWidgets | Filtering, pagination, and export |
| `log-viewer` | QtWidgets | Tail-like monitor with severity filtering and search |
| `file-organizer` | QtWidgets | File browser with favorites and bulk rename |
| `depth-playground` | QtQuick | 2.5D parallax cards and animated shadows |
| `opengl-shader-playground` | QtOpenGL, QtOpenGLWidgets | Live shader editing with native GL from PHP |
| `obj-browser` | QtOpenGL, QtOpenGLWidgets | OBJ + MTL + texture viewer with orbit camera |
| `qt3d-scene-loader` | Qt3DCore, Qt3DRender, Qt3DExtras | QSceneLoader with manual frame graph |
| `qt3d-modules-showcase` | Qt3DCore, Qt3DRender, Qt3DExtras, Qt3DInput | Full Qt3D integration baseline |
| `gpu-stress` | QtQuick3D | Stress scene with dynamic instancing and FPS HUD |
| `bluetooth-scan-connect` | QtBluetooth | Scan, inspect services, and connect |
| `multithread-downloader` | QtCore (ZTS) | QThread workers with publish/on events |

> **Threading note:** `multithread-downloader` requires a ZTS PHP build. All other examples work on both ZTS and NTS.

---

## Architecture

You don't need to understand the internals to build apps — `php qtb build` handles everything. This section explains how it works under the hood.

PHP Qt Builder is a code generator, not a hand-written binding. This makes it maintainable across Qt versions.

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Qt C++ Headers │────▶│  ext-cparser     │────▶│  PhpClass IR    │
│  (e.g. QWidget) │     │  (libclang AST)  │     │  (Definition/)  │
└─────────────────┘     └──────────────────┘     └─────────────────┘
                                                          │
                                                          ▼
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Loadable       │◀────│  phpize / make   │◀────│  Blade Templates│
│  PHP Extension  │     │  (C++17)         │     │  (templates/)   │
│  (qt.so)        │     │                  │     │  .h / .cpp      │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

### Three-Stage Pipeline

1. **Inspect** (`qtb inspect`) — Parse a Qt header with `ext-cparser` and dump raw class metadata.
2. **Define** (`qtb define`) — Transform raw metadata into a PHP-facing intermediate representation with merged overloads and mapped types.
3. **Generate** (`qtb generate`) — Render Blade templates into `.h`, `.cpp`, and `.stub.php` files.

The `build` command runs the full pipeline at scale: parallel discovery workers, cached class structures, enum extraction, dependency graph resolution, and incremental file writing.

### What Gets Generated

For each Qt class, the builder emits:

- **`.h`** — Object struct, `zend_class_entry` externs, `Z_*_P` macros, `PHP_MINIT_FUNCTION`
- **`.cpp`** — `create_object`, `free_obj`, method implementations, constructor dispatch, signal binding
- **`.stub.php`** — PHP signatures for `gen_stub.php` to produce `_arginfo.h`

Shared support files are generated once per build:

- Signal helpers (`QPhpSignalConnection`, `QMetaObjectConnection`)
- Thread runtime (`QThreadRuntime`, `QFuture`, `QPromise`) — ZTS only
- Enum holders for namespace-owned enums
- Ownership and lifecycle helpers

---

## Threading

PHP Qt Builder provides first-class threading support when running on a **ZTS PHP build**:

- **QThread task mode** — Start a worker with a PHP callable that runs in a dedicated QThread
- **Cross-thread signals** — Connect PHP callables to signals that may fire from any thread
- **Event publishing** — `QThread::publish()` / `receive()` for thread-safe message passing
- **QFuture / QPromise** — Async result patterns from background tasks
- **Moved objects** — Safely move QObject instances between threads

On NTS builds, all Qt APIs work except the threading-specific extensions listed above.

---

## Platform Support

| Platform | Status | Notes |
|----------|--------|-------|
| **Linux** | Fully supported | Primary development and CI platform |
| **macOS** | Fully supported | Framework linking, x86_64 and Apple Silicon |
| **Windows** | Partial | Header inspection works; extension bootstrap (`phpize`/`configure`/`make`) path is Unix-oriented and not yet implemented |
| **iOS** | Tested | Via static staging (`--stage-static-to`) into php-src |

Platform-specific build logic is handled automatically by the generated `config.m4` (Unix) and `config.w32` (Windows) templates.

---

## Version Compatibility

| Component | Required | Notes |
|-----------|----------|-------|
| PHP | `^8.4` | Tested on 8.4; 8.3 may work but is not guaranteed |
| Qt | 6.x | Latest tested LTS recommended |
| C++ standard | C++17 | Enforced by generated `config.m4` / `config.w32` |
| `ext-cparser` | Latest | See [nazmulpcc/php-c-parser](https://github.com/nazmulpcc/php-c-parser) |

---

## Related Projects

- **[php-c-parser](https://github.com/nazmulpcc/php-c-parser)** — The `ext-cparser` extension this project depends on
- **[phpqt](https://github.com/nazmulpcc/phpqt)** — Earlier proof-of-concept using PHP-CPP (archived)
- **[Shiboken](https://doc.qt.io/qtforpython-6/shiboken6/)** — Qt's official generator for Python bindings (similar architecture)

---

## License

[MIT License](/LICENSE)

---

*Built with PHP, Qt 6, libclang, and a lot of templates.*
