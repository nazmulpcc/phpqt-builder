<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Xml\QDomDocument;

qt_runtime_require_class(QDomDocument::class, 'QtXml classes are unavailable in this build.');

// Build a document programmatically and serialise it.
$doc = new QDomDocument('inventory');

$root   = $doc->createElement('inventory');
$item   = $doc->createElement('item');
$nameEl = $doc->createElement('name');
$nameEl->appendChild($doc->createTextNode('Blue Widget'));
$item->appendChild($nameEl);
$root->appendChild($item);
$doc->appendChild($root);

$xml = $doc->toString(2);

// Re-parse and navigate — firstChildElement() segfaults, use firstChild()->toElement().
$doc2  = new QDomDocument();
$doc2->setContent($xml, false);

$root2  = $doc2->documentElement();
$itemEl = $root2->firstChild()->toElement();
$nameEl2 = $itemEl->firstChild()->toElement();

qt_runtime_result([
    'root_tag'          => $root2->tagName(),
    'root_has_child'    => $root2->hasChildNodes(),
    'item_tag'          => $itemEl->tagName(),
    'item_is_element'   => $itemEl->isElement(),
    'name_tag'          => $nameEl2->tagName(),
    'name_text'         => $nameEl2->text(),
    'xml_has_inventory' => str_contains($xml, '<inventory'),
    'xml_has_widget'    => str_contains($xml, 'Blue Widget'),
]);
