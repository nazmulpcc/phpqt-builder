<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\TextToSpeech\QTextToSpeech;

qt_runtime_require_class(QTextToSpeech::class, 'QtTextToSpeech classes are unavailable in this build.');

$engines = QTextToSpeech::availableEngines();

$tts = new QTextToSpeech();

$state = $tts->state();
$rate  = $tts->rate();
$pitch = $tts->pitch();
$volume = $tts->volume();

qt_runtime_result([
    'engines_is_array'   => is_array($engines),
    'state_is_int'       => is_int($state),
    'rate_is_numeric'    => is_float($rate) || is_int($rate),
    'pitch_is_numeric'   => is_float($pitch) || is_int($pitch),
    'volume_in_range'    => $volume >= 0.0 && $volume <= 1.0,
]);
