@php
/** @var \QtBuilder\Build\ExtensionBuildContext $ctx */
/** @var \QtBuilder\Build\RuntimeModuleMetadata|null $buildInfoModule */
$buildInfoModule = $ctx->currentModuleMetadata();
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
