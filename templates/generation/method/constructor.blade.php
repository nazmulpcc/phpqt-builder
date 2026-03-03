@php
/**
 * Constructor method template.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
@endphp
/* {!! $method->name !!} */
ZEND_METHOD({!! $ctx->zendClassSymbol !!}, __construct)
{
@if($method->hasNoParams())
    ZEND_PARSE_PARAMETERS_NONE();
@else
@foreach($method->params as $param)
    {!! $param->cDeclaration() !!};
@endforeach

    ZEND_PARSE_PARAMETERS_START({!! $method->requiredArgCount !!}, {!! $method->maxArgCount !!})
@php $seenOptional = false; @endphp
@foreach($method->params as $param)
@if($param->isOptional && !$seenOptional)
@php $seenOptional = true; @endphp
        Z_PARAM_OPTIONAL
@endif
        {!! $param->zppMacro !!}
@endforeach
    ZEND_PARSE_PARAMETERS_END();

@endif
    {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);

    if (intern->native_ptr != NULL) {
        zend_throw_error(NULL, "{!! $ctx->phpClassName !!}::__construct() called twice");
        RETURN_THROWS();
    }

@if($ctx->requiresVirtualTrampoline)
    bool _qt_use_trampoline = (Z_OBJCE_P(ZEND_THIS) != {!! $ctx->ceVarName !!});
@endif

@if($method->hasNoParams())
@if($ctx->requiresVirtualTrampoline)
    if (_qt_use_trampoline) {
        intern->native_ptr = new {!! $ctx->nativeInstantiationType !!}();
        static_cast<{!! $ctx->trampolineTypeName !!} *>(intern->native_ptr)->php_object = &intern->std;
        intern->native_is_generated_subclass = true;
        intern->native_is_virtual_trampoline = true;
    } else {
        intern->native_ptr = new {!! $ctx->plainNativeInstantiationType !!}();
        intern->native_is_generated_subclass = {!! $ctx->plainInstantiationUsesGeneratedType() ? 'true' : 'false' !!};
        intern->native_is_virtual_trampoline = false;
    }
@else
    intern->native_ptr = new {!! $ctx->nativeInstantiationType !!}();
@if($ctx->tracksGeneratedNativeSubclass)
    intern->native_is_generated_subclass = true;
@endif
@endif
@if($ctx->hasPreventDestroy)
    qt_track_native_instance(intern->native_ptr);
@endif
@elseif($method->isOverloaded)
@include('generation.method.constructor_dispatch', ['ctx' => $ctx, 'method' => $method])
@else
@if($ctx->needsArgvStorage)
    if (intern->extra_storage == NULL) {
        intern->extra_storage = new {!! $ctx->argvStorageStructName !!}();
    }
    auto *_qt_argv_storage = static_cast<{!! $ctx->argvStorageStructName !!} *>(intern->extra_storage);
@endif
@php $callPlan = $method->callPlan($ctx, $method->overloads[0] ?? null, $ctx->needsArgvStorage ? '_qt_argv_storage' : null); @endphp
@foreach($callPlan['setup_lines'] as $line)
    {!! $line !!}
@endforeach
@if($ctx->requiresVirtualTrampoline)
    if (_qt_use_trampoline) {
        intern->native_ptr = new {!! $ctx->nativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
        static_cast<{!! $ctx->trampolineTypeName !!} *>(intern->native_ptr)->php_object = &intern->std;
        intern->native_is_generated_subclass = true;
        intern->native_is_virtual_trampoline = true;
    } else {
        intern->native_ptr = new {!! $ctx->plainNativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
        intern->native_is_generated_subclass = {!! $ctx->plainInstantiationUsesGeneratedType() ? 'true' : 'false' !!};
        intern->native_is_virtual_trampoline = false;
    }
@else
    intern->native_ptr = new {!! $ctx->nativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
@if($ctx->tracksGeneratedNativeSubclass)
    intern->native_is_generated_subclass = true;
@endif
@endif
@if($ctx->hasPreventDestroy)
    qt_track_native_instance(intern->native_ptr);
@foreach($method->params as $param)
@if($param->isObject && !$param->isUnion)

    if ({!! $param->cVarName !!} != NULL) {
        intern->prevent_destroy = true;
    }
@endif
@endforeach
@endif
@endif
}
