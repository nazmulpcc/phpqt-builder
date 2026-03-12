<?php

declare(strict_types=1);

require __DIR__ . '/DownloadManager.php';

final class ThreadedDownloaderWorkerTasks
{
    /**
     * @return array{size:int,accept_ranges:bool}
     */
    public static function probe(string $url): array
    {
        $manager = new DownloadManager();
        return $manager->probe($url);
    }

    /**
     * @return array{ok:bool,output:string,start:int,end:int}
     */
    public static function downloadRangeToFile(string $url, int $start, int $end, string $outputPath): array
    {
        $manager = new DownloadManager();
        $manager->downloadRange($url, $start, $end, $outputPath);

        return [
            'ok' => true,
            'output' => $outputPath,
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * @param list<string> $partPaths
     * @return array{ok:bool,output:string,parts:int}
     */
    public static function mergeParts(array $partPaths, string $outputPath): array
    {
        $manager = new DownloadManager();
        $manager->mergeParts($partPaths, $outputPath);

        return [
            'ok' => true,
            'output' => $outputPath,
            'parts' => count($partPaths),
        ];
    }
}
