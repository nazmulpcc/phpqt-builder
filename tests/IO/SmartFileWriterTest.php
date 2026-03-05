<?php

declare(strict_types=1);

use QtBuilder\IO\FileWriteStatus;
use QtBuilder\IO\SmartFileWriter;

it('creates new files and reports created status', function (): void {
    $dir = qt_temp_dir('qtbuilder-smartwriter-');
    $path = $dir . '/sample.txt';

    $writer = new SmartFileWriter();
    $result = $writer->write($path, "hello\n");

    expect($result->status)->toBe(FileWriteStatus::Created)
        ->and($result->reason)->toBe('created')
        ->and(is_file($path))->toBeTrue()
        ->and((string) file_get_contents($path))->toBe("hello\n");
});

it('skips unchanged files and preserves mtime', function (): void {
    $dir = qt_temp_dir('qtbuilder-smartwriter-');
    $path = $dir . '/same.txt';
    file_put_contents($path, "same-content\n");
    clearstatcache(true, $path);
    $before = filemtime($path);
    expect($before)->not->toBeFalse();
    usleep(300000);

    $writer = new SmartFileWriter();
    $result = $writer->write($path, "same-content\n");
    clearstatcache(true, $path);
    $after = filemtime($path);

    expect($result->status)->toBe(FileWriteStatus::Unchanged)
        ->and($result->reason)->toBe('unchanged')
        ->and($after)->toBe($before);
});

it('updates files when content differs', function (): void {
    $dir = qt_temp_dir('qtbuilder-smartwriter-');
    $path = $dir . '/changed.txt';
    file_put_contents($path, "before\n");
    clearstatcache(true, $path);
    $before = filemtime($path);
    expect($before)->not->toBeFalse();
    usleep(300000);

    $writer = new SmartFileWriter();
    $result = $writer->write($path, "after\n");
    clearstatcache(true, $path);
    $after = filemtime($path);

    expect($result->status)->toBe(FileWriteStatus::Updated)
        ->and($result->reason)->toBe('size_mismatch')
        ->and($after)->toBeGreaterThanOrEqual($before)
        ->and((string) file_get_contents($path))->toBe("after\n");
});

