<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Qt\Core\QThreadRuntime;
use Qt\Core\QTimer;
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

function infer_filename_from_url(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $name = basename($path);
    if ($name === '' || $name === '/' || $name === '.') {
        return 'download.bin';
    }

    return $name;
}

function runtime_bootstrap_script(): string
{
    return __DIR__ . '/worker_runtime_bootstrap.php';
}

example_section('Threaded Downloader');

if (!extension_loaded('curl')) {
    example_fail('The curl extension is required for this example.');
}

try {
    assert_classes_available([
        QApplication::class,
        QWidget::class,
        QTimer::class,
        QThreadRuntime::class,
    ]);
} catch (Throwable $throwable) {
    example_fail($throwable->getMessage() . ' Build with QtCore and QtWidgets modules.');
}

if (!is_file(runtime_bootstrap_script())) {
    example_fail('Missing worker bootstrap script: ' . runtime_bootstrap_script());
}

$app = new QApplication();
$window = new QWidget();
$window->resize(900, 700);
$window->setWindowTitle('Threaded Downloader (QThreadRuntime + cURL)');
$window->setStyleSheet(<<<'CSS'
QWidget { background: #f8fafc; color: #0f172a; font-size: 13px; }
QLabel[role="title"] { font-size: 20px; font-weight: 700; }
QLineEdit { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 7px 9px; }
QPushButton { background: #2563eb; color: #ffffff; border: none; border-radius: 8px; padding: 8px 14px; }
QPushButton:disabled { background: #93c5fd; color: #e2e8f0; }
QProgressBar { background: #e2e8f0; border: 1px solid #cbd5e1; border-radius: 8px; text-align: center; min-height: 20px; }
QProgressBar::chunk { background: #2563eb; border-radius: 7px; }
CSS);

$root = new QVBoxLayout();
$root->setSpacing(10);
$root->setContentsMargins(18, 18, 18, 18);

$title = new QLabel('Threaded Downloader');
$title->setProperty('role', 'title');
$desc = new QLabel(
    'Parallel byte-range download using QThreadRuntime workers. Each worker builds its own cURL handle in an isolated PHP runtime.'
);
$desc->setWordWrap(true);

$urlInput = new QLineEdit();
$urlInput->setText('https://proof.ovh.net/files/100Mb.dat');

$threadInput = new QLineEdit();
$threadInput->setText('4');
$threadInput->setMaximumWidth(120);

$downloadButton = new QPushButton('Download');
$statusLabel = new QLabel('Status: idle');
$progressLabel = new QLabel('Progress: 0%');
$outputLabel = new QLabel('Output: (none)');
$threadProgressTitle = new QLabel('Thread Progress');

$formRow = new QHBoxLayout();
$formRow->addWidget(new QLabel('URL'));
$formRow->addWidget($urlInput, 1);
$formRow->addWidget(new QLabel('Threads'));
$formRow->addWidget($threadInput);
$formRow->addWidget($downloadButton);

$threadProgressWidget = new QWidget();
$threadProgressLayout = new QVBoxLayout();
$threadProgressLayout->setSpacing(6);
$threadProgressLayout->setContentsMargins(0, 0, 0, 0);
$threadProgressWidget->setLayout($threadProgressLayout);

/** @var array<int, QLabel> $threadProgressLabels */
$threadProgressLabels = [];
/** @var array<int, QProgressBar> $threadProgressBars */
$threadProgressBars = [];
/** @var array<int, QWidget> $threadProgressRows */
$threadProgressRows = [];

for ($i = 0; $i < 32; $i++) {
    $rowWidget = new QWidget();
    $row = new QHBoxLayout();
    $row->setContentsMargins(0, 0, 0, 0);
    $rowWidget->setLayout($row);
    $rowLabel = new QLabel(sprintf('Thread %d: idle', $i + 1));
    $rowLabel->setMinimumWidth(210);
    $bar = new QProgressBar();
    $bar->setRange(0, 100);
    $bar->setValue(0);

    $row->addWidget($rowLabel);
    $row->addWidget($bar, 1);
    $threadProgressLayout->addWidget($rowWidget);
    $rowWidget->setVisible(false);

    $threadProgressRows[$i] = $rowWidget;
    $threadProgressLabels[$i] = $rowLabel;
    $threadProgressBars[$i] = $bar;
}

$root->addWidget($title);
$root->addWidget($desc);
$root->addLayout($formRow);
$root->addWidget($statusLabel);
$root->addWidget($progressLabel);
$root->addWidget($outputLabel);
$root->addWidget($threadProgressTitle);
$root->addWidget($threadProgressWidget, 1);
$threadProgressTitle->setVisible(false);
$threadProgressWidget->setVisible(false);
$window->setLayout($root);

$appendLog = static function (string $line): void {
    fwrite(STDOUT, sprintf("[%s] %s\n", date('H:i:s'), $line));
};

$busy = false;
$stage = 'idle';
$totalBytes = 0;
$partPaths = [];
$finalOutputPath = '';
$sessionDir = '';
$sourceUrl = '';

/** @var array{runtime:QThreadRuntime,jobId:int}|null $probeTask */
$probeTask = null;
/** @var array<int, array{index:int,runtime:QThreadRuntime,jobId:int,start:int,end:int,total:int,path:string,done:bool,error:?string}> $rangeTasks */
$rangeTasks = [];
/** @var array{runtime:QThreadRuntime,jobId:int}|null $mergeTask */
$mergeTask = null;

$activeThreadRows = 0;

$resetThreadProgress = static function (int $activeCount) use (
    &$activeThreadRows,
    $threadProgressTitle,
    $threadProgressWidget,
    $threadProgressRows,
    $threadProgressLabels,
    $threadProgressBars
): void {
    $activeThreadRows = $activeCount;
    $threadProgressTitle->setVisible($activeCount > 0);
    $threadProgressWidget->setVisible($activeCount > 0);
    for ($i = 0; $i < 32; $i++) {
        if ($i < $activeCount) {
            $threadProgressRows[$i]->setVisible(true);
            $threadProgressLabels[$i]->setText(sprintf('Thread %d: queued', $i + 1));
            $threadProgressBars[$i]->setValue(0);
            continue;
        }

        $threadProgressRows[$i]->setVisible(false);
        $threadProgressLabels[$i]->setText(sprintf('Thread %d: idle', $i + 1));
        $threadProgressBars[$i]->setValue(0);
    }
};

$stopRuntime = static function (?QThreadRuntime $runtime, int $timeoutMs = 2000): void {
    if ($runtime === null) {
        return;
    }

    try {
        $runtime->stop($timeoutMs);
    } catch (Throwable) {
    }
};

$cleanupActiveTasks = static function (int $timeoutMs = 2000) use (&$probeTask, &$rangeTasks, &$mergeTask, $stopRuntime): void {
    if ($probeTask !== null) {
        $stopRuntime($probeTask['runtime'], $timeoutMs);
        $probeTask = null;
    }

    foreach ($rangeTasks as $task) {
        $stopRuntime($task['runtime'], $timeoutMs);
    }
    $rangeTasks = [];

    if ($mergeTask !== null) {
        $stopRuntime($mergeTask['runtime'], $timeoutMs);
        $mergeTask = null;
    }
};

$startRuntimeTask = static function (string $method, array $args) use ($appendLog): array {
    $runtime = new QThreadRuntime();
    $runtime->setBootstrapScript(runtime_bootstrap_script());
    $runtime->start();
    $jobId = $runtime->submit(['ThreadedDownloaderWorkerTasks', $method], $args);
    $appendLog(sprintf('Started worker job: %s', $method));
    return ['runtime' => $runtime, 'jobId' => $jobId];
};

$failRun = static function (string $status, string $reason) use (
    &$busy,
    &$stage,
    &$probeTask,
    &$rangeTasks,
    &$mergeTask,
    $cleanupActiveTasks,
    $statusLabel,
    $downloadButton,
    $resetThreadProgress,
    $appendLog
): void {
    $busy = false;
    $stage = 'idle';
    $cleanupActiveTasks();
    $resetThreadProgress(0);
    $statusLabel->setText('Status: ' . $status);
    $downloadButton->setEnabled(true);
    $appendLog($reason);
};

$progressTimer = new QTimer($window);
$progressTimer->setInterval(140);
$progressTimer->onTimeout(static function () use (
    &$busy,
    &$stage,
    &$totalBytes,
    &$partPaths,
    &$sourceUrl,
    &$activeThreadRows,
    &$sessionDir,
    &$finalOutputPath,
    &$probeTask,
    &$rangeTasks,
    &$mergeTask,
    $appendLog,
    $startRuntimeTask,
    $failRun,
    $stopRuntime,
    $statusLabel,
    $progressLabel,
    $outputLabel,
    $downloadButton,
    $threadProgressLabels,
    $threadProgressBars,
    $resetThreadProgress
): void {
    try {
        if (!$busy) {
            return;
        }

        $downloaded = 0;
        foreach ($partPaths as $i => $path) {
            if (is_file($path)) {
                $size = (int) filesize($path);
                $downloaded += $size;

                if (isset($rangeTasks[$i]) && $rangeTasks[$i]['total'] > 0 && $i < $activeThreadRows) {
                    $partTotal = $rangeTasks[$i]['total'];
                    $partPct = (int) round(min(100.0, ($size / $partTotal) * 100.0));
                    $threadProgressBars[$i]->setValue($partPct);
                    $threadProgressLabels[$i]->setText(sprintf(
                        'Thread %d: %d%% (%d/%d)',
                        $i + 1,
                        $partPct,
                        $size,
                        $partTotal
                    ));
                }
            }
        }

        if ($totalBytes > 0) {
            $pct = (int) round(min(100.0, ($downloaded / $totalBytes) * 100.0));
            if ($stage === 'merge') {
                $progressLabel->setText(sprintf('Progress: %d%% (merge stage)', $pct));
            } else {
                $progressLabel->setText(sprintf('Progress: %d%% (%d / %d bytes)', $pct, $downloaded, $totalBytes));
            }
        }

        if ($stage === 'probe') {
            if ($probeTask === null) {
                $failRun('failed', 'Internal error: missing probe task.');
                return;
            }

            try {
                $result = $probeTask['runtime']->await($probeTask['jobId'], 1);
            } catch (Throwable $throwable) {
                $failRun('probe failed', 'Probe failed: ' . $throwable->getMessage());
                return;
            }

            if ($result === null) {
                return;
            }

            $stopRuntime($probeTask['runtime']);
            $probeTask = null;

            if (!is_array($result) || !isset($result['size'])) {
                $failRun('probe failed', 'Probe returned invalid payload.');
                return;
            }

            $totalBytes = (int) $result['size'];
            $acceptRanges = (bool) ($result['accept_ranges'] ?? false);
            if ($totalBytes <= 0) {
                $failRun('probe failed', 'Probe returned non-positive content length.');
                return;
            }

            $threadCount = count($rangeTasks);
            if (!$acceptRanges && $threadCount > 1) {
                $appendLog('Server does not advertise byte ranges; falling back to a single worker.');
                $threadCount = 1;
                $rangeTasks = array_slice($rangeTasks, 0, 1);
                $partPaths = array_slice($partPaths, 0, 1);
            }
            if ($totalBytes < $threadCount) {
                $threadCount = max(1, $totalBytes);
                $appendLog(sprintf('Reducing workers to %d for small file size.', $threadCount));
                $rangeTasks = array_slice($rangeTasks, 0, $threadCount);
                $partPaths = array_slice($partPaths, 0, $threadCount);
            }

            $chunkSize = intdiv($totalBytes, $threadCount);
            $resetThreadProgress($threadCount);
            $statusLabel->setText(sprintf('Status: downloading in %d worker(s)...', $threadCount));
            $stage = 'download';

            for ($i = 0; $i < $threadCount; $i++) {
                $start = $i * $chunkSize;
                $end = ($i === $threadCount - 1) ? ($totalBytes - 1) : (($start + $chunkSize) - 1);
                $path = $partPaths[$i];

                try {
                    $task = $startRuntimeTask('downloadRangeToFile', [$sourceUrl, $start, $end, $path]);
                } catch (Throwable $throwable) {
                    $failRun('failed', sprintf('Could not start range worker %d: %s', $i, $throwable->getMessage()));
                    return;
                }

                $rangeTasks[$i] = [
                    'index' => $i,
                    'runtime' => $task['runtime'],
                    'jobId' => $task['jobId'],
                    'start' => $start,
                    'end' => $end,
                    'total' => ($end - $start) + 1,
                    'path' => $path,
                    'done' => false,
                    'error' => null,
                ];
                $threadProgressLabels[$i]->setText(sprintf('Thread %d: downloading %d-%d', $i + 1, $start, $end));
                $appendLog(sprintf('Worker %d range: %d-%d', $i, $start, $end));
            }

            return;
        }

        if ($stage === 'download') {
            $allDone = true;
            $failed = false;

            foreach ($rangeTasks as &$task) {
                if ($task['done']) {
                    continue;
                }

                try {
                    $result = $task['runtime']->await($task['jobId'], 1);
                } catch (Throwable $throwable) {
                    $task['done'] = true;
                    $task['error'] = $throwable->getMessage();
                    $stopRuntime($task['runtime']);
                    $failed = true;
                    continue;
                }

                if ($result === null) {
                    $allDone = false;
                    continue;
                }

                $task['done'] = true;
                $stopRuntime($task['runtime']);

                if (!is_array($result) || !($result['ok'] ?? false)) {
                    $task['error'] = 'Invalid worker result payload.';
                    $failed = true;
                    continue;
                }

                $threadProgressBars[$task['index']]->setValue(100);
                $threadProgressLabels[$task['index']]->setText(sprintf('Thread %d: completed', $task['index'] + 1));
            }
            unset($task);

            if (!$allDone && !$failed) {
                return;
            }

            if ($failed) {
                foreach ($rangeTasks as $task) {
                    if ($task['error'] !== null) {
                        $threadProgressLabels[$task['index']]->setText(sprintf('Thread %d: failed', $task['index'] + 1));
                        $appendLog(sprintf('Worker %d failed: %s', $task['index'], $task['error']));
                    }
                }
                $failRun('failed', 'Download aborted due to worker failure.');
                return;
            }

            $appendLog('All range workers completed. Starting merge worker.');
            $statusLabel->setText('Status: merging parts...');
            $stage = 'merge';

            try {
                $mergeTask = $startRuntimeTask('mergeParts', [$partPaths, $finalOutputPath]);
            } catch (Throwable $throwable) {
                $failRun('merge failed', 'Could not start merge worker: ' . $throwable->getMessage());
            }

            return;
        }

        if ($stage === 'merge') {
            if ($mergeTask === null) {
                $failRun('merge failed', 'Internal error: missing merge task.');
                return;
            }

            try {
                $result = $mergeTask['runtime']->await($mergeTask['jobId'], 1);
            } catch (Throwable $throwable) {
                $failRun('merge failed', 'Merge failed: ' . $throwable->getMessage());
                return;
            }

            if ($result === null) {
                return;
            }

            $stopRuntime($mergeTask['runtime']);
            $mergeTask = null;

            if (!is_array($result) || !($result['ok'] ?? false)) {
                $failRun('merge failed', 'Merge returned invalid payload.');
                return;
            }

            foreach ($partPaths as $path) {
                @unlink($path);
            }

            $busy = false;
            $stage = 'idle';
            $resetThreadProgress(0);
            $downloadButton->setEnabled(true);
            $statusLabel->setText('Status: completed');
            $progressLabel->setText('Progress: 100%');
            $outputLabel->setText('Output: ' . $finalOutputPath);
            $appendLog('Download complete: ' . $finalOutputPath);
            $appendLog('Session: ' . $sessionDir);
        }
    } catch (Throwable $throwable) {
        $failRun('failed', 'Unexpected callback error: ' . $throwable->getMessage());
    }
});

$app->onAboutToQuit(static function () use (
    &$busy,
    &$stage,
    $progressTimer,
    $cleanupActiveTasks
): void {
    $busy = false;
    $stage = 'idle';
    $progressTimer->stop();
    // During app shutdown, avoid destroying runtimes while their QThread is alive.
    $cleanupActiveTasks(120000);
});

$downloadButton->onClicked(static function (bool $checked = false) use (
    &$busy,
    &$stage,
    &$totalBytes,
    &$partPaths,
    &$finalOutputPath,
    &$sessionDir,
    &$sourceUrl,
    &$activeThreadRows,
    &$probeTask,
    &$rangeTasks,
    &$mergeTask,
    $cleanupActiveTasks,
    $startRuntimeTask,
    $urlInput,
    $threadInput,
    $downloadButton,
    $statusLabel,
    $progressLabel,
    $outputLabel,
    $resetThreadProgress,
    $appendLog,
    $progressTimer
): void {
    try {
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
        if ($threads > 32) {
            $threads = 32;
        }

        $name = infer_filename_from_url($url);
        $sessionId = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $sessionDir = example_repo_root() . '/build/threaded-downloader/' . $sessionId;
        $partsDir = $sessionDir . '/parts';
        $outputDir = $sessionDir . '/output';
        $finalOutputPath = $outputDir . '/' . $name;
        $sourceUrl = $url;

        if (!mkdir($partsDir, 0777, true) && !is_dir($partsDir)) {
            $statusLabel->setText('Status: failed');
            $appendLog('Could not create parts directory: ' . $partsDir);
            return;
        }
        if (!mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            $statusLabel->setText('Status: failed');
            $appendLog('Could not create output directory: ' . $outputDir);
            return;
        }

        $cleanupActiveTasks();
        $activeThreadRows = 0;
        $partPaths = [];
        for ($i = 0; $i < $threads; $i++) {
            $partPaths[] = sprintf('%s/part_%02d.bin', $partsDir, $i);
        }

        $rangeTasks = array_fill(0, $threads, []);
        $probeTask = null;
        $mergeTask = null;
        $totalBytes = 0;
        $busy = true;
        $stage = 'probe';
        $downloadButton->setEnabled(false);
        $statusLabel->setText('Status: probing URL...');
        $progressLabel->setText('Progress: 0%');
        $outputLabel->setText('Output: ' . $finalOutputPath);
        $resetThreadProgress($threads);
        $appendLog('Starting probe worker for URL: ' . $url);

        $probeTask = $startRuntimeTask('probe', [$url]);
    } catch (Throwable $throwable) {
        $busy = false;
        $stage = 'idle';
        $resetThreadProgress(0);
        $downloadButton->setEnabled(true);
        $statusLabel->setText('Status: failed');
        $appendLog('Could not start probe worker: ' . $throwable->getMessage());
        return;
    }

    $progressTimer->start();
});

$window->show();
QApplication::exec();
