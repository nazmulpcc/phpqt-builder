<?php

declare(strict_types=1);

namespace QtBuilder\Build;

readonly class BootstrapResult
{
    /**
     * @param list<BootstrapStep> $steps
     */
    public function __construct(public array $steps) {}

    /**
     * @return list<array{
     *   name: string,
     *   command: list<string>,
     *   working_directory: string,
     *   stdout_log: string,
     *   stderr_log: string
     * }>
     */
    public function toArray(): array
    {
        return array_map(
            static fn(BootstrapStep $step): array => $step->toArray(),
            $this->steps,
        );
    }
}
