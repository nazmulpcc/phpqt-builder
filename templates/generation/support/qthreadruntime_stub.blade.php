{!! '<?php' !!}

/** @generate-class-entries */

namespace Qt\Core;

final class QThreadRuntime
{
    public function __construct() {}

    public function setBootstrapScript(?string $path): void {}

    public function start(): void {}

    public function submit(mixed $callable, array $args = []): int {}

    public function on(string $event, callable $listener): int {}

    public function off(int $listenerId): bool {}

    public function drainEvents(int $maxItems = -1): int {}

    public function send(int $jobId, string $event, array $payload = []): bool {}

    public static function publish(string $event, array $payload = []): bool {}

    /** @return array{event:string,payload:array}|null */
    public static function receive(int $timeoutMs = 0): ?array {}

    public function await(int $jobId, int $timeoutMs = 0): mixed {}

    public function cancel(int $jobId): bool {}

    public function stop(int $timeoutMs = 5000): bool {}

    public function isRunning(): bool {}

    /** @return array<string, int|bool> */
    public function stats(): array {}
}
