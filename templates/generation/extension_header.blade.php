@php
/** @var \QtBuilder\Build\ExtensionBuildContext $ctx */
@endphp
/* {!! $ctx->extensionName !!} extension for PHP */

#ifndef PHP_{!! strtoupper($ctx->extensionName) !!}_H
# define PHP_{!! strtoupper($ctx->extensionName) !!}_H

extern zend_module_entry {!! $ctx->extensionName !!}_module_entry;
# define phpext_{!! $ctx->extensionName !!}_ptr &{!! $ctx->extensionName !!}_module_entry

# define PHP_{!! strtoupper($ctx->extensionName) !!}_VERSION "{!! $ctx->extensionVersion !!}"

# if defined(ZTS) && defined(COMPILE_DL_{!! strtoupper($ctx->extensionName) !!})
ZEND_TSRMLS_CACHE_EXTERN()
# endif

# ifdef __cplusplus
# include <cstdint>
# include <functional>

ZEND_BEGIN_MODULE_GLOBALS({!! $ctx->extensionName !!})
    zend_ulong owner_thread_id;
    bool request_active;
ZEND_END_MODULE_GLOBALS({!! $ctx->extensionName !!})

ZEND_EXTERN_MODULE_GLOBALS({!! $ctx->extensionName !!})
# define QT_RUNTIME_G(v) ZEND_MODULE_GLOBALS_ACCESSOR({!! $ctx->extensionName !!}, v)

bool qt_runtime_is_owner_thread(void);
bool qt_runtime_can_call_zend(void);
bool qt_runtime_is_shutdown_in_progress(void);
void qt_runtime_mark_shutdown_in_progress(void);
void qt_runtime_try_hook_about_to_quit(void);
bool qt_runtime_enqueue_owner_task(std::function<void()> task);
void qt_runtime_schedule_owner_drain(void);
void qt_runtime_drain_owner_tasks(zend_long max_items);
void qt_runtime_owner_safe_point(void);
# endif

#endif /* PHP_{!! strtoupper($ctx->extensionName) !!}_H */
