# PHP QT Builder
The goal of this project is to achieve a PHP extension that wraps around QT so we can build cross platform applications using PHP.
- Parse AST from QT header files using `cparser` extension, see [cparser.stub.php](/cparser.stub.php)
- Use templates to build C++ source code that wraps QT classes.
- Build PHP extension using the generated C++ classes.