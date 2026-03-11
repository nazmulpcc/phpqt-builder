<?php

declare(strict_types=1);

use QtBuilder\Parsing\CppToPhpTypeMapper;
use QtBuilder\Support\CppClassTypeResolver;
use QtBuilder\Support\TypeResolutionContext;

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
        ->and($mapper->map('GLuint64'))->toBe('int')
        ->and($mapper->map('GLint'))->toBe('int')
        ->and($mapper->map('GLintptr'))->toBe('int')
        ->and($mapper->map('GLsizei'))->toBe('int')
        ->and($mapper->map('GLsizeiptr'))->toBe('int')
        ->and($mapper->map('GLbitfield'))->toBe('int')
        ->and($mapper->map('GLshort'))->toBe('int')
        ->and($mapper->map('GLushort'))->toBe('int')
        ->and($mapper->map('GLbyte'))->toBe('int')
        ->and($mapper->map('GLubyte'))->toBe('int')
        ->and($mapper->map('uint'))->toBe('int')
        ->and($mapper->map('GLfloat'))->toBe('float')
        ->and($mapper->map('GLdouble'))->toBe('float')
        ->and($mapper->map('GLboolean'))->toBe('bool');
});

it('maps supported opengl numeric pointer inputs to php arrays', function (): void {
    $mapper = new CppToPhpTypeMapper();

    expect($mapper->map('const GLfloat *'))->toBe('array')
        ->and($mapper->map('const GLdouble *'))->toBe('array')
        ->and($mapper->map('const GLint *'))->toBe('array')
        ->and($mapper->map('const GLshort *'))->toBe('array')
        ->and($mapper->map('const GLushort *'))->toBe('array')
        ->and($mapper->map('const GLuint *'))->toBe('array');
});

it('maps opengl raw input buffers to strings only for opengl owners', function (): void {
    $mapper = new CppToPhpTypeMapper();

    expect($mapper->map('const void *', 'QOpenGLBuffer'))->toBe('string')
        ->and($mapper->map('const GLvoid *', 'QOpenGLFunctions_1_0'))->toBe('string')
        ->and($mapper->map('const GLubyte *', 'QOpenGLFunctions_1_0'))->toBe('string')
        ->and($mapper->map('const void *', 'QByteArray'))->toBe('mixed')
        ->and($mapper->map('const GLvoid *', 'QByteArray'))->toBe('mixed')
        ->and($mapper->map('const GLubyte *', 'QByteArray'))->toBe('int');
});

it('maps qualified namespaced class types to their php class identity when resolver data is available', function (): void {
    $mapper = new CppToPhpTypeMapper();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QNodeId', 'qualified_name' => 'Qt3DCore::QNodeId', 'module' => 'Qt3DCore'],
    ]);
    $context = TypeResolutionContext::fromNames('QRayCasterHit', 'Qt3DRender::QRayCasterHit');

    expect($mapper->map('Qt3DCore::QNodeId', 'QRayCasterHit', $resolver, $context))->toBe('\\Qt\\Qt3DCore\\QNodeId')
        ->and($mapper->map('const Qt3DCore::QNodeId &', 'QRayCasterHit', $resolver, $context))->toBe('\\Qt\\Qt3DCore\\QNodeId');
});

it('maps nested class members to owner-scoped php fqcns when owner php namespace is available', function (): void {
    $mapper = new CppToPhpTypeMapper();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QJsonObject', 'qualified_name' => 'QJsonObject', 'module' => 'QtCore'],
        ['name' => 'const_iterator', 'qualified_name' => 'QJsonObject::const_iterator', 'module' => 'QtCore'],
    ]);
    $context = TypeResolutionContext::fromNames('QJsonObject', 'QJsonObject');

    expect($mapper->map('const_iterator', '\\Qt\\Core\\QJsonObject', $resolver, $context))
        ->toBe('\\Qt\\Core\\QJsonObject\\const_iterator');
});

it('keeps explicit Qt enum qualifiers mapped as int even when a class has the same bare tail name', function (): void {
    $mapper = new CppToPhpTypeMapper();
    $resolver = new CppClassTypeResolver([
        ['name' => 'Key', 'qualified_name' => 'QPixmapCache::Key', 'module' => 'QtGui'],
    ]);
    $context = TypeResolutionContext::fromNames('QKeyCombination', 'QKeyCombination');

    expect($mapper->map('Qt::Key', 'QKeyCombination', $resolver, $context))->toBe('int')
        ->and($mapper->map('const Qt::Key &', 'QKeyCombination', $resolver, $context))->toBe('int');
});

it('maps qsharedpointer aliases to the underlying php object type when alias metadata is available', function (): void {
    $mapper = new CppToPhpTypeMapper();
    $resolver = new CppClassTypeResolver([
        ['name' => 'QEntity', 'qualified_name' => 'Qt3DCore::QEntity', 'module' => 'Qt3DCore'],
        ['name' => 'QAspectEngine', 'qualified_name' => 'Qt3DCore::QAspectEngine', 'module' => 'Qt3DCore'],
    ]);
    $context = TypeResolutionContext::fromNames('QAspectEngine', 'Qt3DCore::QAspectEngine');
    $aliases = ['QEntityPtr' => 'Qt3DCore::QEntity'];

    expect($mapper->map('QEntityPtr', 'QAspectEngine', $resolver, $context, $aliases))->toBe('\\Qt\\Qt3DCore\\QEntity')
        ->and($mapper->map('const QEntityPtr &', 'QAspectEngine', $resolver, $context, $aliases))->toBe('\\Qt\\Qt3DCore\\QEntity');
});

it('maps int128 and multimap container types to php-safe types', function (): void {
    $mapper = new CppToPhpTypeMapper();

    expect($mapper->map('quint128'))->toBe('string')
        ->and($mapper->map('qint128'))->toBe('string')
        ->and($mapper->map('QMultiHash<int, QString>'))->toBe('array')
        ->and($mapper->map('QMultiMap<QString, int>'))->toBe('array');
});
