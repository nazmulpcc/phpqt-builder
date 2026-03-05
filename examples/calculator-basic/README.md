# calculator-basic

A macOS Calculator-inspired basic mode clone built with Qt Widgets.

## Features

- Numeric entry with decimal support
- `AC`, `+/-`, `%`, `÷`, `×`, `-`, `+`, `=`
- Chained operations with top-line expression feedback
- Division-by-zero error handling
- Dedicated calculator stylesheet (not shared with other examples)

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/calculator-basic/run.php
```
