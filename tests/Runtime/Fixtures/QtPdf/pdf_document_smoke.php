<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Qt\Pdf\QPdfDocument;

qt_runtime_require_class(QPdfDocument::class, 'QtPdf classes are unavailable in this build.');

$doc = new QPdfDocument();

$initialStatus = $doc->status();
$initialPages  = $doc->pageCount();

$error = $doc->load('/nonexistent/path/file.pdf');

qt_runtime_result([
    'initial_status'    => $initialStatus,
    'initial_pages'     => $initialPages,
    'load_error_is_int' => is_int($error),
    'load_failed'       => $error !== QPdfDocument::None,
    'status_after_fail' => $doc->status(),
]);
