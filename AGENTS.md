# Repository Guidelines

## Project Structure & Module Organization
`qtb` is the CLI entrypoint. Core PHP code lives in `src/` and is organized by responsibility: `Commands/` for Symfony Console commands, `Parsing/` for libclang-based header inspection, `Definition/` for the intermediate representation, `CodeGen/` for template contexts and file generation, and `Preflight/` and `System/` for environment checks. Blade templates for generated extension files live in `templates/`. Tests live in `tests/`. The `cparser.stub.php` file documents the custom `ext-cparser` API this project depends on.

## Build, Test, and Development Commands
Run `composer install` first to install dependencies and generate `vendor/autoload.php`.

- `php qtb doctor`: verify PHP, `ext-cparser`, Qt discovery, compiler, and build tools
- `php qtb inspect <header> <Class> -I <include-dir>`: dump raw class data from a Qt header
- `php qtb define <header> <Class> -I <include-dir>`: show the merged PHP-facing class definition
- `php qtb generate <header> <Class> -I <include-dir> -o ext/`: generate wrapper `.h`, `.cpp`, and `.stub.php` files
- `composer test`: run PHPUnit

Example: `php qtb inspect /opt/homebrew/opt/qt/include/QtWidgets/QWidget QWidget -I /opt/homebrew/opt/qt/include`

## Coding Style & Naming Conventions
Follow the existing PHP style: 4-space indentation, typed properties, `declare(strict_types=1);` in new PHP files, and PSR-4 names under the `QtBuilder\\` namespace. Use descriptive suffixes that match the current structure, such as `*Command`, `*Builder`, `*Context`, and `*Result`. Keep templates minimal and push logic into PHP classes where possible.

## Testing Guidelines
This project uses PHPUnit 11. Add tests under `tests/` with names ending in `Test.php`. Prefer focused unit tests for parsing, IR building, and code-generation helpers over broad CLI-only tests. When adding generation behavior, include assertions against the produced IR or rendered output, not just command exit codes.

## Commit & Pull Request Guidelines
Recent history uses short, imperative commit subjects such as `fix duplicate naming bug` and `filter out some c/c++ not supported in php`. Keep subjects concise and specific; avoid bundling unrelated changes. Pull requests should describe the Qt class or pipeline stage affected, list validation steps run, and include representative command output when behavior changes.

## Environment Notes
Development currently assumes PHP `^8.4`, `ext-cparser`, and a local Qt SDK. Use `doctor` before debugging parser or code-generation failures.
