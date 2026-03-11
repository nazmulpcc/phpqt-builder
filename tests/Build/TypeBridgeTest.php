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

it('resolves nested container class references to module-correct php fqcns', function (): void {
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

    expect($bridge->containerClassRefs('QList<Tag>'))->toBe(['\\Qt\\Gui\\QFont\\Tag']);
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
