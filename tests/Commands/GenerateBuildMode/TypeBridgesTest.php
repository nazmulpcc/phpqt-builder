<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use QtBuilder\Build\ClassGenerationService;
use QtBuilder\Commands\GenerateCommand;
use QtBuilder\CodeGen\TypeBridge;
use QtBuilder\Tests\Support\GenerateBuildModeRunner;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

dataset('nested return holders', [
    'nested result type' => [
        'qresultholder.h',
        'QResultHolder',
        'QResultHolder',
        'decode',
        'public function setMode(int $mode): void {}',
        'intern->native_ptr->setMode((QResultHolder::Mode)((int)(mode)));',
        null,
        'RETURN_LONG((zend_long)(QResultHolder::decode()',
    ],
    'nested bare enum type' => [
        'qpartsholder.h',
        'QPartsHolder',
        'QPartsHolder,QDate',
        'partsFromDate',
        'public function setFormat(int $format): void {}',
        'intern->native_ptr->setFormat((QPartsHolder::NameFormat)((int)(format)));',
        'public function partsFromDate',
        'ZEND_METHOD(Qt_Core_QPartsHolder, partsFromDate)',
    ],
    'qualified nested enum type' => [
        'qqualifiedtypeholder.h',
        'QQualifiedTypeHolder',
        'QQualifiedTypeHolder',
        'elementAt',
        'public function kind(): int {}',
        'intern->native_ptr->setKind((QQualifiedTypeHolder::Kind)((int)(kind)));',
        null,
        'ZEND_METHOD(Qt_Core_QQualifiedTypeHolder, elementAt)',
    ],
]);
it('casts const object pointer returns for wrapping', function (): void {
        $fixtureRoot = qt_fixture_path('const-pointer');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qconstnodeholder.h',
            'class' => 'QNodeConstHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QNodeConstHolder,QNode',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qnodeconstholder.cpp');
        Assert::assertStringContainsString('const QNode * _result = intern->native_ptr->node();', $cpp);
        Assert::assertStringContainsString('qt_qnode_wrap_native(return_value, const_cast<QNode *>(_result), qt_ce_QNode, true);', $cpp);
        Assert::assertStringContainsString('if (UNEXPECTED(Z_TYPE_P(return_value) != IS_OBJECT)) {', $cpp);
        Assert::assertStringContainsString('Failed to instantiate PHP wrapper for QNodeConstHolder', $cpp);
});

it('casts enum parameters back to native types', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qenumholder.h',
            'class' => 'QEnumHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QEnumHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qenumholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qenumholder.cpp');
    
        Assert::assertStringContainsString('public function setMode(int $mode): void {}', $stub);
        Assert::assertStringContainsString('public const int Off = 0;', $stub);
        Assert::assertStringContainsString('public const int On = 1;', $stub);
        Assert::assertStringContainsString('intern->native_ptr->setMode((QEnumHolder::Mode)((int)(mode)));', $cpp);
        Assert::assertStringContainsString('auto _result = intern->native_ptr->mode();', $cpp);
        Assert::assertStringContainsString('RETURN_LONG((zend_long)(_result));', $cpp);
});

it('qualifies nested enum class names for native casts', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qnestedenumholder.h',
            'class' => 'QNestedEnumHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QNestedEnumHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qnestedenumholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qnestedenumholder.cpp');
    
        Assert::assertStringContainsString('public function setPair(int $semantic, int $componentType): void {}', $stub);
        Assert::assertStringContainsString('intern->native_ptr->setPair((QNestedEnumHolder::Attribute::Semantic)((int)(semantic)), (QNestedEnumHolder::Attribute::ComponentType)((int)(componentType)));', $cpp);
});

it('handles const char pointer string returns', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qcstringholder.h',
            'class' => 'QCStringHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QCStringHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qcstringholder.cpp');
        Assert::assertStringContainsString('auto _result = intern->native_ptr->bits();', $cpp);
        Assert::assertStringContainsString('RETURN_STRING(_result);', $cpp);
        Assert::assertStringNotContainsString('QByteArray _utf8 = _result.toUtf8();', $cpp);
});

it('handles QString pointer parameters', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qstringpointerholder.h',
            'class' => 'QStringPointerHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QStringPointerHolder',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);

        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qstringpointerholder.cpp');
        Assert::assertStringContainsString('QString _qt_arg_1_value;', $cpp);
        Assert::assertStringContainsString('QString *_qt_arg_1 = NULL;', $cpp);
        Assert::assertStringContainsString('_qt_arg_1_value = (', $cpp);
        Assert::assertStringContainsString('QString::fromUtf8(', $cpp);
        Assert::assertStringContainsString('Z_REFVAL_P(selectedFilter)', $cpp);
        Assert::assertStringContainsString('_qt_arg_1 = &_qt_arg_1_value;', $cpp);
        Assert::assertStringContainsString('auto _result = QStringPointerHolder::pickLabel(', $cpp);
        Assert::assertStringContainsString('_qt_arg_1);', $cpp);
        Assert::assertStringContainsString('QByteArray _utf8 = _result.toUtf8();', $cpp);
        Assert::assertStringContainsString('RETURN_STRINGL(_utf8.constData(), _utf8.size());', $cpp);
});

it('uses direct construction for explicit value-return fallbacks in virtual dispatch', function (): void {
        $bridge = new TypeBridge();

        Assert::assertSame('QExplicitValue()', $bridge->defaultNativeReturnExpr('QExplicitValue', 'QExplicitValue'));
        Assert::assertSame('QSqlIndex()', $bridge->defaultNativeReturnExpr('QSqlIndex', 'QSqlIndex'));
        Assert::assertSame('QVariant()', $bridge->defaultNativeReturnExpr('QVariant', 'QVariant'));
});

it('supports common opengl scalar typedef parameters and returns', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        mkdir($outputDir, 0777, true);

        $header = $outputDir . '/qglscalarholder.h';
        file_put_contents($header, <<<'CPP'
typedef unsigned int GLenum;
typedef unsigned int GLuint;
typedef unsigned long long GLuint64;
typedef int GLint;
typedef long long GLintptr;
typedef int GLsizei;
typedef long long GLsizeiptr;
typedef unsigned int GLbitfield;
typedef short GLshort;
typedef unsigned short GLushort;
typedef signed char GLbyte;
typedef unsigned char GLubyte;
typedef unsigned int uint;
typedef float GLfloat;
typedef double GLdouble;
typedef unsigned char GLboolean;

class QGlScalarHolder {
public:
GLenum mode() const;
void setMode(GLenum mode);
GLuint programId() const;
void setProgramId(GLuint id);
GLint location() const;
void setLocation(GLint location);
GLsizei stride() const;
void setStride(GLsizei stride);
GLbitfield mask() const;
void setMask(GLbitfield mask);
GLshort shortValue() const;
void setShortValue(GLshort value);
GLushort ushortValue() const;
void setUshortValue(GLushort value);
GLbyte byteValue() const;
void setByteValue(GLbyte value);
GLubyte ubyteValue() const;
void setUbyteValue(GLubyte value);
GLintptr offset() const;
void setOffset(GLintptr value);
GLsizeiptr sizeInBytes() const;
void setSizeInBytes(GLsizeiptr value);
GLuint64 timestamp() const;
void setTimestamp(GLuint64 value);
uint textureUnit() const;
void setTextureUnit(uint value);
GLfloat ratio() const;
void setRatio(GLfloat ratio);
GLdouble gain() const;
void setGain(GLdouble gain);
GLboolean enabled() const;
void setEnabled(GLboolean enabled);
};
CPP);

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $header,
            'class' => 'QGlScalarHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QGlScalarHolder',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('unsupported_parameter_type', array_column($payload['skipped_methods'], 'reason_code'));
        Assert::assertNotContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qglscalarholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qglscalarholder.cpp');

        Assert::assertStringContainsString('public function setMode(int $mode): void {}', $stub);
        Assert::assertStringContainsString('public function programId(): int {}', $stub);
        Assert::assertStringContainsString('public function ratio(): float {}', $stub);
        Assert::assertStringContainsString('public function enabled(): bool {}', $stub);
        Assert::assertStringContainsString('intern->native_ptr->setMode((GLenum)mode);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setProgramId((GLuint)id);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setLocation((GLint)location);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setStride((GLsizei)stride);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setMask((GLbitfield)mask);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setShortValue((GLshort)value);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setUshortValue((GLushort)value);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setByteValue((GLbyte)value);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setUbyteValue((GLubyte)value);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setOffset((GLintptr)value);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setSizeInBytes((GLsizeiptr)value);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setTimestamp((GLuint64)value);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setTextureUnit((uint)value);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setRatio((GLfloat)ratio);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setGain((GLdouble)gain);', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->setEnabled(enabled);', $cpp);
        Assert::assertStringContainsString('RETURN_LONG((zend_long)(_result));', $cpp);
        Assert::assertStringContainsString('RETURN_DOUBLE((double)(_result));', $cpp);
        Assert::assertStringContainsString('RETURN_BOOL((bool)(_result));', $cpp);
});

it('supports input-only opengl numeric pointer arrays', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        mkdir($outputDir, 0777, true);

        $header = $outputDir . '/qglnumericarrayholder.h';
        file_put_contents($header, <<<'CPP'
typedef float GLfloat;
typedef int GLint;

class QGlNumericArrayHolder {
public:
void uploadFloats(const GLfloat *values, int count);
void uploadInts(const GLint *values, int count);
};
CPP);

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $header,
            'class' => 'QGlNumericArrayHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QGlNumericArrayHolder',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('unsupported_parameter_type', array_column($payload['skipped_methods'], 'reason_code'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qglnumericarrayholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qglnumericarrayholder.cpp');

        Assert::assertStringContainsString('public function uploadFloats(array $values, int $count): void {}', $stub);
        Assert::assertStringContainsString('public function uploadInts(array $values, int $count): void {}', $stub);
        Assert::assertStringContainsString('std::vector<GLfloat> _qt_arg_0_storage;', $cpp);
        Assert::assertStringContainsString('const GLfloat * _qt_arg_0 = NULL;', $cpp);
        Assert::assertStringContainsString('Expected PHP array for OpenGL numeric buffer conversion.', $cpp);
        Assert::assertStringContainsString('Expected array of numeric values.', $cpp);
        Assert::assertStringContainsString('_qt_arg_0_storage.push_back((GLfloat)zval_get_double(_qt_arg_0_entry));', $cpp);
        Assert::assertStringContainsString('std::vector<GLint> _qt_arg_0_storage;', $cpp);
        Assert::assertStringContainsString('const GLint * _qt_arg_0 = NULL;', $cpp);
        Assert::assertStringContainsString('Expected array of ints.', $cpp);
        Assert::assertStringContainsString('_qt_arg_0_storage.push_back((GLint)Z_LVAL_P(_qt_arg_0_entry));', $cpp);
});

it('supports opengl raw input buffers as php strings', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        mkdir($outputDir, 0777, true);

        $header = $outputDir . '/qopenglrawbufferholder.h';
        file_put_contents($header, <<<'CPP'
typedef void GLvoid;
typedef unsigned char GLubyte;

class QOpenGLRawBufferHolder {
public:
void uploadBytes(int size, const void *data);
void uploadGlBytes(int size, const GLvoid *data);
void uploadIndexBytes(int size, const GLubyte *data);
};
CPP);

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $header,
            'class' => 'QOpenGLRawBufferHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QOpenGLRawBufferHolder',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qopenglrawbufferholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qopenglrawbufferholder.cpp');

        Assert::assertStringContainsString('public function uploadBytes(int $size, string $data): void {}', $stub);
        Assert::assertStringContainsString('public function uploadGlBytes(int $size, string $data): void {}', $stub);
        Assert::assertStringContainsString('intern->native_ptr->uploadBytes((int)size, (const void *)ZSTR_VAL(data));', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->uploadGlBytes((int)size, (const GLvoid *)ZSTR_VAL(data));', $cpp);
        Assert::assertStringContainsString('intern->native_ptr->uploadIndexBytes((int)size, (const GLubyte *)ZSTR_VAL(data));', $cpp);
    });

it('supports glubyte string returns for opengl apis', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        mkdir($outputDir, 0777, true);

        $header = $outputDir . '/qopenglstringreturnholder.h';
        file_put_contents($header, <<<'CPP'
typedef unsigned char GLubyte;

class QOpenGLStringReturnHolder {
public:
const GLubyte *getString() const;
};
CPP);

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $header,
            'class' => 'QOpenGLStringReturnHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QOpenGLStringReturnHolder',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qopenglstringreturnholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qopenglstringreturnholder.cpp');

        Assert::assertStringContainsString('public function getString(): string {}', $stub);
        Assert::assertStringContainsString('RETURN_STRING((const char *)_result)', $cpp);
    });

it('keeps non-opengl raw input buffers unsupported', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        mkdir($outputDir, 0777, true);

        $header = $outputDir . '/qrawbufferholder.h';
        file_put_contents($header, <<<'CPP'
class QRawBufferHolder {
public:
void uploadBytes(const void *data);
};
CPP);

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $header,
            'class' => 'QRawBufferHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QRawBufferHolder',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('skipped', $payload['status']);
        Assert::assertSame('no_supported_methods', $payload['reason_code']);
        Assert::assertContains('unsupported_parameter_type', array_column($payload['skipped_methods'], 'reason_code'));
    });

it('treats qbitarray factories as value returns and supports bool out parameters by-ref', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qbitarray.h',
            'class' => 'QBitArray',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QBitArray',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qbitarray.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qbitarray.cpp');
        Assert::assertStringContainsString('public function toUInt32(int $endianness, bool|null &$ok = null): int {}', $stub);
        Assert::assertStringContainsString('QBitArray _result = QBitArray::fromBits(ZSTR_VAL(data), (int)len);', $cpp);
        Assert::assertStringContainsString('_ret_intern->native_ptr = new QBitArray(std::move(_result));', $cpp);
        Assert::assertStringNotContainsString('QBitArray *_result = QBitArray::fromBits', $cpp);
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QBitArray, toUInt32)', $cpp);
        Assert::assertStringContainsString('bool _qt_arg_1_value;', $cpp);
        Assert::assertStringContainsString('if ((ok != NULL)) {', $cpp);
        Assert::assertStringContainsString('_qt_arg_1_value = (((Z_TYPE_P(ok) == IS_REFERENCE) ? Z_REFVAL_P(ok) : (ok)) != NULL && Z_TYPE_P(((Z_TYPE_P(ok) == IS_REFERENCE) ? Z_REFVAL_P(ok) : (ok))) == IS_TRUE);', $cpp);
        Assert::assertStringContainsString('_qt_arg_1 = &_qt_arg_1_value;', $cpp);
        Assert::assertStringContainsString('ZEND_TRY_ASSIGN_REF_BOOL(ok, (bool)((*_qt_arg_1)));', $cpp);
        Assert::assertStringNotContainsString('Z_TYPE_P(((Z_TYPE_P(ok) == IS_REFERENCE) ? Z_REFVAL_P(ok) : (ok))) != IS_NULL', $cpp);
        $callPos = strpos($cpp, 'auto _result = intern->native_ptr->toUInt32(');
        $writebackPos = strpos($cpp, 'ZEND_TRY_ASSIGN_REF_BOOL(ok, (bool)((*_qt_arg_1)));');
        Assert::assertNotFalse($callPos);
        Assert::assertNotFalse($writebackPos);
        Assert::assertGreaterThan($callPos, $writebackPos);
});

it('skips object double pointer out parameters', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qdoublepointerholder.h',
            'class' => 'QDoublePointerHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QDoublePointerHolder,QDoublePointerPeer',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('locate', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_parameter_type', array_column($payload['skipped_methods'], 'reason_code'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qdoublepointerholder.cpp');
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QDoublePointerHolder, value)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QDoublePointerHolder, locate)', $cpp);
});

it('keeps optional QString pointer parameters as writable nullable by-ref pointers', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qstringpointerholder.h',
            'class' => 'QStringPointerHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QStringPointerHolder',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertNotContains('pickLabel', array_column($payload['skipped_methods'], 'name'));

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qstringpointerholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qstringpointerholder.cpp');

        Assert::assertStringContainsString('public static function pickLabel(string $fallback = \'\', string|null &$selectedFilter = null): string {}', $stub);
        Assert::assertStringContainsString('QString _qt_arg_1_value;', $cpp);
        Assert::assertStringContainsString('QString *_qt_arg_1 = NULL;', $cpp);
        Assert::assertStringContainsString('_qt_arg_1_value = (', $cpp);
        Assert::assertStringContainsString('QString::fromUtf8(', $cpp);
        Assert::assertStringContainsString('Z_REFVAL_P(selectedFilter)', $cpp);
        Assert::assertStringContainsString('_qt_arg_1 = &_qt_arg_1_value;', $cpp);
        Assert::assertStringContainsString('QStringPointerHolder::pickLabel(QString::fromUtf8(ZSTR_VAL(fallback), (int)ZSTR_LEN(fallback)), _qt_arg_1)', $cpp);
        Assert::assertStringContainsString('ZEND_TRY_ASSIGN_REF_NEW_STR(selectedFilter, _qt_ref_str_1);', $cpp);
});

it('uses fromInt for flag aliases', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qflagholder.h',
            'class' => 'QFlagHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QFlagHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qflagholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qflagholder.cpp');
    
        Assert::assertStringContainsString('public function setModes(int $modes): void {}', $stub);
        Assert::assertStringContainsString('QFlags<QFlagHolder::Mode>::fromInt((QFlags<QFlagHolder::Mode>::Int)((int)(modes)))', $cpp);
        Assert::assertStringContainsString('auto _result = intern->native_ptr->modes();', $cpp);
        Assert::assertStringContainsString('RETURN_LONG((zend_long)(_result));', $cpp);
});

it('handles char strings and supports writable qt string references', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
    
        $charOutputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qcharholder.h',
            'class' => 'QCharHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $charOutputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QCharHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $charPayload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $charPayload['status']);
    
        $charCpp = (string) file_get_contents($charOutputDir . '/classes/qt_qcharholder.cpp');
        Assert::assertStringContainsString('RETURN_STRINGL(&_result, 1);', $charCpp);
        Assert::assertStringContainsString('RETURN_STRING(_result);', $charCpp);
        Assert::assertStringContainsString("(ZSTR_LEN(ch) > 0 ? ZSTR_VAL(ch)[0] : '\\0')", $charCpp);
    
        $refOutputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qrefholder.h',
            'class' => 'QRefHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $refOutputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QRefHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $refPayload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $refPayload['status']);
    
        $refStub = (string) file_get_contents($refOutputDir . '/classes/qt_qrefholder.stub.php');
        $refCpp = (string) file_get_contents($refOutputDir . '/classes/qt_qrefholder.cpp');
    
        Assert::assertStringContainsString('public function swap(string &$other): void {}', $refStub);
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QRefHolder, swap)', $refCpp);
        Assert::assertStringContainsString('QByteArray _qt_arg_0 = QByteArray(', $refCpp);
        Assert::assertStringContainsString('Z_REFVAL_P(other)', $refCpp);
        Assert::assertStringContainsString('ZEND_TRY_ASSIGN_REF_NEW_STR(other, _qt_ref_str_0);', $refCpp);
});

it('builds an argv constructor bridge', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qargvholder.h',
            'class' => 'QArgvHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QArgvHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $header = (string) file_get_contents($outputDir . '/classes/qt_qargvholder.h');
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qargvholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qargvholder.cpp');
    
        Assert::assertStringContainsString('typedef struct _qt_argv_storage {', $header);
        Assert::assertStringContainsString('std::vector<QByteArray> argv_storage;', $header);
        Assert::assertStringContainsString('std::vector<char *> argv_pointers;', $header);
        Assert::assertStringContainsString('void *extra_storage;', $header);
        Assert::assertStringContainsString('public function __construct(int &$argc = 0, array $argv = [], int $flags = 0) {}', $stub);
        Assert::assertStringContainsString('intern->extra_storage = new qt_argv_storage();', $cpp);
        Assert::assertStringContainsString('if (intern->extra_storage == NULL) {', $cpp);
        Assert::assertStringContainsString('intern->extra_storage = new qt_argv_storage();', $cpp);
        Assert::assertStringContainsString('auto *_qt_argv_storage = static_cast<qt_argv_storage *>(intern->extra_storage);', $cpp);
        Assert::assertStringContainsString('char ** _qt_arg_1 = NULL;', $cpp);
        Assert::assertStringContainsString('_qt_argv_storage->argv_storage.emplace_back("php", 3);', $cpp);
        Assert::assertStringContainsString('_qt_arg_0 = (int)_qt_argv_storage->argv_storage.size();', $cpp);
});

it('builds a const char pointer array bridge for string lists', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
        mkdir($outputDir, 0777, true);

        $header = $outputDir . '/qshaderstringarrayholder.h';
        file_put_contents($header, <<<'CPP'
class QShaderStringArrayHolder {
public:
void setSources(int count, const char **sources);
};
CPP);

        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $header,
            'class' => 'QShaderStringArrayHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QShaderStringArrayHolder',
        ]);

        Assert::assertSame(Command::SUCCESS, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);

        $stub = (string) file_get_contents($outputDir . '/classes/qt_qshaderstringarrayholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qshaderstringarrayholder.cpp');

        Assert::assertStringContainsString('public function setSources(int $count, array $sources): void {}', $stub);
        Assert::assertStringContainsString('std::vector<QByteArray> _qt_arg_1_storage;', $cpp);
        Assert::assertStringContainsString('std::vector<const char *> _qt_arg_1_pointers;', $cpp);
        Assert::assertStringContainsString('const char ** _qt_arg_1 = NULL;', $cpp);
        Assert::assertStringContainsString('_qt_arg_1_pointers.push_back(_qt_arg_1_item.data());', $cpp);
});

it('skips nested return types while keeping enum-facing apis', function (
    string $header,
    string $class,
    string $allowed,
    string $skippedMethod,
    string $stubFragment,
    string $cppFragment,
    ?string $missingStubFragment,
    string $missingCppFragment,
): void {
    $result = GenerateBuildModeRunner::run('policy-qt', [
        'header' => qt_fixture_path('policy-qt/include/QtCore/' . $header),
        'class' => $class,
        '--qt-path' => qt_fixture_path('policy-qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => $allowed,
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and(array_column($result->payload['skipped_methods'], 'name'))->toContain($skippedMethod)
        ->and(array_column($result->payload['skipped_methods'], 'reason_code'))->toContain('unsupported_return_type')
        ->and($result->stub($class))->toContain($stubFragment)
        ->and($result->cpp($class))->toContain($cppFragment)
        ->and($result->cpp($class))->not->toContain($missingCppFragment);

    if ($missingStubFragment !== null) {
        expect($result->stub($class))->not->toContain($missingStubFragment);
    }
})->with('nested return holders');

it('handles std string conversions', function (): void {
    $result = GenerateBuildModeRunner::run('policy-qt', [
        'header' => qt_fixture_path('policy-qt/include/QtCore/qstdstringholder.h'),
        'class' => 'QStdStringHolder',
        '--qt-path' => qt_fixture_path('policy-qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => 'QStdStringHolder',
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and($result->stub('QStdStringHolder'))->toContain('public static function fromStdString(string $s): QStdStringHolder {}')
        ->and($result->stub('QStdStringHolder'))->toContain('public function toStdString(): string {}')
        ->and($result->cpp('QStdStringHolder'))->toContain('QStdStringHolder::fromStdString(std::string(ZSTR_VAL(s), ZSTR_LEN(s)))')
        ->and($result->cpp('QStdStringHolder'))->toContain('RETURN_STRINGL(_result.data(), _result.size())');
});

it('treats object returns without pointers as value objects', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qvaluereturnholder.h',
            'class' => 'QValueReturnHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QValueReturnHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $stub = (string) file_get_contents($outputDir . '/classes/qt_qvaluereturnholder.stub.php');
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qvaluereturnholder.cpp');
    
        Assert::assertStringContainsString('public static function create(): QValueReturnHolder {}', $stub);
        Assert::assertStringContainsString('public function normalized(): QValueReturnHolder {}', $stub);
        Assert::assertStringContainsString('QValueReturnHolder _result = QValueReturnHolder::create();', $cpp);
        Assert::assertStringContainsString('QValueReturnHolder _result = intern->native_ptr->normalized();', $cpp);
        Assert::assertStringContainsString('_ret_intern->native_ptr = new QValueReturnHolder(std::move(_result));', $cpp);
        Assert::assertStringNotContainsString('QValueReturnHolder *_result = QValueReturnHolder::create();', $cpp);
        Assert::assertStringNotContainsString('QValueReturnHolder *_result = intern->native_ptr->normalized();', $cpp);
});

it('converts chrono durations to and from integers', function (): void {
    $result = GenerateBuildModeRunner::run('policy-qt', [
        'header' => qt_fixture_path('policy-qt/include/QtCore/qchronoholder.h'),
        'class' => 'QChronoHolder',
        '--qt-path' => qt_fixture_path('policy-qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => 'QChronoHolder',
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and($result->stub('QChronoHolder'))->toContain('public function setInterval(int $value): void {}')
        ->and($result->cpp('QChronoHolder'))->toContain('intern->native_ptr->setInterval(std::chrono::milliseconds((std::chrono::milliseconds::rep)((int)(value))));')
        ->and($result->cpp('QChronoHolder'))->toContain('auto _result = intern->native_ptr->interval();')
        ->and($result->cpp('QChronoHolder'))->toContain('RETURN_LONG((zend_long)(_result.count()));');
});

it('bridges wide strings through qstring', function (): void {
    $result = GenerateBuildModeRunner::run('policy-qt', [
        'header' => qt_fixture_path('policy-qt/include/QtCore/qwidestringholder.h'),
        'class' => 'QWideStringHolder',
        '--qt-path' => qt_fixture_path('policy-qt'),
        '--module' => 'QtCore',
        '--allowed-classes' => 'QWideStringHolder',
    ]);

    expect($result->exitCode)->toBe(Command::SUCCESS)
        ->and($result->payload['status'])->toBe('ok')
        ->and($result->stub('QWideStringHolder'))->toContain('public static function fromStdWString(string $s): QWideStringHolder {}')
        ->and($result->cpp('QWideStringHolder'))->toContain('QString::fromUtf8(ZSTR_VAL(s), (int)ZSTR_LEN(s)).toStdWString()')
        ->and($result->cpp('QWideStringHolder'))->toContain('QString::fromStdWString(_result).toUtf8()')
        ->and($result->cpp('QWideStringHolder'))->toContain('QString::fromUtf8(ZSTR_VAL(s), (int)ZSTR_LEN(s)).toStdU16String()')
        ->and($result->cpp('QWideStringHolder'))->toContain('QString::fromStdU16String(_result).toUtf8()')
        ->and($result->cpp('QWideStringHolder'))->toContain('QString::fromUtf8(ZSTR_VAL(s), (int)ZSTR_LEN(s)).toStdU32String()')
        ->and($result->cpp('QWideStringHolder'))->toContain('QString::fromStdU32String(_result).toUtf8()');
});

it('returns qanystringview values via toString', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qanystringviewholder.h',
            'class' => 'QAnyStringViewHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QAnyStringViewHolder',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qanystringviewholder.cpp');
        Assert::assertStringContainsString('QByteArray _utf8 = _result.toString().toUtf8();', $cpp);
        Assert::assertStringNotContainsString('_result.toUtf8()', $cpp);
});

it('skips qchar buffer returns', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qstringbufferholder.h',
            'class' => 'QStringBufferHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QStringBufferHolder,QChar',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('unicode', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('constData', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('unsupported_buffer_return', array_column($payload['skipped_methods'], 'reason_code'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qstringbufferholder.cpp');
        Assert::assertStringContainsString('ZEND_METHOD(Qt_Core_QStringBufferHolder, length)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QStringBufferHolder, unicode)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QStringBufferHolder, constData)', $cpp);
});

it('copies pointer returns for value types', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qvariantpointerholder.h',
            'class' => 'QVariantPointerHolder',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QVariantPointerHolder,QVariant',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qvariantpointerholder.cpp');
        Assert::assertStringContainsString('QVariant * _result = intern->native_ptr->current();', $cpp);
        Assert::assertStringContainsString('object_init_ex(return_value, qt_ce_QVariant);', $cpp);
        Assert::assertStringContainsString('_ret_intern->native_ptr = new QVariant(*_result);', $cpp);
        Assert::assertStringNotContainsString('qt_qvariant_wrap_native', $cpp);
});

it('skips complex returns instead of casting to scalars', function (): void {
        $fixtureRoot = qt_fixture_path('policy-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtCore/qunsupportedtypes.h',
            'class' => 'QUnsupportedTypes',
            '--qt-path' => $fixtureRoot,
            '--module' => 'QtCore',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QUnsupportedTypes',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode);
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
        Assert::assertContains('unsupported_return_type', array_column($payload['skipped_methods'], 'reason_code'));
        Assert::assertContains('begin', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('provider', array_column($payload['skipped_methods'], 'name'));
        Assert::assertContains('values', array_column($payload['skipped_methods'], 'name'));
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qunsupportedtypes.cpp');
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QUnsupportedTypes, begin)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QUnsupportedTypes, provider)', $cpp);
        Assert::assertStringNotContainsString('ZEND_METHOD(Qt_Core_QUnsupportedTypes, values)', $cpp);
});

it('uses move construction for move only value object returns', function (): void {
        $fixtureRoot = qt_fixture_path('qml-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtQml/qjsmanagedvalue.h',
            'class' => 'QJSManagedValue',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtQml',
            ],
            '--module' => 'QtQml',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QJSManagedValue,QJSEngine',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qjsmanagedvalue.cpp');
        Assert::assertStringContainsString('_ret_intern->native_ptr = new QJSManagedValue(std::move(_result));', $cpp);
});

it('uses a move aware bridge for rvalue reference object parameters', function (): void {
        $fixtureRoot = qt_fixture_path('qml-qt');
        $outputDir = sys_get_temp_dir() . '/qtbuilder-generate-' . bin2hex(random_bytes(4));
    
        $command = new GenerateCommand(FakeSystemInformation::passing());
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'header' => $fixtureRoot . '/include/QtQml/qjsvalue.h',
            'class' => 'QJSValue',
            '--qt-path' => '/definitely/not/a/qt/root',
            '--include' => [
                $fixtureRoot . '/include',
                $fixtureRoot . '/include/QtQml',
            ],
            '--module' => 'QtQml',
            '--build-mode' => true,
            '--output' => $outputDir,
            '--output-subdir' => 'classes',
            '--allowed-classes' => 'QJSValue,QJSManagedValue,QJSPrimitiveValue,QJSEngine',
        ]);
    
        Assert::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame('ok', $payload['status']);
    
        $cpp = (string) file_get_contents($outputDir . '/classes/qt_qjsvalue.cpp');
        Assert::assertStringContainsString('QJSPrimitiveValue _qt_arg_0 = QJSPrimitiveValue(*qt_qjsprimitivevalue_from_obj(Z_OBJ_P(value))->native_ptr);', $cpp);
        Assert::assertStringContainsString('new QJSValue(std::move(_qt_arg_0));', $cpp);
        Assert::assertStringContainsString('QJSManagedValue _qt_arg_0 = QJSManagedValue(qt_qjsmanagedvalue_from_obj(Z_OBJ_P(value))->native_ptr->toJSValue(), qt_qjsmanagedvalue_from_obj(Z_OBJ_P(value))->native_ptr->engine());', $cpp);
        Assert::assertStringContainsString('new QJSValue(std::move(_qt_arg_0));', $cpp);
});
