<?php

declare(strict_types=1);

require __DIR__ . '/DownloadManager.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php worker.php <mode> [...args]\n");
    exit(2);
}

$manager = new DownloadManager();
$mode = $argv[1];

try {
    if ($mode === 'probe') {
        if ($argc < 4) {
            throw new InvalidArgumentException('probe mode requires: <url> <output_json>');
        }

        $url = $argv[2];
        $outputJson = $argv[3];
        $result = $manager->probe($url);
        $dir = dirname($outputJson);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create probe output directory: ' . $dir);
        }

        file_put_contents($outputJson, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        exit(0);
    }

    if ($mode === 'range') {
        if ($argc < 7) {
            throw new InvalidArgumentException('range mode requires: <url> <start> <end> <output_path> <meta_json>');
        }

        $url = $argv[2];
        $start = (int) $argv[3];
        $end = (int) $argv[4];
        $outputPath = $argv[5];
        $metaJson = $argv[6];

        $manager->downloadRange($url, $start, $end, $outputPath);
        file_put_contents($metaJson, json_encode([
            'ok' => true,
            'output' => $outputPath,
            'start' => $start,
            'end' => $end,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        exit(0);
    }

    if ($mode === 'merge') {
        if ($argc < 5) {
            throw new InvalidArgumentException('merge mode requires: <final_output> <meta_json> <part_1> [part_2 ...]');
        }

        $finalOutput = $argv[2];
        $metaJson = $argv[3];
        $parts = array_slice($argv, 4);
        $manager->mergeParts($parts, $finalOutput);

        file_put_contents($metaJson, json_encode([
            'ok' => true,
            'output' => $finalOutput,
            'parts' => count($parts),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        exit(0);
    }

    throw new InvalidArgumentException('Unknown mode: ' . $mode);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");
    exit(1);
}
