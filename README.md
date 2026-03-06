# PHP QT Builder
The goal of this project is to achieve a PHP extension that wraps around QT so we can build cross platform applications using PHP.
- Parse AST from QT header files using `cparser` extension, see [cparser.stub.php](/cparser.stub.php)
- Use templates to build C++ source code that wraps QT classes.
- Build PHP extension using the generated C++ classes.

## Supported Module Manifest
The repository root [manifest.json](/Users/nazmul/lab/phpqt-builder-codex/manifest.json) lists the currently supported wrapper modules and their declared inter-module dependencies.

## Preflight System Checks
Preflight checks are run by the `doctor` command and report whether the system is ready for project tasks.

### Critical checks
- Supported OS family (`Linux` or `Darwin`)
- PHP version (`^8.4`)
- `ext-cparser` extension
- Qt discovery using one of:
  - `qtpaths`/`qtpaths6`
  - `qmake`/`qmake6`
  - `pkg-config --exists Qt6Core`
- C++ compiler (`c++`, `g++`, or `clang++`)
- `phpize`
- Build tool (`make` or `ninja`)

### Warning checks
- `cmake` availability (recommended, not blocking yet)

## Doctor Command
Use `doctor` to print the current preflight report explicitly.

```bash
./qtb doctor
```

Machine-readable output:

```bash
./qtb doctor --format=json
```

Example JSON shape:

```json
{
  "summary": {
    "status": "fail",
    "passed": 5,
    "warnings": 1,
    "failed": 2
  },
  "checks": [
    {
      "id": "php_ext_cparser",
      "label": "PHP Extension cparser",
      "status": "fail",
      "message": "The ext-cparser extension is missing. Install or enable it before continuing.",
      "meta": {}
    }
  ]
}
```
