<?php

declare(strict_types=1);

namespace QtBuilder\IO;

final readonly class FileWriteResult
{
    public function __construct(
        public string $path,
        public FileWriteStatus $status,
        public int $bytes,
        public string $reason,
    ) {}
}

