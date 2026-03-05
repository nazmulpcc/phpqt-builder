<?php

declare(strict_types=1);

namespace QtBuilder\IO;

final class FileWriteStats
{
    private int $created = 0;
    private int $updated = 0;
    private int $unchanged = 0;

    public function record(FileWriteResult $result): void
    {
        match ($result->status) {
            FileWriteStatus::Created => $this->created++,
            FileWriteStatus::Updated => $this->updated++,
            FileWriteStatus::Unchanged => $this->unchanged++,
        };
    }

    public function merge(self $other): void
    {
        $this->created += $other->created;
        $this->updated += $other->updated;
        $this->unchanged += $other->unchanged;
    }

    public function created(): int
    {
        return $this->created;
    }

    public function updated(): int
    {
        return $this->updated;
    }

    public function unchanged(): int
    {
        return $this->unchanged;
    }

    public function written(): int
    {
        return $this->created + $this->updated;
    }

    public function total(): int
    {
        return $this->written() + $this->unchanged;
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, written: int, total: int}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created(),
            'updated' => $this->updated(),
            'unchanged' => $this->unchanged(),
            'written' => $this->written(),
            'total' => $this->total(),
        ];
    }
}

