<?php

declare(strict_types=1);

it('covers chart construction series addition axis range and point data', function (): void {
    $payload = qt_runtime_payload('QtCharts/chart_series_smoke.php');

    expect($payload['title'])->toBe('Sales Overview')
        ->and($payload['series_name'])->toBe('Revenue')
        ->and($payload['point_count'])->toBe(4)
        ->and((float) $payload['axis_x_min'])->toEqualWithDelta(0.0, 0.001)
        ->and((float) $payload['axis_x_max'])->toEqualWithDelta(3.0, 0.001)
        ->and((float) $payload['axis_y_max'])->toEqualWithDelta(300.0, 0.001)
        ->and($payload['tick_count'])->toBe(4);
});
