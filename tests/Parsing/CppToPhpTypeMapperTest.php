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
