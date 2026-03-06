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
#include "{!! $ctx->phpHeaderFilename() !!}"
@foreach($ctx->classHeaders() as $header)
#include "{!! $header !!}"
@endforeach
@if($ctx->requiresBuildInfoRegistration())
#include "qt_buildinfo.h"
@endif

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

zend_module_entry {!! $ctx->extensionName !!}_module_entry = {
    STANDARD_MODULE_HEADER,
    "{!! $ctx->extensionName !!}",
    NULL,
    PHP_MINIT({!! $ctx->extensionName !!}),
    NULL,
    NULL,
    NULL,
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
