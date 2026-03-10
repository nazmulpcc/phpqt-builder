<?php

declare(strict_types=1);

use QtBuilder\Build\FixedPointEngine;

it('iterates until state stops changing', function (): void {
    $engine = new FixedPointEngine();

    $result = $engine->run(
        ['value' => 0],
        static function (int $pass, array $state): array {
            $next = $state['value'] + 1;
            if ($next >= 3) {
                return [
                    'state' => ['value' => 3],
                    'changed' => false,
                    'has_errors' => false,
                    'is_empty' => false,
                ];
            }

            return [
                'state' => ['value' => $next],
                'changed' => true,
                'has_errors' => false,
                'is_empty' => false,
            ];
        },
    );

    expect($result['passes'])->toBe(3)
        ->and($result['state'])->toBe(['value' => 3]);
});

it('stops when an iteration reports errors', function (): void {
    $engine = new FixedPointEngine();

    $result = $engine->run(
        ['value' => 0],
        static function (int $pass, array $state): array {
            return [
                'state' => ['value' => $state['value'] + 1],
                'changed' => true,
                'has_errors' => $pass >= 2,
                'is_empty' => false,
            ];
        },
    );

    expect($result['passes'])->toBe(2)
        ->and($result['state'])->toBe(['value' => 2]);
});

it('stops when an iteration reports empty state', function (): void {
    $engine = new FixedPointEngine();

    $result = $engine->run(
        ['value' => 0],
        static function (int $pass, array $state): array {
            return [
                'state' => ['value' => $state['value'] + 1],
                'changed' => true,
                'has_errors' => false,
                'is_empty' => $pass >= 2,
            ];
        },
    );

    expect($result['passes'])->toBe(2)
        ->and($result['state'])->toBe(['value' => 2]);
});

