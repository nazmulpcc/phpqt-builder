@php
/** @var \QtBuilder\Build\ExtensionBuildContext $ctx */
/** @var \QtBuilder\Build\RuntimeModuleMetadata|null $buildInfoModule */
$buildInfoModule = $ctx->currentModuleMetadata();
$buildInfoDependencies = $buildInfoModule !== null && $buildInfoModule->dependencies !== []
    ? implode(', ', $buildInfoModule->dependencies)
    : '-';
@endphp
#ifdef HAVE_CONFIG_H
# include <config.h>
#endif

#ifdef __cplusplus
extern "C" {
#endif
#include "php.h"
#include "ext/standard/info.h"
#ifdef __cplusplus
}
#endif
#include "qt_php_compat.h"
#include <QtCore/QCoreApplication>
#include <QtCore/QMetaObject>
#include <QtCore/QObject>
#include <atomic>
#include <cstdint>
#include <cstring>
#include <deque>
#include <functional>
#include <mutex>
#include <string>
#include "{!! $ctx->phpHeaderFilename() !!}"
@foreach($ctx->classHeaders() as $header)
#include "{!! $header !!}"
@endforeach
@if($ctx->requiresBuildInfoRegistration())
#include "qt_buildinfo.h"
@endif
@if($ctx->includeSignalConnectionSupport)
#include "classes/qt_php_signal_helpers.h"
#include "classes/qt_qmetaobject_bridge.h"
@endif

static std::atomic_bool qt_shutdown_in_progress{false};
static std::atomic_bool qt_about_to_quit_hooked{false};
static std::atomic<zend_ulong> qt_primary_owner_thread_id{0};
static constexpr size_t QT_OWNER_TASK_QUEUE_MAX_DEPTH = 4096;
static std::mutex qt_owner_task_mutex;
static std::deque<std::function<void()>> qt_owner_task_queue;
static std::atomic_uint32_t qt_owner_task_pending{0};
static std::atomic_bool qt_owner_drain_scheduled{false};
static std::atomic_uint64_t qt_owner_task_enqueued{0};
static std::atomic_uint64_t qt_owner_task_drained{0};
static std::atomic_uint64_t qt_owner_task_dropped_full{0};
static std::atomic_uint64_t qt_owner_task_dropped_shutdown{0};
static std::atomic_uint64_t qt_owner_virtual_timeouts{0};
static thread_local bool qt_owner_drain_active = false;
@if($ctx->includeSignalConnectionSupport)
static void (*qt_saved_execute_ex)(zend_execute_data *execute_data) = nullptr;
@endif
ZEND_DECLARE_MODULE_GLOBALS({!! $ctx->extensionName !!})

static void php_{!! $ctx->extensionName !!}_init_globals(zend_{!! $ctx->extensionName !!}_globals *globals)
{
    memset(globals, 0, sizeof(*globals));
}

bool qt_runtime_is_owner_thread(void)
{
#if defined(ZTS)
    if (!tsrm_is_managed_thread()) {
        return false;
    }

    if (!QT_RUNTIME_G(request_active)) {
        return false;
    }

    return (zend_ulong) (uintptr_t) tsrm_thread_id() == QT_RUNTIME_G(owner_thread_id);
#else
    if (!QT_RUNTIME_G(request_active)) {
        return false;
    }

    return true;
#endif
}

static inline bool qt_runtime_is_primary_owner_thread(void)
{
#if defined(ZTS)
    if (!tsrm_is_managed_thread()) {
        return false;
    }

    zend_ulong primary_owner_thread_id = qt_primary_owner_thread_id.load(std::memory_order_acquire);
    if (primary_owner_thread_id == 0) {
        return false;
    }

    return (zend_ulong) (uintptr_t) tsrm_thread_id() == primary_owner_thread_id;
#else
    return QT_RUNTIME_G(request_active);
#endif
}

bool qt_runtime_can_call_zend(void)
{
#if defined(ZTS)
    if (!tsrm_is_managed_thread()) {
        return false;
    }
#endif

    if (!QT_RUNTIME_G(request_active)) {
        return false;
    }

    if (qt_runtime_is_shutdown_in_progress()) {
        return false;
    }

    return qt_runtime_is_owner_thread();
}

bool qt_runtime_is_shutdown_in_progress(void)
{
    return qt_shutdown_in_progress.load(std::memory_order_acquire);
}

@if($ctx->includeSignalConnectionSupport)
static void qt_execute_signal_guard(zend_execute_data *execute_data)
{
    zend_function *func = execute_data != NULL ? execute_data->func : NULL;
    if (func != NULL
        && func->type == ZEND_USER_FUNCTION
        && qt_php_signal_function_is_declaration(func)) {
        const char *method_name = (func->common.function_name != NULL)
            ? ZSTR_VAL(func->common.function_name)
            : "<unknown>";
        zend_throw_error(
            NULL,
            "Signal \"%s\" cannot be invoked directly; use emit('%s', ...).",
            method_name,
            method_name
        );
        return;
    }

    if (qt_saved_execute_ex != nullptr) {
        qt_saved_execute_ex(execute_data);
        return;
    }

    execute_ex(execute_data);
}
@endif

zend_class_entry *qt_runtime_exception_ce(void)
{
    zend_string *class_name = zend_string_init("RuntimeException", sizeof("RuntimeException") - 1, 0);
    zend_class_entry *ce = zend_lookup_class_ex(class_name, NULL, ZEND_FETCH_CLASS_NO_AUTOLOAD);
    zend_string_release(class_name);

    if (ce != NULL && instanceof_function(ce, zend_ce_throwable)) {
        return ce;
    }

    return zend_ce_exception;
}

static inline void qt_runtime_drop_owner_tasks(bool count_as_shutdown = false)
{
    size_t dropped = 0;
    std::lock_guard<std::mutex> lock(qt_owner_task_mutex);
    dropped = qt_owner_task_queue.size();
    qt_owner_task_queue.clear();
    qt_owner_task_pending.store(0, std::memory_order_release);
    qt_owner_drain_scheduled.store(false, std::memory_order_release);
    if (count_as_shutdown && dropped > 0) {
        qt_owner_task_dropped_shutdown.fetch_add((uint64_t) dropped, std::memory_order_acq_rel);
    }
}

bool qt_runtime_enqueue_owner_task(std::function<void()> task)
{
    if (!task) {
        return false;
    }

    if (qt_runtime_is_shutdown_in_progress()) {
        qt_owner_task_dropped_shutdown.fetch_add(1, std::memory_order_acq_rel);
        return false;
    }

    {
        std::lock_guard<std::mutex> lock(qt_owner_task_mutex);
        if (qt_runtime_is_shutdown_in_progress()) {
            qt_owner_task_dropped_shutdown.fetch_add(1, std::memory_order_acq_rel);
            return false;
        }

        if (qt_owner_task_queue.size() >= QT_OWNER_TASK_QUEUE_MAX_DEPTH) {
            qt_owner_task_dropped_full.fetch_add(1, std::memory_order_acq_rel);
            return false;
        }

        qt_owner_task_queue.emplace_back(std::move(task));
        qt_owner_task_pending.fetch_add(1, std::memory_order_acq_rel);
        qt_owner_task_enqueued.fetch_add(1, std::memory_order_acq_rel);
    }

    qt_runtime_schedule_owner_drain();
    return true;
}

void qt_runtime_schedule_owner_drain(void)
{
    if (qt_runtime_is_shutdown_in_progress()) {
        return;
    }

    QCoreApplication *app = QCoreApplication::instance();
    if (app == NULL) {
        return;
    }

    bool expected = false;
    if (!qt_owner_drain_scheduled.compare_exchange_strong(expected, true, std::memory_order_acq_rel)) {
        return;
    }

    QMetaObject::invokeMethod(
        app,
        []() {
            qt_owner_drain_scheduled.store(false, std::memory_order_release);
            qt_runtime_owner_safe_point();
        },
        Qt::QueuedConnection
    );
}

void qt_runtime_drain_owner_tasks(zend_long max_items)
{
    if (!qt_runtime_is_primary_owner_thread()) {
        return;
    }

    if (qt_owner_drain_active) {
        return;
    }

    if (max_items == 0) {
        return;
    }

    if (qt_runtime_is_shutdown_in_progress()) {
        qt_runtime_drop_owner_tasks();
        return;
    }

    if (!qt_runtime_can_call_zend()) {
        return;
    }

    qt_owner_drain_active = true;
    zend_long processed = 0;

    while (max_items < 0 || processed < max_items) {
        std::function<void()> task;
        {
            std::lock_guard<std::mutex> lock(qt_owner_task_mutex);
            if (qt_owner_task_queue.empty()) {
                break;
            }

            task = std::move(qt_owner_task_queue.front());
            qt_owner_task_queue.pop_front();
        }

        qt_owner_task_pending.fetch_sub(1, std::memory_order_acq_rel);
        qt_owner_task_drained.fetch_add(1, std::memory_order_acq_rel);
        processed++;

        if (task) {
            task();
        }

        if (qt_runtime_is_shutdown_in_progress()) {
            qt_runtime_drop_owner_tasks(true);
            break;
        }
    }

    qt_owner_drain_active = false;

    if (qt_owner_task_pending.load(std::memory_order_acquire) > 0) {
        qt_runtime_schedule_owner_drain();
    }
}

void qt_runtime_owner_safe_point(void)
{
    if (!qt_runtime_can_call_zend() || !qt_runtime_is_primary_owner_thread()) {
        return;
    }

    qt_runtime_drain_owner_tasks(-1);
}

void qt_runtime_record_virtual_timeout(void)
{
    qt_owner_virtual_timeouts.fetch_add(1, std::memory_order_acq_rel);
}

void qt_runtime_mark_shutdown_in_progress(void)
{
    qt_shutdown_in_progress.store(true, std::memory_order_release);
}

void qt_runtime_try_hook_about_to_quit(void)
{
    if (qt_runtime_is_shutdown_in_progress()) {
        return;
    }

    QCoreApplication *app = QCoreApplication::instance();
    if (app == NULL) {
        return;
    }

    bool expected = false;
    if (!qt_about_to_quit_hooked.compare_exchange_strong(expected, true, std::memory_order_acq_rel)) {
        return;
    }

    QObject::connect(
        app,
        &QCoreApplication::aboutToQuit,
        app,
        []() {
            qt_runtime_mark_shutdown_in_progress();
        }
    );
}

static inline void qt_runtime_shutdown_qcoreapplication(void)
{
    QCoreApplication *app = QCoreApplication::instance();
    if (app == NULL) {
        return;
    }

    qt_runtime_mark_shutdown_in_progress();

    QCoreApplication::sendPostedEvents(NULL, 0);
    QCoreApplication::processEvents();
    delete app;
    qt_about_to_quit_hooked.store(false, std::memory_order_release);
}

PHP_MINFO_FUNCTION({!! $ctx->extensionName !!})
{
    php_info_print_table_start();
    php_info_print_table_row(2, "{!! $ctx->extensionName !!} support", "enabled");
    php_info_print_table_row(2, "version", PHP_{!! strtoupper($ctx->extensionName) !!}_VERSION);
    php_info_print_table_row(2, "build mode", "{!! $ctx->buildMode !!}");
@if($ctx->runtimeManifest instanceof \QtBuilder\Build\RuntimeManifest)
    php_info_print_table_row(2, "Qt version", "{!! $ctx->runtimeManifest->qtVersion !!}");
@endif
@if($ctx->buildMode === \QtBuilder\Build\RuntimeManifest::MODE_MONOLITHIC && $ctx->runtimeManifest instanceof \QtBuilder\Build\RuntimeManifest)
    zend_string *qt_buildinfo_built_modules = qt_buildinfo_join_built_modules();
    php_info_print_table_row(2, "built modules", ZSTR_VAL(qt_buildinfo_built_modules));
    zend_string_release(qt_buildinfo_built_modules);
@elseif($buildInfoModule instanceof \QtBuilder\Build\RuntimeModuleMetadata)
    zend_string *qt_buildinfo_built_modules = qt_buildinfo_join_built_modules();
    zend_string *qt_buildinfo_loaded_modules = qt_buildinfo_join_loaded_modules();
    php_info_print_table_row(2, "current module", "{!! $buildInfoModule->module !!}");
    php_info_print_table_row(2, "dependency modules", "{!! $buildInfoDependencies !!}");
    php_info_print_table_row(2, "built modules", ZSTR_VAL(qt_buildinfo_built_modules));
    php_info_print_table_row(2, "loaded modules", ZSTR_VAL(qt_buildinfo_loaded_modules));
    zend_string_release(qt_buildinfo_built_modules);
    zend_string_release(qt_buildinfo_loaded_modules);
@endif
    php_info_print_table_row(2, "owner queue max depth", "4096");
    php_info_print_table_row(2, "owner queue enqueued", std::to_string(qt_owner_task_enqueued.load(std::memory_order_acquire)).c_str());
    php_info_print_table_row(2, "owner queue drained", std::to_string(qt_owner_task_drained.load(std::memory_order_acquire)).c_str());
    php_info_print_table_row(2, "owner queue dropped (full)", std::to_string(qt_owner_task_dropped_full.load(std::memory_order_acquire)).c_str());
    php_info_print_table_row(2, "owner queue dropped (shutdown)", std::to_string(qt_owner_task_dropped_shutdown.load(std::memory_order_acquire)).c_str());
    php_info_print_table_row(2, "virtual dispatch timeouts", std::to_string(qt_owner_virtual_timeouts.load(std::memory_order_acquire)).c_str());
@if($ctx->includeThreadRuntimeSupport)
    qt_qthreadruntime_phpinfo_rows();
@endif
    php_info_print_table_end();
}

PHP_MINIT_FUNCTION({!! $ctx->extensionName !!})
{
    ZEND_INIT_MODULE_GLOBALS({!! $ctx->extensionName !!}, php_{!! $ctx->extensionName !!}_init_globals, NULL);

@if($ctx->includeSignalConnectionSupport)
    if (qt_saved_execute_ex == nullptr) {
        qt_saved_execute_ex = zend_execute_ex;
        zend_execute_ex = qt_execute_signal_guard;
    }
@endif

@foreach($ctx->classMinits() as $minit)
    if (PHP_MINIT({!! $minit !!})(INIT_FUNC_ARGS_PASSTHRU) != SUCCESS) {
        return FAILURE;
    }
@endforeach
@if($ctx->requiresBuildInfoRegistration() && $buildInfoModule instanceof \QtBuilder\Build\RuntimeModuleMetadata && $ctx->runtimeManifest instanceof \QtBuilder\Build\RuntimeManifest)

    if (qt_buildinfo_register_module("{!! $buildInfoModule->module !!}", "{!! $buildInfoModule->extensionName !!}", "{!! $ctx->runtimeManifest->qtVersion !!}", "{!! $ctx->runtimeManifest->builderAbiVersion !!}") != SUCCESS) {
        return FAILURE;
    }
@endif

    return SUCCESS;
}

@if($ctx->includeThreadRuntimeSupport)
PHP_MSHUTDOWN_FUNCTION({!! $ctx->extensionName !!})
{
    qt_qthreadruntime_restore_sapi_deactivate();
@if($ctx->includeSignalConnectionSupport)
    if (qt_saved_execute_ex != nullptr) {
        zend_execute_ex = qt_saved_execute_ex;
        qt_saved_execute_ex = nullptr;
    }
@endif

    return SUCCESS;
}

@endif
PHP_RINIT_FUNCTION({!! $ctx->extensionName !!})
{
#if defined(ZTS) && (defined(COMPILE_DL_{!! strtoupper($ctx->extensionName) !!}) || defined(ZEND_COMPILE_DL_EXT))
    ZEND_TSRMLS_CACHE_UPDATE();
#endif

@if($ctx->includeThreadRuntimeSupport)
    bool _qt_is_worker_request = qt_qthreadruntime_is_worker_request_context();
@else
    bool _qt_is_worker_request = false;
@endif

#if defined(ZTS)
    QT_RUNTIME_G(owner_thread_id) = (zend_ulong) (uintptr_t) tsrm_thread_id();
#else
    QT_RUNTIME_G(owner_thread_id) = 0;
#endif
    QT_RUNTIME_G(request_active) = true;

    if (!_qt_is_worker_request) {
#if defined(ZTS)
        qt_primary_owner_thread_id.store(QT_RUNTIME_G(owner_thread_id), std::memory_order_release);
#else
        qt_primary_owner_thread_id.store(0, std::memory_order_release);
#endif
        qt_shutdown_in_progress.store(false, std::memory_order_release);
        qt_about_to_quit_hooked.store(false, std::memory_order_release);
        qt_runtime_drop_owner_tasks();
    }
@if($ctx->includeSignalConnectionSupport)

    if (qt_php_signal_ensure_current_thread_dispatcher() == NULL) {
        QT_RUNTIME_G(request_active) = false;
        zend_throw_error(NULL, "Failed to initialize the PHP signal dispatcher for the current thread.");
        return FAILURE;
    }
@endif

    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION({!! $ctx->extensionName !!})
{
@if($ctx->includeThreadRuntimeSupport)
    if (qt_qthreadruntime_is_worker_request_context()) {
        QT_RUNTIME_G(request_active) = false;
        return SUCCESS;
    }
@endif

    qt_runtime_mark_shutdown_in_progress();
    qt_runtime_drain_owner_tasks(256);
    qt_runtime_drop_owner_tasks(true);
    qt_primary_owner_thread_id.store(0, std::memory_order_release);
@if($ctx->includeThreadRuntimeSupport)
    qt_qthreadruntime_shutdown_all(2000);
    qt_runtime_shutdown_qcoreapplication();
@else
    qt_runtime_shutdown_qcoreapplication();
@endif
    QT_RUNTIME_G(request_active) = false;

    return SUCCESS;
}

extern "C" zend_module_entry {!! $ctx->extensionName !!}_module_entry = {
    STANDARD_MODULE_HEADER,
    "{!! $ctx->extensionName !!}",
    NULL,
    PHP_MINIT({!! $ctx->extensionName !!}),
@if($ctx->includeThreadRuntimeSupport)
    PHP_MSHUTDOWN({!! $ctx->extensionName !!}),
@else
    NULL,
@endif
    PHP_RINIT({!! $ctx->extensionName !!}),
    PHP_RSHUTDOWN({!! $ctx->extensionName !!}),
    PHP_MINFO({!! $ctx->extensionName !!}),
    PHP_{!! strtoupper($ctx->extensionName) !!}_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#if defined(COMPILE_DL_{!! strtoupper($ctx->extensionName) !!}) || defined(ZEND_COMPILE_DL_EXT)
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE({!! $ctx->extensionName !!})
#endif
