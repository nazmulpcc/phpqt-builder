{!! '<' . '?php' !!}

/** @generate-class-entries */

namespace Qt\Core;

final class QFuture
{
    public function isValid(): bool {}

    public function isRunning(): bool {}

    public function isFinished(): bool {}

    public function isCanceled(): bool {}

    public function cancel(): bool {}

    public function wait(int $timeoutMs = 0): bool {}

    public function result(int $timeoutMs = 0): mixed {}

    public function on(string $event, callable $listener): int {}

    public function off(int $listenerId): bool {}

    public function drainEvents(int $maxItems = -1): int {}

    public function then(callable $handler): \Qt\Core\QFuture {}

    public function onFailed(callable $handler): \Qt\Core\QFuture {}

    public function onCanceled(callable $handler): \Qt\Core\QFuture {}
}
