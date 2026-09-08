<?php

declare(strict_types=1);

use QtBuilder\CodeGen\TypeBridge;

it('converts multimap containers through pair-array semantics', function (): void {
    $bridge = new TypeBridge();

    $toPhp = $bridge->nativeContainerToPhpZvalBlock('return_value', 'QMultiHash<int, QString>', '_result');
    expect($toPhp)->toContain('array_init_size(return_value, (uint32_t)_result.size());')
        ->toContain('array_init_size(&_qt_pair, 2);')
        ->toContain('add_next_index_zval(return_value, &_qt_pair);');

    $fromPhp = $bridge->nativeReturnFromZvalSetup('array', 'QMultiHash<int, QString>', '_zv');
    expect($fromPhp['expr'])->toBe('_qt_ret')
        ->and(implode("\n", $fromPhp['lines']))->toContain('Expected array entries to be [key, value] pairs.')
        ->toContain('_qt_ret.insert(_qt_ret_key, _qt_ret_value);');
});

it('uses distinct key/value zend_string temporaries for pair-sequence string conversions', function (): void {
    $bridge = new TypeBridge();

    $fromPhp = $bridge->nativeReturnFromZvalSetup('array', 'QMultiHash<QByteArray, QByteArray>', '_zv');
    $generated = implode("\n", $fromPhp['lines']);

    expect($generated)->toContain('zend_string *_qt_ret_key_str = zval_get_string(_qt_ret_key_entry);')
        ->toContain('zend_string_release(_qt_ret_key_str);')
        ->toContain('zend_string *_qt_ret_value_str = zval_get_string(_qt_ret_value_entry);')
        ->toContain('zend_string_release(_qt_ret_value_str);')
        ->not->toContain('zend_string *_qt_ret_str = zval_get_string(_qt_ret_key_entry);')
        ->not->toContain('zend_string *_qt_ret_str = zval_get_string(_qt_ret_value_entry);');
});

it('accepts object keys for pair-sequence container conversions', function (): void {
    $bridge = new TypeBridge();
    $bridge->setTypeResolutionMetadata('Qt\\Bluetooth', [
        'qbluetoothuuid' => [
            'name' => 'QBluetoothUuid',
            'namespace' => 'Qt\\Bluetooth',
            'generation_id' => 'qbluetoothuuid',
            'qualified_name' => 'QBluetoothUuid',
        ],
    ], [], 'QBluetoothDeviceInfo');

    $fromPhp = $bridge->nativeReturnFromZvalSetup('array', 'QMultiHash<QBluetoothUuid, QByteArray>', '_zv');
    $generated = implode("\n", $fromPhp['lines']);

    expect($generated)->toContain('instanceof_function(Z_OBJCE_P(_qt_ret_key_entry), qt_ce_qbluetoothuuid)')
        ->toContain('_qt_ret_key = *qt_qbluetoothuuid_from_obj(Z_OBJ_P(_qt_ret_key_entry))->native_ptr;')
        ->toContain('Expected pair key type QBluetoothUuid.');
});

it('borrows signal refs only for qobject-derived class types', function (): void {
    $bridge = new TypeBridge();
    $bridge->setTypeResolutionMetadata('Qt\\Bluetooth', [
        'qbluetoothdeviceinfo' => [
            'name' => 'QBluetoothDeviceInfo',
            'namespace' => 'Qt\\Bluetooth',
            'generation_id' => 'qbluetoothdeviceinfo',
            'qualified_name' => 'QBluetoothDeviceInfo',
            'is_qobject_derived' => false,
        ],
        'qobject' => [
            'name' => 'QObject',
            'namespace' => 'Qt\\Core',
            'generation_id' => 'qobject',
            'qualified_name' => 'QObject',
            'is_qobject_derived' => true,
        ],
    ], [], 'QBluetoothDeviceDiscoveryAgent');

    expect($bridge->signalArgUsesBorrowedWrap('\\Qt\\Bluetooth\\QBluetoothDeviceInfo', 'const QBluetoothDeviceInfo &'))->toBeFalse()
        ->and($bridge->signalArgUsesBorrowedWrap('\\Qt\\Core\\QObject', 'QObject &'))->toBeTrue()
        ->and($bridge->signalArgMarshallingMode('\\Qt\\Core\\QObject', 'QObject &'))->toBe('borrowed_qobject_snapshot')
        ->and($bridge->signalArgMarshallingMode('int', 'int'))->toBe('by_value');
});

it('converts int128 values through decimal strings', function (): void {
    $bridge = new TypeBridge();

    $returnBlock = $bridge->nativeStringToPhpReturn('quint128', '_result');
    expect($returnBlock)->toContain('quint128 _qt_raw = _result;')
        ->toContain('RETURN_STRINGL(_qt_decimal.constData(), _qt_decimal.size())');

    $fromPhp = $bridge->nativeReturnFromZvalSetup('string', 'quint128', '_zv');
    expect($fromPhp['expr'])->toContain('([&]() -> quint128');
});

it('converts php strings to qanystringview with an explicit qanystringview wrapper', function (): void {
    $bridge = new TypeBridge();

    $setup = $bridge->nativeArgumentSetup('string', 'QAnyStringView', 'text', '_qt_arg_0');
    expect($setup['expr'])->toContain('QAnyStringView(QString::fromUtf8(ZSTR_VAL(text), (int)ZSTR_LEN(text)))');
});

it('qualifies bare nested container element types using the current owner context', function (): void {
    $bridge = new TypeBridge();
    $bridge->setTypeResolutionMetadata('Qt\\Gui', [
        'qfont' => [
            'name' => 'QFont',
            'namespace' => 'Qt\\Gui',
            'generation_id' => 'qfont',
            'qualified_name' => 'QFont',
        ],
        'tag' => [
            'name' => 'Tag',
            'namespace' => 'Qt\\Gui\\QFont',
            'generation_id' => 'tag__qfont',
            'qualified_name' => 'QFont::Tag',
        ],
    ], [], 'QFont');

    $block = $bridge->nativeContainerToPhpZvalBlock('return_value', 'QList<Tag>', '_result');
    expect($block)->toContain('new QFont::Tag(_qt_item)');
});

it('resolves nested container class references to canonical c++ class keys', function (): void {
    $bridge = new TypeBridge();
    $bridge->setTypeResolutionMetadata('Qt\\Gui', [
        'qfont' => [
            'name' => 'QFont',
            'namespace' => 'Qt\\Gui',
            'generation_id' => 'qfont',
            'qualified_name' => 'QFont',
        ],
        'tag' => [
            'name' => 'Tag',
            'namespace' => 'Qt\\Gui\\QFont',
            'generation_id' => 'tag__qfont',
            'qualified_name' => 'QFont::Tag',
        ],
    ], [], 'QFont');

    expect($bridge->containerClassRefs('QList<Tag>'))->toBe(['QFont::Tag']);
});

it('resolves generation ids for nested php fqcns via class metadata', function (): void {
    $bridge = new TypeBridge();
    $bridge->setTypeResolutionMetadata('Qt\\Gui', [
        'qfont' => [
            'name' => 'QFont',
            'namespace' => 'Qt\\Gui',
            'generation_id' => 'qfont',
            'qualified_name' => 'QFont',
        ],
        'tag' => [
            'name' => 'Tag',
            'namespace' => 'Qt\\Gui\\QFont',
            'generation_id' => 'tag__qfont',
            'qualified_name' => 'QFont::Tag',
        ],
    ], [], 'QFont');

    expect($bridge->ceVarName('\\Qt\\Gui\\QFont\\Tag'))->toBe('qt_ce_tag__qfont');
});

it('resolves ambiguous bare nested type helpers against the current owner context', function (): void {
    $bridge = new TypeBridge();
    $bridge->setTypeResolutionMetadata('Qt\\Core', [
        'qjsonarray' => [
            'name' => 'QJsonArray',
            'namespace' => 'Qt\\Core',
            'generation_id' => 'qjsonarray',
            'qualified_name' => 'QJsonArray',
        ],
        'iterator__qjsonarray' => [
            'name' => 'iterator',
            'namespace' => 'Qt\\Core\\QJsonArray',
            'generation_id' => 'iterator__qjsonarray',
            'qualified_name' => 'QJsonArray::iterator',
        ],
        'iterator__qdirlisting' => [
            'name' => 'iterator',
            'namespace' => 'Qt\\Core\\QDirListing',
            'generation_id' => 'iterator__qdirlisting',
            'qualified_name' => 'QDirListing::iterator',
        ],
    ], [], 'QJsonArray');

    expect($bridge->fromObjFuncName('iterator'))->toBe('qt_iterator__qjsonarray_from_obj');
});

it('maps owner-scoped enum-like container elements to scalar arrays', function (): void {
    $bridge = new TypeBridge();
    $bridge->setTypeResolutionMetadata('Qt\\Multimedia', [
        'qmediametadata' => [
            'name' => 'QMediaMetaData',
            'namespace' => 'Qt\\Multimedia',
            'generation_id' => 'qmediametadata',
            'qualified_name' => 'QMediaMetaData',
        ],
        'key__qpixmapcache' => [
            'name' => 'Key',
            'namespace' => 'Qt\\Gui\\QPixmapCache',
            'generation_id' => 'key__qpixmapcache',
            'qualified_name' => 'QPixmapCache::Key',
        ],
    ], [], 'QMediaMetaData');

    expect($bridge->containerClassRefs('QList<Key>'))->toBe([]);
    $block = $bridge->nativeContainerToPhpZvalBlock('return_value', 'QList<Key>', '_result');
    expect($block)->toContain('ZVAL_LONG(&_qt_value, (zend_long)(_qt_item));')
        ->toContain('add_next_index_zval(return_value, &_qt_value);')
        ->not->toContain('object_init_ex');
});

it('escapes fqcn backslashes in generated container type-error strings', function (): void {
    $bridge = new TypeBridge();
    $bridge->setTypeResolutionMetadata('Qt\\WebSockets', [
        'qsslerror' => [
            'name' => 'QSslError',
            'namespace' => 'Qt\\Network',
            'generation_id' => 'qsslerror',
            'qualified_name' => 'QSslError',
        ],
    ], [], 'QWebSocket');

    $fromPhp = $bridge->nativeReturnFromZvalSetup('array', 'QList<QSslError>', 'errors');
    $generated = implode("\n", $fromPhp['lines']);

    expect($generated)->toContain('Expected array of \\\\Qt\\\\Network\\\\QSslError objects.')
        ->not->toContain('Expected array of \\Qt\\Network\\QSslError objects.');
});

it('supports static return type for fluent same-class return strategy', function (): void {
    $bridge = new TypeBridge();

    expect($bridge->returnStrategyForCpp('static', 'QString &'))->toBe('this')
        ->and($bridge->stubType('static', false, 'Qt\\Core', ['QString' => 'Qt\\Core']))->toBe('static')
        ->and($bridge->zendTypeConstant('static'))->toBe('IS_STATIC')
        ->and($bridge->mayBeConstant('static'))->toBe('MAY_BE_STATIC')
        ->and($bridge->defaultNativeReturnExpr('static', 'QString &'))->toBe('*this');
});
