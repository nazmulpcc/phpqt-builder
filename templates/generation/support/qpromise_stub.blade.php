{!! '<?php' !!}

/** @generate-class-entries */

namespace Qt\Core;

final class QPromise
{
    public static function current(): ?\Qt\Core\QPromise {}

    public function publish(string $event, array $payload = []): bool {}

    /** @return array{event:string,payload:array}|null */
    public function receive(int $timeoutMs = 0): ?array {}

    public function isCanceled(): bool {}

    public function setProgressRange(int $minimum, int $maximum): void {}

    public function setProgressValue(int $value): void {}

    public function setProgressValueAndText(int $value, string $text): void {}
}
