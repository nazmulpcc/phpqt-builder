<?php

declare(strict_types=1);

final class DownloadManager
{
    private const USER_AGENT = 'phpqt-threaded-downloader/1.0';

    /**
     * @return array{size:int, accept_ranges:bool}
     */
    public function probe(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new RuntimeException($this->formatCurlFailure('Probe failed', (string) curl_error($ch)));
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($httpCode >= 400) {
            throw new RuntimeException(sprintf('Probe failed with HTTP %d.', $httpCode));
        }

        $size = -1;
        $acceptRanges = false;
        foreach (preg_split('/\r\n|\n|\r/', $response) as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                continue;
            }

            if (stripos($trimmed, 'Content-Length:') === 0) {
                $value = trim(substr($trimmed, strlen('Content-Length:')));
                if (is_numeric($value)) {
                    $size = (int) $value;
                }
                continue;
            }

            if (stripos($trimmed, 'Accept-Ranges:') === 0) {
                $value = strtolower(trim(substr($trimmed, strlen('Accept-Ranges:'))));
                $acceptRanges = ($value === 'bytes');
            }
        }

        if ($size <= 0) {
            throw new RuntimeException('Could not determine file size from headers.');
        }

        return [
            'size' => $size,
            'accept_ranges' => $acceptRanges,
        ];
    }

    public function downloadRange(string $url, int $startByte, int $endByte, string $outputPath): void
    {
        if ($endByte < $startByte) {
            throw new InvalidArgumentException('Invalid byte range.');
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create output directory: ' . $dir);
        }

        $fh = fopen($outputPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Could not open output file: ' . $outputPath);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            throw new RuntimeException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_RANGE => sprintf('%d-%d', $startByte, $endByte),
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? curl_error($ch) : null;
        fclose($fh);

        if ($ok === false) {
            @unlink($outputPath);
            throw new RuntimeException($this->formatCurlFailure('Range download failed', (string) $error));
        }

        if ($httpCode >= 400) {
            @unlink($outputPath);
            throw new RuntimeException(sprintf('Range download failed with HTTP %d.', $httpCode));
        }
    }

    /**
     * @param list<string> $partPaths
     */
    public function mergeParts(array $partPaths, string $outputPath): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create output directory: ' . $dir);
        }

        $out = fopen($outputPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not open final output file: ' . $outputPath);
        }

        try {
            foreach ($partPaths as $path) {
                $in = fopen($path, 'rb');
                if ($in === false) {
                    throw new RuntimeException('Missing part file: ' . $path);
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } catch (Throwable $throwable) {
            fclose($out);
            throw $throwable;
        }

        fclose($out);
    }

    private function formatCurlFailure(string $prefix, string $error): string
    {
        $message = $prefix . ': ' . $error;
        if (!$this->looksLikeMissingCaConfiguration($error)) {
            return $message;
        }

        $configuredCa = [
            'curl.cainfo' => ini_get('curl.cainfo'),
            'openssl.cafile' => ini_get('openssl.cafile'),
        ];

        return $message . sprintf(
            ' Configure curl.cainfo and openssl.cafile in php.ini to point at a valid CA bundle. Current values: curl.cainfo=%s, openssl.cafile=%s',
            $this->formatIniValue($configuredCa['curl.cainfo']),
            $this->formatIniValue($configuredCa['openssl.cafile']),
        );
    }

    private function looksLikeMissingCaConfiguration(string $error): bool
    {
        $normalized = strtolower($error);

        return str_contains($normalized, 'unable to get local issuer certificate')
            || str_contains($normalized, 'problem with the ssl ca cert')
            || str_contains($normalized, 'error setting certificate file')
            || str_contains($normalized, 'schannel: failed to import cert file');
    }

    private function formatIniValue(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '(not set)';
        }

        return $value;
    }
}
