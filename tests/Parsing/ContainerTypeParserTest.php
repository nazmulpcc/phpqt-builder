<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Parsing;

use PHPUnit\Framework\TestCase;
use QtBuilder\Parsing\ContainerTypeParser;

final class ContainerTypeParserTest extends TestCase
{
    public function testParseRecognizesCommonQtContainers(): void
    {
        $parser = new ContainerTypeParser();

        $stringList = $parser->parse('const QStringList &');
        self::assertNotNull($stringList);
        self::assertSame('sequence', $stringList->kind);
        self::assertSame('QString', $stringList->elementType);

        $indexList = $parser->parse('QModelIndexList');
        self::assertNotNull($indexList);
        self::assertSame('sequence', $indexList->kind);
        self::assertSame('QModelIndex', $indexList->elementType);

        $byteMap = $parser->parse('QHash<int, QByteArray>');
        self::assertNotNull($byteMap);
        self::assertSame('hash', $byteMap->kind);
        self::assertSame('int', $byteMap->keyType);
        self::assertSame('QByteArray', $byteMap->valueType);

        $variantMap = $parser->parse('QMap<int, QVariant>');
        self::assertNotNull($variantMap);
        self::assertSame('map', $variantMap->kind);
        self::assertSame('int', $variantMap->keyType);
        self::assertSame('QVariant', $variantMap->valueType);

        $actionList = $parser->parse('const QList<QAction *> &');
        self::assertNotNull($actionList);
        self::assertSame('sequence', $actionList->kind);
        self::assertSame('QAction *', $actionList->elementType);

        $constActionList = $parser->parse('const QList<const QAction *> &');
        self::assertNotNull($constActionList);
        self::assertSame('sequence', $constActionList->kind);
        self::assertSame('const QAction *', $constActionList->elementType);
    }

    public function testParseRejectsUnsupportedContainerShapes(): void
    {
        $parser = new ContainerTypeParser();

        self::assertNull($parser->parse('QSet<QString>'));

        $complexList = $parser->parse('QList<std::pair<qreal, QPointF>>');
        self::assertNotNull($complexList);
        self::assertSame('std::pair<qreal, QPointF>', $complexList->elementType);
    }
}
