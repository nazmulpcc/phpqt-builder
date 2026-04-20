<?php

declare(strict_types=1);

require __DIR__ . '/DownloadManager.php';

final class MultiThreadDownloaderWorkerTasks
{
    private static function interrupted(): bool
    {
        try {
            return \Qt\Core\QThread::currentThread()->isInterruptionRequested();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{ok:bool,size?:int,accept_ranges?:bool,error?:string}
     */
    public static function probe(string $url): array
    {
        try {
            $manager = new DownloadManager();
            $result = $manager->probe($url);

            $payload = [
                'ok' => true,
                'size' => (int) $result['size'],
                'accept_ranges' => (bool) $result['accept_ranges'],
            ];
            \Qt\Core\QThread::publish('result', $payload);

            return $payload;
        } catch (\Throwable $throwable) {
            $payload = [
                'ok' => false,
                'error' => $throwable->getMessage(),
            ];
            \Qt\Core\QThread::publish('result', $payload);

            return $payload;
        }
    }

    /**
     * @return array{ok:bool,index?:int,output?:string,error?:string}
     */
    public static function downloadRangeToFile(int $index, string $url, int $start, int $end, string $outputPath): array
    {
        try {
            $manager = new DownloadManager();
            $manager->downloadRange(
                $url,
                $start,
                $end,
                $outputPath,
                static function (int $downloaded, int $total, int $percent) use ($index): void {
                    \Qt\Core\QThread::publish('progress', [
                        'index' => $index,
                        'downloaded' => $downloaded,
                        'total' => $total,
                        'percent' => $percent,
                    ]);
                },
                static fn (): bool => self::interrupted(),
            );

            $payload = [
                'ok' => true,
                'index' => $index,
                'output' => $outputPath,
            ];
            \Qt\Core\QThread::publish('result', $payload);

            return $payload;
        } catch (\Throwable $throwable) {
            $payload = [
                'ok' => false,
                'index' => $index,
                'error' => $throwable->getMessage(),
            ];
            \Qt\Core\QThread::publish('result', $payload);

            return $payload;
        }
    }

    /**
     * @param list<string> $partPaths
     * @return array{ok:bool,output?:string,error?:string}
     */
    public static function mergeParts(array $partPaths, string $outputPath): array
    {
        try {
            $manager = new DownloadManager();
            $manager->mergeParts($partPaths, $outputPath);

            $payload = [
                'ok' => true,
                'output' => $outputPath,
            ];
            \Qt\Core\QThread::publish('result', $payload);

            return $payload;
        } catch (\Throwable $throwable) {
            $payload = [
                'ok' => false,
                'error' => $throwable->getMessage(),
            ];
            \Qt\Core\QThread::publish('result', $payload);

            return $payload;
        }
    }
}
