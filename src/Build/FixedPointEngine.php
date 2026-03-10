<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final class FixedPointEngine
{
    /**
     * @template TState
     * @param TState $initialState
     * @param callable(int, TState): array{state: TState, changed: bool, has_errors: bool, is_empty: bool} $iterate
     * @return array{passes: int, state: TState}
     */
    public function run(mixed $initialState, callable $iterate): array
    {
        $state = $initialState;
        $passes = 0;

        do {
            $passes++;
            $result = $iterate($passes, $state);
            $state = $result['state'];
            $changed = $result['changed'];
            $hasErrors = $result['has_errors'];
            $isEmpty = $result['is_empty'];
        } while ($changed && !$hasErrors && !$isEmpty);

        return [
            'passes' => $passes,
            'state' => $state,
        ];
    }
}

