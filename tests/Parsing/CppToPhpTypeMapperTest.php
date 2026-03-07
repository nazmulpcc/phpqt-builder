<?php

declare(strict_types=1);

use QtBuilder\Parsing\CppToPhpTypeMapper;

it('maps qt global enum-like names to int', function (): void {
    $mapper = new CppToPhpTypeMapper();

    expect($mapper->map('QtMsgType'))->toBe('int')
        ->and($mapper->map('QtHighDpiScaleFactorRoundingPolicy'))->toBe('int');
});

it('keeps qt object names as object types', function (): void {
    $mapper = new CppToPhpTypeMapper();

    expect($mapper->map('QUrl'))->toBe('QUrl')
        ->and($mapper->map('QBuffer::OpenMode'))->toBe('int');
});

it('maps common opengl scalar typedefs to php scalars', function (): void {
    $mapper = new CppToPhpTypeMapper();

    expect($mapper->map('GLenum'))->toBe('int')
        ->and($mapper->map('GLuint'))->toBe('int')
        ->and($mapper->map('GLint'))->toBe('int')
        ->and($mapper->map('GLsizei'))->toBe('int')
        ->and($mapper->map('GLbitfield'))->toBe('int')
        ->and($mapper->map('GLfloat'))->toBe('float')
        ->and($mapper->map('GLdouble'))->toBe('float')
        ->and($mapper->map('GLboolean'))->toBe('bool');
});

it('maps supported opengl numeric pointer inputs to php arrays', function (): void {
    $mapper = new CppToPhpTypeMapper();

    expect($mapper->map('const GLfloat *'))->toBe('array')
        ->and($mapper->map('const GLint *'))->toBe('array');
});
