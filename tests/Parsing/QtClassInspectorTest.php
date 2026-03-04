<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Parsing;

use PHPUnit\Framework\TestCase;
use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;

final class QtClassInspectorTest extends TestCase
{
    public function testInspectDetectsSignalAndSlotMethodsViaAnnotations(): void
    {
        if (!method_exists(\CParser\Cursor::class, 'getAnnotations')) {
            self::markTestSkipped('ext-cparser does not expose cursor annotations.');
        }

        $fixtureRoot = dirname(__DIR__) . '/Fixtures/signals-qt';
        $includeRoot = $fixtureRoot . '/include';
        $header = $includeRoot . '/QtCore/qsignalfixture.h';

        $inspector = new QtClassInspector(new ClangArgumentBuilder([
            $includeRoot,
            $includeRoot . '/QtCore',
        ]));

        $classData = $inspector->inspect($header, 'QSignalFixture');
        self::assertNotNull($classData);

        $methods = [];
        foreach ($classData['methods'] as $method) {
            $methods[$method['name']] = $method;
        }

        self::assertArrayHasKey('plainMethod', $methods);
        self::assertArrayHasKey('setValue', $methods);
        self::assertArrayHasKey('resetValue', $methods);
        self::assertArrayHasKey('triggered', $methods);
        self::assertArrayHasKey('valueChanged', $methods);

        self::assertFalse($methods['plainMethod']['is_signal']);
        self::assertFalse($methods['plainMethod']['is_slot']);

        self::assertFalse($methods['setValue']['is_signal']);
        self::assertTrue($methods['setValue']['is_slot']);
        self::assertSame('public', $methods['setValue']['access']);

        self::assertFalse($methods['resetValue']['is_signal']);
        self::assertTrue($methods['resetValue']['is_slot']);
        self::assertSame('protected', $methods['resetValue']['access']);

        self::assertTrue($methods['triggered']['is_signal']);
        self::assertFalse($methods['triggered']['is_slot']);
        self::assertSame('public', $methods['triggered']['access']);

        self::assertTrue($methods['valueChanged']['is_signal']);
        self::assertFalse($methods['valueChanged']['is_slot']);
    }

    public function testInspectDetectsFinalMethodViaCursorKind404(): void
    {
        $fixtureRoot = dirname(__DIR__) . '/Fixtures/policy-qt';
        $includeRoot = $fixtureRoot . '/include';
        $header = $includeRoot . '/QtCore/qfinalvirtualthing.h';

        $inspector = new QtClassInspector(new ClangArgumentBuilder([
            $includeRoot,
            $includeRoot . '/QtCore',
        ]));

        $classData = $inspector->inspect($header, 'QFinalVirtualThing');
        self::assertNotNull($classData);

        $methods = [];
        foreach ($classData['methods'] as $method) {
            $methods[$method['name']] = $method;
        }

        self::assertArrayHasKey('value', $methods);
        self::assertTrue($methods['value']['is_virtual']);
        self::assertTrue($methods['value']['is_final']);
    }
}
