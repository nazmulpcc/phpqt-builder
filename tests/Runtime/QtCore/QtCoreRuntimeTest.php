<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function qt_runtime_thread_payload(string $fixture, array $env = [], int $timeout = 5): array
{
    if (!defined('PHP_ZTS') || (int) PHP_ZTS !== 1) {
        test()->markTestSkipped('QThread runtime tests require a ZTS PHP build.');
    }

    return qt_runtime_payload($fixture, $env, $timeout);
}

it('covers qobject lifecycle and property notifications', function (): void {
    $payload = qt_runtime_payload('QtCore/qobject_properties.php');

    expect($payload['name'])->toBe('Runtime Child')
        ->and($payload['has_parent'])->toBeTrue()
        ->and($payload['inherits_qobject'])->toBeTrue()
        ->and($payload['notify_hits'])->toBe(1)
        ->and($payload['connection_is_object'])->toBeTrue();
});

it('covers timers and event delivery with short ticks', function (): void {
    $payload = qt_runtime_payload('QtCore/timers_and_events.php');

    expect($payload['custom_type'])->toBeGreaterThan(0)
        ->and($payload['filter_count'])->toBeGreaterThanOrEqual(2)
        ->and($payload['event_count'])->toBeGreaterThanOrEqual(2)
        ->and($payload['custom_count'])->toBe(2)
        ->and($payload['timer_ticks'])->toBe(3);
});

it('covers container round-trips and stream state', function (): void {
    $payload = qt_runtime_payload('QtCore/containers_and_streams.php');

    expect($payload['watch_file_count_before'])->toBe(1)
        ->and($payload['watch_dir_count_before'])->toBe(1)
        ->and($payload['watch_file_count_after'])->toBe(0)
        ->and($payload['program'])->toBe('/bin/echo')
        ->and($payload['argument_count'])->toBe(3)
        ->and($payload['env_has_demo_mode'])->toBeTrue()
        ->and($payload['env_key_count'])->toBeGreaterThan(0)
        ->and($payload['first_line'])->toBe('sales-eu,1280')
        ->and($payload['rest'])->toBe('sales-us,1540')
        ->and($payload['debug_verbosity'])->toBe(5)
        ->and($payload['debug_auto_insert_spaces'])->toBeFalse()
        ->and($payload['debug_quote_strings'])->toBeFalse();
});

it('covers abstract item model row insertion and data access', function (): void {
    $payload = qt_runtime_payload('QtCore/abstract_item_models.php');

    expect($payload['table_rows'])->toBe(3)
        ->and($payload['table_columns'])->toBe(2)
        ->and($payload['table_cell'])->toBe('SO-002')
        ->and($payload['list_rows'])->toBe(3)
        ->and($payload['list_item'])->toBe('Order synced');
});

it('writes back by-reference scalar out parameters', function (): void {
    $payload = qt_runtime_payload('QtCore/byref_writeback.php');

    expect($payload['ok_is_bool'])->toBeTrue()
        ->and($payload['ok_type'])->toBe('bool')
        ->and($payload['ok_value'])->toBeTrue()
        ->and($payload['value'])->toBeInt();
});

it('supports QList-derived item selections through synthetic list parents', function (): void {
    $payload = qt_runtime_payload('QtCore/qitemselection_list_parent.php');

    expect($payload['inherits_list'])->toBeTrue()
        ->and($payload['count'])->toBe(1)
        ->and($payload['item_class'])->toBe('Qt\\Core\\QItemSelectionRange');
});

it('supports QList-derived xml stream attributes through synthetic list parents', function (): void {
    $payload = qt_runtime_payload('QtCore/qxmlstreamattributes_list_parent.php');

    expect($payload['inherits_list'])->toBeTrue()
        ->and($payload['count'])->toBe(1)
        ->and($payload['item_class'])->toBe('Qt\\Core\\QXmlStreamAttribute');
});

it('exits cleanly on qcoreapplication quit with qobject signal callbacks', function (): void {
    $payload = qt_runtime_payload('QtCore/qobject_shutdown_quit.php');

    expect($payload['ticks'])->toBe(1)
        ->and($payload['about_to_quit_hits'])->toBe(0)
        ->and($payload['notify_hits'])->toBe(1)
        ->and($payload['connections_are_objects'])->toBeTrue();
});

it('dispatches qthread signals safely back to request thread', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_signal_dispatch_safety.php');

    expect($payload['started_hits'])->toBe(1)
        ->and($payload['finished_hits'])->toBe(1)
        ->and($payload['timed_out'])->toBeFalse()
        ->and($payload['connections_are_objects'])->toBeTrue();
});

it('dispatches qthread signals without requiring a qcoreapplication event loop', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_signal_dispatch_no_event_loop.php');

    expect($payload['started_hits'])->toBeGreaterThanOrEqual(1)
        ->and($payload['finished_hits'])->toBeGreaterThanOrEqual(1)
        ->and($payload['timed_out'])->toBeFalse()
        ->and($payload['wait_ok'])->toBeTrue()
        ->and($payload['connections_are_objects'])->toBeTrue();
});

it('dispatches qthread virtual overrides back to owner thread', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_virtual_dispatch.php');

    expect($payload['wait_ok'])->toBeTrue()
        ->and($payload['run_hits'])->toBe(1)
        ->and($payload['finished_hits'])->toBe(1)
        ->and($payload['timed_out'])->toBeFalse();
});

it('moves a php qobject subclass to a qthread and restores its live state there', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_move_to_thread_php_subclass.php');

    expect($payload['move_ok'])->toBeTrue()
        ->and($payload['posted'])->toBeTrue()
        ->and($payload['started_hits'])->toBe(1)
        ->and($payload['wait_ok'])->toBeTrue()
        ->and($payload['finished_hits'])->toBe(1)
        ->and($payload['callback_on_main_thread'])->toBeTrue()
        ->and($payload['worker_thread_differs'])->toBeTrue()
        ->and($payload['received_name'])->toBeString()
        ->and($payload['received_name'])->toStartWith('phase1:moved:7:');
});

it('allows the phase 2 moved-source whitelist on the original wrapper', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_move_to_thread_source_handle_whitelist.php');

    expect($payload['move_ok'])->toBeTrue()
        ->and($payload['wait_ok'])->toBeTrue()
        ->and($payload['thread_is_qthread'])->toBeTrue()
        ->and($payload['object_name'])->toBeString()
        ->and($payload['object_name'])->toStartWith('phase2:')
        ->and($payload['property_value'])->toBe('worker-native')
        ->and($payload['signals_blocked'])->toBeFalse()
        ->and($payload['has_dynamic_data'])->toBeTrue()
        ->and($payload['inherits_qobject'])->toBeTrue()
        ->and($payload['worker_thread_id'])->not->toBe($payload['main_thread_id']);
});

it('rejects stale source-handle property and mutation access after move', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_move_to_thread_source_handle_rejection.php');

    expect($payload['move_ok'])->toBeTrue()
        ->and($payload['property_read']['threw'])->toBeTrue()
        ->and($payload['property_write']['threw'])->toBeTrue()
        ->and($payload['set_object_name']['threw'])->toBeTrue()
        ->and($payload['set_property']['threw'])->toBeTrue()
        ->and($payload['block_signals']['threw'])->toBeTrue();
});

it('keeps moved-source observer registration working on the callback owner thread', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_move_to_thread_source_handle_observers.php');

    expect($payload['move_ok'])->toBeTrue()
        ->and($payload['wait_ok'])->toBeTrue()
        ->and($payload['notify_hits'])->toBe(1)
        ->and($payload['signal_hits'])->toBe(1)
        ->and($payload['notify_on_main_thread'])->toBeTrue()
        ->and($payload['signal_on_main_thread'])->toBeTrue()
        ->and($payload['connections_are_objects'])->toBeTrue();
});

it('hardens moved-source lifetime behavior for destruction and invalidation', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_move_to_thread_source_handle_lifetime.php');

    expect($payload['move_alive_ok'])->toBeTrue()
        ->and($payload['move_dead_ok'])->toBeTrue()
        ->and($payload['alive_signal_hits'])->toBe(1)
        ->and($payload['wait_alive_ok'])->toBeTrue()
        ->and($payload['wait_dead_ok'])->toBeTrue()
        ->and($payload['thread_probe']['threw'])->toBeTrue()
        ->and($payload['property_probe']['threw'])->toBeTrue();
});

it('hides stale php state from debug output and rejects clone on moved source handles', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_move_to_thread_source_handle_debug_clone.php');

    expect($payload['move_ok'])->toBeTrue()
        ->and($payload['clone_probe']['threw'])->toBeTrue()
        ->and($payload['dump_has_state'])->toBeFalse()
        ->and($payload['dump_has_meta'])->toBeFalse()
        ->and($payload['dump_has_source_custom'])->toBeFalse();
});

it('handles burst cross-thread signal dispatch without timing out', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_signal_burst_stress.php');

    expect($payload['timed_out'])->toBeFalse()
        ->and($payload['wait_all_ok'])->toBeTrue()
        ->and($payload['started_hits'])->toBe($payload['thread_count'])
        ->and($payload['finished_hits'])->toBe($payload['thread_count']);
});

it('runs qthread task mode with sequential reuse and event streaming', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_qthread_task_mode_basic.php');

    expect($payload['first_timed_out'])->toBeFalse()
        ->and($payload['second_timed_out'])->toBeFalse()
        ->and($payload['first_wait_ok'])->toBeTrue()
        ->and($payload['second_wait_ok'])->toBeTrue()
        ->and($payload['after_first'])->toBe(5)
        ->and($payload['after_second'])->toBe(8)
        ->and($payload['listener_removed'])->toBeTrue()
        ->and($payload['is_finished'])->toBeTrue()
        ->and($payload['is_running'])->toBeFalse();
});

it('keeps qthread task events flowing when one worker finishes earlier', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_qthread_task_mode_shutdown_isolation.php');

    expect($payload['timed_out'])->toBeFalse()
        ->and($payload['a_done'])->toBeTrue()
        ->and($payload['b_done'])->toBeTrue()
        ->and($payload['b_result_after_a_done'])->toBeTrue()
        ->and($payload['b_progress_total'])->toBeGreaterThan(0)
        ->and($payload['b_progress_after_a_done'])->toBeGreaterThan(0)
        ->and($payload['wait_a'])->toBeTrue()
        ->and($payload['wait_b'])->toBeTrue()
        ->and($payload['off_a_progress'])->toBeTrue()
        ->and($payload['off_b_progress'])->toBeTrue()
        ->and($payload['off_a_result'])->toBeTrue()
        ->and($payload['off_b_result'])->toBeTrue();
});

it('covers qthread task-mode sequential reuse and owner context parity', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_qthread_task_parity_reuse.php');

    expect($payload['owner_current_thread_object'])->toBeTrue()
        ->and($payload['owner_thread_id_type'])->not->toBe('')
        ->and($payload['stack_applied'])->toBeTrue()
        ->and($payload['started_hits'])->toBeGreaterThanOrEqual(2)
        ->and($payload['finished_hits'])->toBeGreaterThanOrEqual(2)
        ->and($payload['run1_running_observed'])->toBeTrue()
        ->and($payload['run1_timed_out'])->toBeFalse()
        ->and($payload['run1_wait_ok'])->toBeTrue()
        ->and($payload['run1_finished_after_wait'])->toBeTrue()
        ->and($payload['run1_running_after_wait'])->toBeFalse()
        ->and($payload['run1_status'])->toBe('done')
        ->and($payload['run2_running_observed'])->toBeTrue()
        ->and($payload['run2_timed_out'])->toBeFalse()
        ->and($payload['run2_wait_ok'])->toBeTrue()
        ->and($payload['run2_finished_after_wait'])->toBeTrue()
        ->and($payload['run2_running_after_wait'])->toBeFalse()
        ->and($payload['run2_status'])->toBe('done')
        ->and($payload['disconnect_started'])->toBeTrue()
        ->and($payload['disconnect_finished'])->toBeTrue()
        ->and($payload['off_result'])->toBeTrue();
});

it('covers qthread task-mode interruption request/read semantics', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_qthread_task_parity_interrupt.php');

    expect($payload['timed_out'])->toBeFalse()
        ->and($payload['wait_ok'])->toBeTrue()
        ->and($payload['finished_after_wait'])->toBeTrue()
        ->and($payload['running_after_wait'])->toBeFalse()
        ->and($payload['interrupt_requested'])->toBeTrue()
        ->and($payload['interruption_state_after_request'])->toBeTrue()
        ->and($payload['status'])->toBe('interrupted')
        ->and($payload['off_progress'])->toBeTrue()
        ->and($payload['off_result'])->toBeTrue();
});

it('covers qthread task-mode quit/exit compatibility', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_qthread_task_parity_quit_exit.php');

    expect($payload['timed_out'])->toBeFalse()
        ->and($payload['wait_ok'])->toBeTrue()
        ->and($payload['finished_after_wait'])->toBeTrue()
        ->and($payload['running_after_wait'])->toBeFalse()
        ->and($payload['quit_exit_called'])->toBeTrue()
        ->and($payload['status'])->toBe('done')
        ->and($payload['off_progress'])->toBeTrue()
        ->and($payload['off_result'])->toBeTrue();
});

it('covers qthread task-mode setPriority while running', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_qthread_task_parity_priority_running.php');

    expect($payload['timed_out'])->toBeFalse()
        ->and($payload['wait_ok'])->toBeTrue()
        ->and($payload['finished_after_wait'])->toBeTrue()
        ->and($payload['running_after_wait'])->toBeFalse()
        ->and($payload['priority_set_called'])->toBeTrue()
        ->and($payload['status'])->toBe('done')
        ->and($payload['off_progress'])->toBeTrue()
        ->and($payload['off_result'])->toBeTrue();
});

it('runs blocking worker jobs in parallel isolated runtimes', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_runtime_parallel.php');

    expect($payload['parallel_window_ok'])->toBeTrue()
        ->and($payload['error_propagated'])->toBeTrue()
        ->and($payload['runtime_a_enqueued'])->toBeGreaterThanOrEqual(1)
        ->and($payload['runtime_b_enqueued'])->toBeGreaterThanOrEqual(1)
        ->and($payload['stop_a'])->toBeTrue()
        ->and($payload['stop_b'])->toBeTrue();
});

it('enforces bounded worker queue limits with deterministic rejection accounting', function (): void {
    $payload = qt_runtime_thread_payload(
        'QtCore/thread_runtime_queue_limits.php',
        ['QT_QTHREADRUNTIME_MAX_QUEUE_DEPTH' => '1'],
        10,
    );

    expect($payload['queue_max_depth'])->toBe(1)
        ->and($payload['accepted'])->toBeGreaterThan(0)
        ->and($payload['rejected'])->toBeGreaterThan(0)
        ->and($payload['rejected_full'])->toBeGreaterThan(0)
        ->and($payload['stopped'])->toBeTrue();
});

it('returns null on await timeout and records timeout counters', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_runtime_timeout.php', [], 10);

    expect($payload['first_is_null'])->toBeTrue()
        ->and($payload['second_is_zero'])->toBeTrue()
        ->and($payload['timeouts'])->toBeGreaterThanOrEqual(1)
        ->and($payload['stopped'])->toBeTrue();
});

it('handles shutdown race with queued jobs without worker crashes', function (): void {
    $payload = qt_runtime_thread_payload(
        'QtCore/thread_runtime_shutdown_race.php',
        ['QT_QTHREADRUNTIME_MAX_QUEUE_DEPTH' => '8'],
        10,
    );

    expect($payload['stop_ok'])->toBeTrue()
        ->and($payload['submitted'])->toBeGreaterThan(0)
        ->and($payload['stats_running'])->toBeFalse()
        ->and($payload['stats_worker_crash'])->toBe(0)
        ->and($payload['stats_canceled'])->toBeGreaterThanOrEqual(0);
});

it('accepts array callable descriptors and rejects closure callables deterministically', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_runtime_callable_forms.php', [], 10);

    expect($payload['array_callable_object'])->toBeTrue()
        ->and($payload['array_callable_class'])->toBe('DateTimeImmutable')
        ->and($payload['non_static_rejected'])->toBeTrue()
        ->and($payload['unknown_class_rejected'])->toBeFalse()
        ->and($payload['unknown_class_errored_on_await'])->toBeTrue()
        ->and($payload['closure_rejected'])->toBeTrue()
        ->and($payload['stopped'])->toBeTrue();
});

it('loads worker bootstrap script into isolated runtime context', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_runtime_bootstrap_script.php', [], 10);

    expect($payload['sum'])->toBe(42)
        ->and($payload['worker_bootstrap_failed'])->toBeFalse()
        ->and($payload['stopped'])->toBeTrue()
        ->and($payload['main_has_function'])->toBeFalse();
});

it('executes bootstrap-defined array callables inside worker runtime', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_runtime_bootstrap_array_callable.php', [], 10);

    expect($payload['result'])->toBe(42)
        ->and($payload['await_errored'])->toBeFalse()
        ->and($payload['worker_bootstrap_failed'])->toBeFalse()
        ->and($payload['stopped'])->toBeTrue()
        ->and($payload['main_has_class'])->toBeFalse();
});

it('rejects unsupported cross-runtime payload values deterministically', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_runtime_payload_validation.php', [], 10);

    expect($payload['resource_rejected'])->toBeTrue()
        ->and($payload['closure_rejected'])->toBeTrue()
        ->and($payload['normal_value'])->toBe(9)
        ->and($payload['stopped'])->toBeTrue();
});

it('streams worker events to owner listeners with dynamic event names', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_runtime_event_publish.php', [], 10);

    expect($payload['result'])->toBe(123)
        ->and($payload['progress_count'])->toBeGreaterThanOrEqual(5)
        ->and($payload['decompressed_count'])->toBeGreaterThanOrEqual(2)
        ->and($payload['progress_first'])->toBe(1)
        ->and($payload['off_invalid'])->toBeFalse()
        ->and($payload['off_progress'])->toBeTrue()
        ->and($payload['off_decompressed'])->toBeTrue()
        ->and($payload['drained'])->toBeGreaterThan(0)
        ->and($payload['events_out_enqueued'])->toBeGreaterThanOrEqual(7)
        ->and($payload['events_out_drained'])->toBeGreaterThanOrEqual(7)
        ->and($payload['stopped'])->toBeTrue();
});

it('supports owner-to-worker commands through send/receive', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_runtime_event_send_receive.php', [], 10);

    expect($payload['send_missing'])->toBeFalse()
        ->and($payload['send_pause'])->toBeTrue()
        ->and($payload['send_resume'])->toBeTrue()
        ->and($payload['send_stop'])->toBeTrue()
        ->and($payload['result'])->toBeArray()
        ->and($payload['ack_count'])->toBeGreaterThanOrEqual(3)
        ->and($payload['drained'])->toBeGreaterThan(0)
        ->and($payload['events_in_enqueued'])->toBeGreaterThanOrEqual(3)
        ->and($payload['events_in_drained'])->toBeGreaterThanOrEqual(3)
        ->and($payload['stopped'])->toBeTrue();
});

it('drops newest worker events when outbound event queue is full', function (): void {
    $payload = qt_runtime_thread_payload(
        'QtCore/thread_runtime_event_burst_drop.php',
        ['QT_QTHREADRUNTIME_EVENT_OUT_QUEUE_DEPTH' => '8'],
        10,
    );

    expect($payload['published'])->toBeGreaterThan(0)
        ->and($payload['received'])->toBeGreaterThan(0)
        ->and($payload['received'])->toBeLessThanOrEqual($payload['published'])
        ->and($payload['drained'])->toBeGreaterThan(0)
        ->and($payload['events_out_enqueued'])->toBe($payload['published'])
        ->and($payload['events_out_dropped_full'])->toBeGreaterThan(0)
        ->and($payload['stopped'])->toBeTrue();
});

it('isolates listener exceptions and continues dispatch', function (): void {
    $payload = qt_runtime_thread_payload('QtCore/thread_runtime_event_listener_exception.php', [], 10);

    expect($payload['result'])->toBe(7)
        ->and($payload['first_hits'])->toBeGreaterThan(0)
        ->and($payload['second_hits'])->toBeGreaterThan(0)
        ->and($payload['second_hits'])->toBe($payload['first_hits'])
        ->and($payload['listener_dispatch_errors'])->toBeGreaterThan(0)
        ->and($payload['stopped'])->toBeTrue();
});
