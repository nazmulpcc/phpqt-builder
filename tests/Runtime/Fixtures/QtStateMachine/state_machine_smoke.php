<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Core\QCoreApplication;
use Qt\StateMachine\QFinalState;
use Qt\StateMachine\QSignalTransition;
use Qt\StateMachine\QState;
use Qt\StateMachine\QStateMachine;

qt_runtime_require_class(QStateMachine::class, 'QtStateMachine classes are unavailable in this build.');

$app = new QCoreApplication();

$machine = new QStateMachine();

$s1 = new QState($machine);
$s1->setObjectName('StateA');

$s2 = new QState($machine);
$s2->setObjectName('StateB');

$final = new QFinalState($machine);
$final->setObjectName('Final');

$machine->setInitialState($s1);

// Use QSignalTransition::setTargetState() — targetState() returns QAbstractState
// (abstract) so we only set and never read back via the transition.
$t1 = new QSignalTransition();
$t1->setTargetState($s2);
$s1->addTransition($t1);

$t2 = new QSignalTransition();
$t2->setTargetState($final);
$s2->addTransition($t2);

$machine->start();

for ($i = 0; $i < 5; $i++) {
    QCoreApplication::processEvents();
}

qt_runtime_result([
    'is_running'         => $machine->isRunning(),
    'initial_state_name' => $s1->objectName(),
    's1_name'            => $s1->objectName(),
    's2_name'            => $s2->objectName(),
    'final_name'         => $final->objectName(),
    'is_animated'        => $machine->isAnimated(),
]);
