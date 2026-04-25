<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Qt\Core\QThread;
use Qt\Core\QTimer;
use Qt\Core\QMetaObjectConnection;
use Qt\Core\QPhpSignalConnection;
use Qt\Core\QObject;
use Qt\Widgets\QApplication;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QLineEdit;
use Qt\Widgets\QProgressBar;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

/**
 * @param list<string> $classes
 */
function assert_classes_available(array $classes): void
{
    foreach ($classes as $class) {
        if (!class_exists($class)) {
            throw new RuntimeException(sprintf('Required class is not available: %s', $class));
        }
    }
}

function runtime_bootstrap_script(): string
{
    return __DIR__ . '/worker_runtime_bootstrap.php';
}

function infer_filename_from_url(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $name = basename($path);

    if ($name === '' || $name === '/' || $name === '.') {
        return 'download.bin';
    }

    return $name;
}

/**
 * @return array{session_dir:string,final_output_path:string,part_paths:list<string>}
 */
function build_session_paths(string $url, int $threads): array
{
    $name = infer_filename_from_url($url);
    $sessionId = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $sessionDir = example_repo_root() . '/build/multithread-downloader/' . $sessionId;
    $partsDir = $sessionDir . '/parts';
    $outputDir = $sessionDir . '/output';

    if (!mkdir($partsDir, 0777, true) && !is_dir($partsDir)) {
        throw new RuntimeException('Could not create parts directory: ' . $partsDir);
    }

    if (!mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
        throw new RuntimeException('Could not create output directory: ' . $outputDir);
    }

    $partPaths = [];
    for ($index = 0; $index < $threads; $index++) {
        $partPaths[] = sprintf('%s/part_%02d.bin', $partsDir, $index);
    }

    return [
        'session_dir' => $sessionDir,
        'final_output_path' => $outputDir . '/' . $name,
        'part_paths' => $partPaths,
    ];
}

example_section('Multithread Downloader');

if (!extension_loaded('curl')) {
    example_fail('The curl extension is required for this example.');
}

try {
    assert_classes_available([
        QApplication::class,
        QWidget::class,
        QTimer::class,
        QThread::class,
    ]);
} catch (Throwable $throwable) {
    example_fail($throwable->getMessage() . ' Build with QtCore and QtWidgets modules.');
}

if (!is_file(runtime_bootstrap_script())) {
    example_fail('Missing worker bootstrap script: ' . runtime_bootstrap_script());
}

$app = new QApplication();
$window = new QWidget();
$window->resize(860, 340);
$window->setWindowTitle('Multithread Downloader');
$window->setStyleSheet(<<<'CSS'
QWidget { background: #f8fafc; color: #0f172a; font-size: 13px; }
QLabel[role="title"] { font-size: 20px; font-weight: 700; }
QLineEdit { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 7px 9px; }
QPushButton { background: #2563eb; color: #ffffff; border: none; border-radius: 8px; padding: 8px 14px; }
QPushButton:disabled { background: #93c5fd; color: #e2e8f0; }
QProgressBar { background: #e2e8f0; border: 1px solid #cbd5e1; border-radius: 8px; text-align: center; min-height: 18px; }
QProgressBar::chunk { background: #2563eb; border-radius: 7px; }
CSS);

$root = new QVBoxLayout();
$root->setSpacing(10);
$root->setContentsMargins(18, 18, 18, 18);

$title = new QLabel('Multithread Downloader');
$title->setProperty('role', 'title');

$desc = new QLabel('A simple event-driven downloader: probe once, start one worker per byte range, then merge the parts when all workers finish.');
$desc->setWordWrap(true);

$urlInput = new QLineEdit();
$urlInput->setText('https://proof.ovh.net/files/10Mb.dat');

$threadInput = new QLineEdit();
$threadInput->setText('4');
$threadInput->setMaximumWidth(100);

$downloadButton = new QPushButton('Download');

$formRow = new QHBoxLayout();
$formRow->addWidget(new QLabel('URL'));
$formRow->addWidget($urlInput, 1);
$formRow->addWidget(new QLabel('Threads'));
$formRow->addWidget($threadInput);
$formRow->addWidget($downloadButton);

$statusLabel = new QLabel('Status: idle');
$overallBar = new QProgressBar();
$overallBar->setRange(0, 100);
$overallBar->setValue(0);

$outputLabel = new QLabel('Output: (none)');
$outputLabel->setWordWrap(true);

$workersTitle = new QLabel('Workers');
$workersTitle->setVisible(false);

$workersWidget = new QWidget();
$workersLayout = new QVBoxLayout();
$workersLayout->setSpacing(6);
$workersLayout->setContentsMargins(0, 0, 0, 0);
$workersWidget->setLayout($workersLayout);
$workersWidget->setVisible(false);
$workersLayout->addStretch(1);

$root->addWidget($title);
$root->addWidget($desc);
$root->addLayout($formRow);
$root->addWidget($statusLabel);
$root->addWidget($overallBar);
$root->addWidget($outputLabel);
$root->addWidget($workersTitle);
$root->addWidget($workersWidget);
$root->addStretch(1);
$window->setLayout($root);

$pumpTimer = new QTimer($window);
$pumpTimer->setInterval(16);

$appendLog = static function (string $line): void {
    fwrite(STDOUT, sprintf("[%s] %s\n", date('H:i:s'), $line));
};

$resizeForWorkers = static function (int $count) use ($window): void {
    $baseHeight = 340;
    $extraHeight = $count > 0 ? 36 + ($count * 30) : 0;
    $window->resize(860, $baseHeight + $extraHeight);
};

/** @var list<array{widget:QWidget,label:QLabel,bar:QProgressBar}> $workerRows */
$workerRows = [];

$clearWorkerRows = static function () use (&$workerRows, $workersLayout, $workersTitle, $workersWidget, $resizeForWorkers): void {
    foreach ($workerRows as $row) {
        $workersLayout->removeWidget($row['widget']);
        $row['widget']->setVisible(false);
    }

    $workerRows = [];
    $workersTitle->setVisible(false);
    $workersWidget->setVisible(false);
    $resizeForWorkers(0);
};

$showWorkerRows = static function (int $count) use (&$workerRows, $workersLayout, $workersTitle, $workersWidget, $resizeForWorkers, $clearWorkerRows): void {
    $clearWorkerRows();
    if ($count <= 0) {
        return;
    }

    $workersTitle->setVisible(true);
    $workersWidget->setVisible(true);
    $resizeForWorkers($count);

    for ($index = 0; $index < $count; $index++) {
        $rowWidget = new QWidget();
        $rowLayout = new QHBoxLayout();
        $rowLayout->setContentsMargins(0, 0, 0, 0);
        $rowWidget->setLayout($rowLayout);

        $label = new QLabel(sprintf('Worker %d: queued', $index + 1));
        $label->setMinimumWidth(180);

        $bar = new QProgressBar();
        $bar->setRange(0, 100);
        $bar->setValue(0);

        $rowLayout->addWidget($label);
        $rowLayout->addWidget($bar, 1);
        $workersLayout->insertWidget($index, $rowWidget);

        $workerRows[] = [
            'widget' => $rowWidget,
            'label' => $label,
            'bar' => $bar,
        ];
    }
};

$setWorkerProgress = static function (int $index, string $label, int $percent) use (&$workerRows): void {
    if (!isset($workerRows[$index])) {
        return;
    }

    $workerRows[$index]['label']->setText($label);
    $workerRows[$index]['bar']->setValue($percent);
};

$busy = false;
$stage = 'idle';
$sourceUrl = '';
$sessionDir = '';
$finalOutputPath = '';
$requestedThreads = 0;
$totalBytes = 0;
/** @var list<string> $partPaths */
$partPaths = [];
/** @var array{thread:QThread,listeners:list<int|QMetaObjectConnection|QPhpSignalConnection>,resolved:bool}|null $probeTask */
$probeTask = null;
/** @var array<int, array{thread:QThread,listeners:list<int|QMetaObjectConnection|QPhpSignalConnection>,resolved:bool,total:int,downloaded:int,path:string}> $downloadTasks */
$downloadTasks = [];
/** @var array{thread:QThread,listeners:list<int|QMetaObjectConnection|QPhpSignalConnection>,resolved:bool}|null $mergeTask */
$mergeTask = null;
/** @var array{message:string}|null $pendingFailure */
$pendingFailure = null;
/** @var array{output:string,session:string}|null $pendingSuccess */
$pendingSuccess = null;

$stopTask = static function (?array &$task, int $timeoutMs = 2000): void {
    if ($task === null) {
        return;
    }

    $thread = $task['thread'] ?? null;
    if (!$thread instanceof QThread) {
        $task = null;
        return;
    }

    try {
        $thread->requestInterruption();
    } catch (Throwable) {
    }

    foreach ($task['listeners'] ?? [] as $listener) {
        try {
            if ($listener instanceof QMetaObjectConnection) {
                QObject::disconnect($listener);
                continue;
            }

            $thread->off($listener);
        } catch (Throwable) {
        }
    }

    try {
        $thread->wait($timeoutMs);
    } catch (Throwable) {
    }

    $task = null;
};

$cleanupAllTasks = static function (int $timeoutMs = 2000) use (&$probeTask, &$downloadTasks, &$mergeTask, $stopTask): void {
    $stopTask($probeTask, $timeoutMs);

    foreach ($downloadTasks as &$task) {
        $localTask = $task;
        $stopTask($localTask, $timeoutMs);
    }
    unset($task);
    $downloadTasks = [];

    $stopTask($mergeTask, $timeoutMs);
};

$updateOverallProgress = static function () use (&$downloadTasks, &$totalBytes, $overallBar): void {
    if ($totalBytes <= 0) {
        $overallBar->setValue(0);
        return;
    }

    $downloaded = 0;
    foreach ($downloadTasks as $task) {
        $downloaded += $task['downloaded'];
    }

    $overallBar->setValue((int) round(min(100.0, ($downloaded / $totalBytes) * 100.0)));
};

$finalizeFailure = static function () use (
    &$busy,
    &$stage,
    &$pendingFailure,
    $pumpTimer,
    $cleanupAllTasks,
    $downloadButton,
    $statusLabel,
    $clearWorkerRows,
    $appendLog
): void {
    $message = $pendingFailure['message'] ?? 'Download failed.';
    $pendingFailure = null;
    $pumpTimer->stop();
    $busy = false;
    $stage = 'idle';
    $cleanupAllTasks();
    $clearWorkerRows();
    $downloadButton->setEnabled(true);
    $statusLabel->setText('Status: failed');
    $appendLog($message);
};

$finalizeSuccess = static function () use (
    &$busy,
    &$stage,
    &$partPaths,
    &$pendingSuccess,
    $pumpTimer,
    $cleanupAllTasks,
    $clearWorkerRows,
    $downloadButton,
    $statusLabel,
    $overallBar,
    $outputLabel,
    $appendLog
): void {
    $outputPath = $pendingSuccess['output'] ?? '';
    $sessionPath = $pendingSuccess['session'] ?? '';
    $pendingSuccess = null;

    foreach ($partPaths as $path) {
        @unlink($path);
    }

    $pumpTimer->stop();
    $busy = false;
    $stage = 'idle';
    $cleanupAllTasks();
    $clearWorkerRows();
    $downloadButton->setEnabled(true);
    $statusLabel->setText('Status: completed');
    $overallBar->setValue(100);
    $outputLabel->setText('Output: ' . $outputPath);
    $appendLog('Download complete: ' . $outputPath);
    $appendLog('Session: ' . $sessionPath);
};

$startMerge = null;
$startDownloads = null;

$startMerge = static function () use (
    &$busy,
    &$stage,
    &$mergeTask,
    &$partPaths,
    &$finalOutputPath,
    &$sessionDir,
    &$pendingFailure,
    &$pendingSuccess,
    $statusLabel,
    $appendLog
): void {
    $stage = 'merge';
    $statusLabel->setText('Status: merging parts...');
    $appendLog('All workers finished. Starting merge.');

    $thread = new QThread(null, runtime_bootstrap_script());
    $mergeTask = [
        'thread' => $thread,
        'listeners' => [],
        'resolved' => false,
    ];

    $mergeTask['listeners'][] = $thread->on('result', static function (array $event) use (&$busy, &$stage, &$mergeTask, &$pendingFailure, &$pendingSuccess, &$finalOutputPath, &$sessionDir): void {
        if (!$busy || $stage !== 'merge' || $mergeTask === null) {
            return;
        }

        $payload = $event['payload'] ?? null;
        if (!is_array($payload) || !($payload['ok'] ?? false)) {
            $pendingFailure = [
                'message' => is_array($payload) ? (string) ($payload['error'] ?? 'Merge failed.') : 'Merge failed.',
            ];
            return;
        }

        $mergeTask['resolved'] = true;
        $pendingSuccess = [
            'output' => $finalOutputPath,
            'session' => $sessionDir,
        ];
    });

    $mergeTask['listeners'][] = $thread->onFinished(static function () use (&$busy, &$stage, &$mergeTask, &$pendingFailure): void {
        if (!$busy || $stage !== 'merge' || $mergeTask === null || ($mergeTask['resolved'] ?? false)) {
            return;
        }

        $pendingFailure = ['message' => 'Merge worker finished without a result.'];
    });

    $thread->start('MultiThreadDownloaderWorkerTasks::mergeParts', [$partPaths, $finalOutputPath]);
};

$startDownloads = static function (int $threadCount) use (
    &$busy,
    &$stage,
    &$sourceUrl,
    &$totalBytes,
    &$partPaths,
    &$downloadTasks,
    &$pendingFailure,
    $showWorkerRows,
    $setWorkerProgress,
    $statusLabel,
    $appendLog,
    $updateOverallProgress,
    $startMerge
): void {
    $stage = 'download';
    $statusLabel->setText(sprintf('Status: downloading with %d worker(s)...', $threadCount));
    $appendLog(sprintf('Starting %d download worker(s).', $threadCount));
    $showWorkerRows($threadCount);
    $downloadTasks = [];

    $chunkSize = intdiv($totalBytes, $threadCount);

    for ($index = 0; $index < $threadCount; $index++) {
        $start = $index * $chunkSize;
        $end = ($index === $threadCount - 1) ? ($totalBytes - 1) : (($start + $chunkSize) - 1);
        $total = ($end - $start) + 1;
        $path = $partPaths[$index];

        $thread = new QThread(null, runtime_bootstrap_script());
        $downloadTasks[$index] = [
            'thread' => $thread,
            'listeners' => [],
            'resolved' => false,
            'total' => $total,
            'downloaded' => 0,
            'path' => $path,
        ];

        $setWorkerProgress($index, sprintf('Worker %d: downloading bytes %d-%d', $index + 1, $start, $end), 0);

        $downloadTasks[$index]['listeners'][] = $thread->on('progress', static function (array $event) use (&$busy, &$stage, &$downloadTasks, $setWorkerProgress, $updateOverallProgress): void {
            if (!$busy || $stage !== 'download') {
                return;
            }

            $payload = $event['payload'] ?? null;
            if (!is_array($payload)) {
                return;
            }

            $index = (int) ($payload['index'] ?? -1);
            if (!isset($downloadTasks[$index])) {
                return;
            }

            $downloadTasks[$index]['downloaded'] = (int) ($payload['downloaded'] ?? 0);
            $percent = (int) ($payload['percent'] ?? 0);

            $setWorkerProgress(
                $index,
                sprintf(
                    'Worker %d: %d%% (%d/%d)',
                    $index + 1,
                    $percent,
                    $downloadTasks[$index]['downloaded'],
                    $downloadTasks[$index]['total'],
                ),
                $percent,
            );
            $updateOverallProgress();
        });

        $downloadTasks[$index]['listeners'][] = $thread->on('result', static function (array $event) use (&$busy, &$stage, &$downloadTasks, &$pendingFailure, $setWorkerProgress, $updateOverallProgress, $startMerge): void {
            if (!$busy || $stage !== 'download') {
                return;
            }

            $payload = $event['payload'] ?? null;
            if (!is_array($payload)) {
                $pendingFailure = ['message' => 'A worker returned an invalid result payload.'];
                return;
            }

            $index = (int) ($payload['index'] ?? -1);
            if (!isset($downloadTasks[$index])) {
                return;
            }

            $downloadTasks[$index]['resolved'] = true;

            if (!($payload['ok'] ?? false)) {
                $setWorkerProgress($index, sprintf('Worker %d: failed', $index + 1), 0);
                $pendingFailure = [
                    'message' => sprintf('Worker %d failed: %s', $index + 1, (string) ($payload['error'] ?? 'Unknown error.')),
                ];
                return;
            }

            $downloadTasks[$index]['downloaded'] = $downloadTasks[$index]['total'];
            $setWorkerProgress($index, sprintf('Worker %d: completed', $index + 1), 100);
            $updateOverallProgress();

            foreach ($downloadTasks as $task) {
                if (!($task['resolved'] ?? false)) {
                    return;
                }
            }

            $startMerge();
        });

        $downloadTasks[$index]['listeners'][] = $thread->onFinished(static function () use (&$busy, &$stage, &$downloadTasks, &$pendingFailure): void {
            if (!$busy || $stage !== 'download') {
                return;
            }

            foreach ($downloadTasks as $task) {
                if (($task['resolved'] ?? false) === false && $task['thread']->isFinished()) {
                    $pendingFailure = ['message' => 'A worker finished without sending a result.'];
                    return;
                }
            }
        });

        $thread->start('MultiThreadDownloaderWorkerTasks::downloadRangeToFile', [$index, $sourceUrl, $start, $end, $path]);
    }
};

$pumpTimer->onTimeout(static function () use (
    &$busy,
    &$probeTask,
    &$downloadTasks,
    &$mergeTask,
    &$pendingFailure,
    &$pendingSuccess,
    $finalizeFailure,
    $finalizeSuccess
): void {
    if (!$busy) {
        return;
    }

    try {
        if ($probeTask !== null) {
            $probeTask['thread']->drainEvents(128);
        }

        if ($pendingFailure !== null || $pendingSuccess !== null) {
            if ($pendingFailure !== null) {
                $finalizeFailure();
            } else {
                $finalizeSuccess();
            }
            return;
        }

        foreach ($downloadTasks as $task) {
            $task['thread']->drainEvents(128);
            if ($pendingFailure !== null || $pendingSuccess !== null) {
                if ($pendingFailure !== null) {
                    $finalizeFailure();
                } else {
                    $finalizeSuccess();
                }
                return;
            }
        }

        if ($mergeTask !== null) {
            $mergeTask['thread']->drainEvents(128);
        }

        if ($pendingFailure !== null) {
            $finalizeFailure();
            return;
        }

        if ($pendingSuccess !== null) {
            $finalizeSuccess();
        }
    } catch (Throwable $throwable) {
        $pendingFailure = ['message' => 'Unexpected callback error: ' . $throwable->getMessage()];
        $finalizeFailure();
    }
});

$downloadButton->onClicked(static function () use (
    &$busy,
    &$stage,
    &$sourceUrl,
    &$sessionDir,
    &$finalOutputPath,
    &$requestedThreads,
    &$totalBytes,
    &$partPaths,
    &$probeTask,
    &$pendingFailure,
    &$pendingSuccess,
    $cleanupAllTasks,
    $urlInput,
    $threadInput,
    $statusLabel,
    $overallBar,
    $outputLabel,
    $downloadButton,
    $appendLog,
    $pumpTimer,
    $startDownloads,
    $showWorkerRows
): void {
    if ($busy) {
        return;
    }

    $url = trim($urlInput->text());
    if ($url === '') {
        $appendLog('Please enter a URL.');
        return;
    }

    $threads = (int) trim($threadInput->text());
    if ($threads <= 0) {
        $threads = 4;
    }
    $threads = min($threads, 12);

    try {
        $paths = build_session_paths($url, $threads);
    } catch (Throwable $throwable) {
        $statusLabel->setText('Status: failed');
        $appendLog($throwable->getMessage());
        return;
    }

    $cleanupAllTasks();

    $busy = true;
    $stage = 'probe';
    $sourceUrl = $url;
    $sessionDir = $paths['session_dir'];
    $finalOutputPath = $paths['final_output_path'];
    $requestedThreads = $threads;
    $totalBytes = 0;
    $partPaths = $paths['part_paths'];
    $pendingFailure = null;
    $pendingSuccess = null;

    $downloadButton->setEnabled(false);
    $statusLabel->setText('Status: probing URL...');
    $overallBar->setValue(0);
    $outputLabel->setText('Output: ' . $finalOutputPath);
    $showWorkerRows($threads);
    $appendLog('Starting probe.');

    $thread = new QThread(null, runtime_bootstrap_script());
    $probeTask = [
        'thread' => $thread,
        'listeners' => [],
        'resolved' => false,
    ];

    $probeTask['listeners'][] = $thread->on('result', static function (array $event) use (
        &$busy,
        &$stage,
        &$probeTask,
        &$pendingFailure,
        &$totalBytes,
        &$requestedThreads,
        &$partPaths,
        $appendLog,
        $startDownloads
    ): void {
        if (!$busy || $stage !== 'probe' || $probeTask === null) {
            return;
        }

        $payload = $event['payload'] ?? null;
        if (!is_array($payload) || !($payload['ok'] ?? false)) {
            $pendingFailure = [
                'message' => is_array($payload) ? (string) ($payload['error'] ?? 'Probe failed.') : 'Probe failed.',
            ];
            return;
        }

        $probeTask['resolved'] = true;
        $totalBytes = (int) ($payload['size'] ?? 0);
        if ($totalBytes <= 0) {
            $pendingFailure = ['message' => 'Probe returned an invalid file size.'];
            return;
        }

        $threadCount = min($requestedThreads, $totalBytes);
        if (!(bool) ($payload['accept_ranges'] ?? false) && $threadCount > 1) {
            $threadCount = 1;
            $appendLog('Server does not support ranges. Falling back to one worker.');
        }

        $partPaths = array_slice($partPaths, 0, $threadCount);
        $startDownloads($threadCount);
    });

    $probeTask['listeners'][] = $thread->onFinished(static function () use (&$busy, &$stage, &$probeTask, &$pendingFailure): void {
        if (!$busy || $stage !== 'probe' || $probeTask === null || ($probeTask['resolved'] ?? false)) {
            return;
        }

        $pendingFailure = ['message' => 'Probe worker finished without a result.'];
    });

    $thread->start('MultiThreadDownloaderWorkerTasks::probe', [$url]);
    $pumpTimer->start();
});

$app->onAboutToQuit(static function () use (&$busy, &$stage, $pumpTimer, $cleanupAllTasks): void {
    $pumpTimer->stop();
    $busy = false;
    $stage = 'idle';
    $cleanupAllTasks(120000);
});

$window->show();
$app->exec();
