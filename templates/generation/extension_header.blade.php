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

#endif /* PHP_{!! strtoupper($ctx->extensionName) !!}_H */
