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

#include "php.h"
#include "ext/standard/info.h"
#include <QtCore/QCoreApplication>
#include <QtCore/QObject>
#include <atomic>
#include <cstdint>
#include <cstring>
#include "{!! $ctx->phpHeaderFilename() !!}"
@foreach($ctx->classHeaders() as $header)
#include "{!! $header !!}"
@endforeach
@if($ctx->requiresBuildInfoRegistration())
#include "qt_buildinfo.h"
@endif

static std::atomic_bool qt_shutdown_in_progress{false};
static std::atomic_bool qt_about_to_quit_hooked{false};
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
    php_info_print_table_end();
}

PHP_MINIT_FUNCTION({!! $ctx->extensionName !!})
{
    ZEND_INIT_MODULE_GLOBALS({!! $ctx->extensionName !!}, php_{!! $ctx->extensionName !!}_init_globals, NULL);

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

PHP_RINIT_FUNCTION({!! $ctx->extensionName !!})
{
#if defined(ZTS) && defined(COMPILE_DL_{!! strtoupper($ctx->extensionName) !!})
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
#if defined(ZTS)
    QT_RUNTIME_G(owner_thread_id) = (zend_ulong) (uintptr_t) tsrm_thread_id();
#else
    QT_RUNTIME_G(owner_thread_id) = 0;
#endif
    QT_RUNTIME_G(request_active) = true;
    qt_shutdown_in_progress.store(false, std::memory_order_release);
    qt_about_to_quit_hooked.store(false, std::memory_order_release);

    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION({!! $ctx->extensionName !!})
{
    qt_runtime_shutdown_qcoreapplication();
    qt_runtime_mark_shutdown_in_progress();
    QT_RUNTIME_G(request_active) = false;

    return SUCCESS;
}

zend_module_entry {!! $ctx->extensionName !!}_module_entry = {
    STANDARD_MODULE_HEADER,
    "{!! $ctx->extensionName !!}",
    NULL,
    PHP_MINIT({!! $ctx->extensionName !!}),
    NULL,
    PHP_RINIT({!! $ctx->extensionName !!}),
    PHP_RSHUTDOWN({!! $ctx->extensionName !!}),
    PHP_MINFO({!! $ctx->extensionName !!}),
    PHP_{!! strtoupper($ctx->extensionName) !!}_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_{!! strtoupper($ctx->extensionName) !!}
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE({!! $ctx->extensionName !!})
#endif
