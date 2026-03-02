<?php

use Qt\Core\QCoreApplication;

$app = new QCoreApplication($argc, $argv);

$object = new Qt\Core\QObject();
$object->setObjectName("TestObject");
echo "Object Name: " . $object->objectName() . PHP_EOL;
// echo "Is Widget Type: " . ($object->isWidgetType() ? "Yes" : "No") . PHP_EOL;
// echo "Is Window Type: " . ($object->isWindowType() ? "Yes" : "No") . PHP_EOL;
// echo "Is Quick Item Type: " . ($object->isQuickItemType() ? "Yes" : "No") . PHP_EOL;
// echo "Signals Blocked: " . ($object->signalsBlocked() ? "Yes" : "No") . PHP_EOL;
// echo "Thread: " . $object->thread() . PHP_EOL;

// QCoreApplication::exec();