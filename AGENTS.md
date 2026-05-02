# Repository Guidelines

## Project Overview

phpqt-builder parses Qt 6 C++ headers via `ext-cparser` (libclang), builds an intermediate representation (IR), and generates a PHP extension (`.h`, `.cpp`, `.stub.php`) that wraps Qt classes for PHP. It supports 36 Qt modules and can produce either a single monolithic extension or per-module split extensions.

## Project Structure

```
qtb                          CLI entrypoint (Symfony Console)
src/
  Commands/                  9 console commands (doctor, inspect, define, generate, build, build:info, build:modules, build:discover, example)
  Parsing/                   ext-cparser header inspection and type mapping
    QtClassInspector         Raw C++ AST extraction via libclang
    ClassDefinitionBuilder   Transforms raw AST data into PhpClass IR
    CppToPhpTypeMapper       C++ type -> PHP type mapping
    ClangArgumentBuilder     Compiler flags for cparser translation units
    ContainerTypeParser      QList/QMap/QHash template type parsing
  Definition/                Intermediate representation (IR) layer
    PhpClass, PhpMethod, PhpParameter, PhpProperty,
    MethodOverload, OverloadParameter, PhpClassConstant, ContainerType
  CodeGen/                   Template context objects and extension generator
    ExtensionGenerator       Orchestrates Blade template rendering
    ClassContext              Master template context (naming, type classification, method contexts)
    MethodContext             Per-method ZPP patterns, overload dispatch, call plans
    TypeBridge                PHP<->C++ type mapping for Zend C API constructs
    ContainerBridge           Container type argument/return marshalling
    ParamContext, OverloadContext, OverloadParamContext, PropertyContext,
    SignalOverloadContext, EnumHolderContext
  Build/                     Full multi-module build pipeline (43 files)
    BuildPipeline             Orchestrates: analyze -> emit -> scaffold -> bootstrap
    BuildDiscoveryService     Parallel class discovery with acceptance filtering
    ClassGenerationService    Per-class generation (probe, facts, generate)
    FixedPointEngine          Iterative fixed-point generation until stable
    ExtensionScaffolder       Extension-level files (config.m4, ext .h/.cpp)
    ExtensionBootstrapper     Runs phpize/configure/make
    GenerateWorkerPool        Parallel worker management
    ModuleBuildGraph          Module dependency graph resolution
    Dependencies/             ModuleDependencyResolver, StaticModuleDependencyResolver
  Filtering/                 Class and method exposure policies
  IO/                        SmartFileWriter (atomic writes with content comparison)
  Containers/                QList specialization handling
  Scanning/                  Module header scanning for candidate classes
  Qt/                        Qt installation discovery and version detection
  Preflight/                 Environment preflight checks (CheckResult, PreflightReport)
  System/                    System command results and Qt detection DTOs
  Prompts/                   Interactive Laravel Prompts extensions (auto-dependency expansion)
  Support/                   Shared utilities (type resolution, naming, smart pointers)
  Contracts/                 SystemInformation interface
  UnixSystemInformation.php  Concrete system information implementation
templates/
  generation/                Blade templates for extension code generation
    class_header/source/stub.blade.php   Per-class generated files
    config_m4/w32.blade.php              Build system config
    extension_header/source.blade.php    Extension-wide files
    method/                              Method dispatch templates (constructor, simple, overloaded, signal_bind)
    return/                              Return type templates (scalar, string, array, qobject_pointer, value_object, void)
    support/                             ~35 support templates (signal/slot attributes, QMetaObject bridge, thread runtime, enum holders, build info, ownership helpers)
  clang/                     Clang feature overrides
tests/
  Pest.php                   Test bootstrap with helpers and groups (build, commands, filtering, parsing, runtime)
  Build/                     Pipeline, generation, scaffolding, fixed-point engine tests
  Commands/                  Command tests including GenerateBuildMode/ subdirectory
  Parsing/                   Parser, type mapper, container parser tests
  Filtering/                 Exposure policy tests
  IO/                        SmartFileWriter tests
  Scanning/                  Module header scanner tests
  Qt/                        Qt installation resolver tests
  Runtime/                   Integration tests against the built extension (per-module)
  Examples/                  Example-specific tests
  Support/                   Test utilities (fakes, runners, result DTOs)
  Fixtures/                  Test fixture data
examples/
  _support/                  Shared support code (bootstrap, AppPaths, themes, widgets, Quick/QML)
  15 example applications    Each with run.php entry point
manifest.json                Module dependency graph (36 Qt modules)
cparser.stub.php             API reference for the ext-cparser extension
```

## CLI Commands (`php qtb`)

| Command | Description |
|---------|-------------|
| `doctor` | Check system requirements (PHP, ext-cparser, Qt, compiler, phpize, make) |
| `inspect <class>` | Dump raw class properties, methods, and access specifiers from a Qt header. Options: `--format (human\|json)`, `--qt-path`, `-I/--include` |
| `define <header> <class>` | Show the merged PHP class definition from a C++ header. Options: `--format`, `-I/--include` |
| `generate <header> <class>` | Generate C/C++ extension code for one class. Options: `-N/--namespace`, `-o/--output`, `--module`, `--build-mode`, `--worker-mode` |
| `build [modules]` | Generate a full PHP extension from Qt modules (interactive selection). Options: `--qt-path`, `--name`, `-o/--output`, `-F/--force`, `--no-build`, `--ccache`, `-j/--jobs` |
| `build:modules [modules]` | Generate per-module split extensions. Options: same as `build` |
| `build:discover [modules]` | Scan Qt modules and write discovery metadata cache. Options: `--qt-path`, `-o/--output`, `-F/--force`, `-j/--jobs` |
| `build:info` | Read build metadata from a previous build's runtime manifest. Options: `--build-root`, `--format (table\|json)`, `--module` |
| `example [name]` | Run an example app from `examples/`. Options: `--list/-l`, `--php`, `--extension` |

## Build, Test, and Development Commands

```sh
composer install                                        # Install dependencies
php qtb doctor                                          # Verify environment
php qtb build                                           # Interactive build (select modules)
php qtb build QtCore,QtGui,QtWidgets --no-build         # Generate without compiling
php qtb build:discover QtCore,QtGui -j 4                # Parallel discovery
```

### Testing

The project uses **Pest 4** and **PHPUnit 12**. Tests are split into fast unit tests and runtime integration tests.

```sh
composer test                   # Fast unit tests (excludes Runtime/)
composer test:runtime           # Integration tests against built extension
composer test:profile           # With profiling
composer test:runtime:profile   # Runtime tests with profiling
```

Pest groups: `build`, `commands`, `filtering`, `parsing`, `runtime`. Two PHPUnit configs: `phpunit.xml` (fast) and `phpunit.runtime.xml` (runtime only).

### Running Examples

```sh
# Via the CLI (auto-discovers examples/*/run.php)
php qtb example csv-viewer

# Directly with the extension loaded
DYLD_FRAMEWORK_PATH=/opt/homebrew/Frameworks php -dextension=build/ext/.libs/qt.so examples/csv-viewer/run.php

# With auto-quit (for CI / headless testing)
QT_EXAMPLE_AUTO_QUIT_SECONDS=5 php qtb example gpu-stress
```

## Coding Style and Naming Conventions

- 4-space indentation, typed properties, `declare(strict_types=1);` in new PHP files
- PSR-4 autoloading under `QtBuilder\` namespace (`src/`) and `QtBuilder\Tests\` (`tests/`)
- Descriptive suffixes matching module responsibility: `*Command`, `*Builder`, `*Context`, `*Result`, `*Bridge`, `*Resolver`, `*Policy`
- Blade templates should be minimal; push logic into PHP context classes (`ClassContext`, `MethodContext`, etc.)
- Readonly DTOs for data transfer (`CheckResult`, `CommandResult`, `QtDetectionResult`, `HeaderCandidate`, etc.)
- The `Definition/` layer is a pure IR: read-only DTOs with `toArray()`/`fromArray()` for serialization

## Architecture: Three-Stage Pipeline

```
Qt C++ Headers
    |
    v  (ext-cparser / libclang)
Parsing Layer  -- QtClassInspector -> raw arrays
    |
    v  (ClassDefinitionBuilder + CppToPhpTypeMapper)
Definition Layer -- PhpClass IR (merged overloads, filtered members, PHP types)
    |
    v  (ExtensionGenerator + Blade templates)
CodeGen Layer   -- .h, .cpp, .stub.php per class + extension scaffolding
    |
    v  (phpize / configure / make)
Built PHP Extension (.so)
```

The `Build/` module orchestrates the full pipeline at scale: discovers candidates across modules, resolves dependencies from `manifest.json`, runs parallel generation workers via `FixedPointEngine` (iterates until output stabilizes), scaffolds the extension, and bootstraps with phpize/configure/make.

## Signal/Slot Pattern for Examples

PHP classes extending `QObject` can use `#[Signal]` and `#[Slot]` attributes. When exposed to QML via `QQmlEngine::rootContext()->setContextProperty()`, QML can call PHP slots directly and listen to PHP signals through `Connections`:

```php
use Qt\Core\Attributes\Signal;
use Qt\Core\Attributes\Slot;
use Qt\Core\QObject;

class MyGateway extends QObject
{
    #[Slot(['string'])]
    public function doSomething(string $arg): void
    {
        $this->done('result: ' . $arg);
    }

    #[Signal(['string'])]
    protected function done(string $value): void {}
}
```

```qml
Connections {
    target: gateway
    function onDone(value) { console.log(value) }
}
// Call from QML: gateway.doSomething("hello")
```

The `signal-slot.php` file at the repo root demonstrates and tests this mechanism.

## Key Dependencies

| Dependency | Purpose |
|-----------|---------|
| `ext-cparser` | PHP extension wrapping libclang for C++ header parsing |
| `symfony/console` ^8.0 | CLI framework |
| `symfony/process` ^8.0 | Process management for parallel workers |
| `symfony/dependency-injection` ^8.0 | Service container |
| `laravel/prompts` ^0.3 | Interactive module selection |
| `eftec/bladeone` ^4.19 | Template engine for code generation |
| `pest` ^4.4 / `phpunit` ^12.4 | Testing |

## Commit and Pull Request Guidelines

Short, imperative commit subjects (e.g., `fix duplicate naming bug`, `filter out some c/c++ not supported in php`). Keep subjects concise and specific; avoid bundling unrelated changes. Pull requests should describe the Qt class or pipeline stage affected, list validation steps run, and include representative command output when behavior changes.

## Environment Requirements

- PHP `^8.4`
- `ext-cparser` (builds from source against libclang)
- Qt 6.x SDK (with development headers)
- C++17 compiler, phpize, php-config, make, pkg-config
- Run `php qtb doctor` before debugging parser or code-generation failures
- On macOS: `DYLD_FRAMEWORK_PATH=/opt/homebrew/Frameworks` needed when running the built extension
