<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Core\QFileSystemWatcher::class, 'QtCore container and stream classes are unavailable in this build.');

$tempRoot = sys_get_temp_dir() . '/phpqt-runtime-' . bin2hex(random_bytes(4));
mkdir($tempRoot, 0777, true);
$watchFile = $tempRoot . '/sales-feed.log';
file_put_contents($watchFile, "sales-eu,1280\nsales-us,1540\n");

$watcher = new \Qt\Core\QFileSystemWatcher();
$watcher->addPaths([$tempRoot, $watchFile]);
$filesBefore = $watcher->files();
$dirsBefore = $watcher->directories();
$watcher->removePaths([$watchFile]);
$filesAfter = $watcher->files();

$process = new \Qt\Core\QProcess();
$process->setProgram('/bin/echo');
$process->setArguments(['sales-sync', '--tenant=acme', '--batch=25']);

$environment = \Qt\Core\QProcessEnvironment::systemEnvironment();
$environment->insert('PHPQT_DEMO_MODE', 'containers');

$buffer = new \Qt\Core\QBuffer();
$buffer->setData("sales-eu,1280\nsales-us,1540\n");
$buffer->open(1);
$stream = new \Qt\Core\QTextStream($buffer);
$firstLine = $stream->readLine();
$rest = trim($stream->readAll());

$debugBuffer = new \Qt\Core\QBuffer();
$debug = new \Qt\Core\QDebug($debugBuffer);
$debug->setVerbosity(5);
$debug->setAutoInsertSpaces(false);
$debug->setQuoteStrings(false);

qt_runtime_result([
    'watch_file_count_before' => count($filesBefore),
    'watch_dir_count_before' => count($dirsBefore),
    'watch_file_count_after' => count($filesAfter),
    'program' => $process->program(),
    'argument_count' => count($process->arguments()),
    'env_has_demo_mode' => in_array('PHPQT_DEMO_MODE=containers', $environment->toStringList(), true),
    'env_key_count' => count($environment->keys()),
    'first_line' => $firstLine,
    'rest' => $rest,
    'debug_verbosity' => $debug->verbosity(),
    'debug_auto_insert_spaces' => $debug->autoInsertSpaces(),
    'debug_quote_strings' => $debug->quoteStrings(),
]);
