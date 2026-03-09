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
    zend_class_entry *_qt_actual_ce = Z_OBJCE_P(ZEND_THIS);
@if($ctx->isAbstract)
    bool _qt_use_trampoline = (_qt_actual_ce != {!! $ctx->ceVarName !!});
@else
    bool _qt_has_virtual_override = false;
    if (_qt_actual_ce != {!! $ctx->ceVarName !!}) {
        static const char * const _qt_virtual_methods[] = {
@foreach($ctx->virtualDispatchMethodNames() as $methodName)
            "{!! $methodName !!}",
@endforeach
        };
        _qt_has_virtual_override = qt_any_virtual_method_overridden_in_ce(
            _qt_actual_ce,
            {!! $ctx->ceVarName !!},
            _qt_virtual_methods,
            sizeof(_qt_virtual_methods) / sizeof(_qt_virtual_methods[0])
        );
    }
    bool _qt_use_trampoline = (_qt_actual_ce != {!! $ctx->ceVarName !!}) && _qt_has_virtual_override;
@endif
@endif
@if($method->overloadCount === 0)
    zend_throw_error(NULL, "{!! $ctx->phpClassName !!} cannot be instantiated directly.");
    RETURN_THROWS();
@else

@if($method->hasNoParams())
@if($ctx->requiresVirtualTrampoline)
    if (_qt_use_trampoline) {
        intern->native_ptr = qt_new_default_native<{!! $ctx->nativeInstantiationType !!}>();
        if (intern->native_ptr == NULL) {
            zend_throw_error(NULL, "{!! $ctx->phpClassName !!} cannot be instantiated directly.");
            RETURN_THROWS();
        }
        auto *_qt_trampoline = static_cast<{!! $ctx->trampolineTypeName !!} *>(intern->native_ptr);
        _qt_trampoline->php_object = &intern->std;
        _qt_trampoline->qt_cache_virtual_overrides(_qt_actual_ce, {!! $ctx->ceVarName !!});
        intern->native_is_generated_subclass = true;
        intern->native_is_virtual_trampoline = true;
    } else {
@if($ctx->isAbstract)
        zend_throw_error(NULL, "Abstract class {!! $ctx->phpClassName !!} cannot be instantiated directly.");
        RETURN_THROWS();
@else
        intern->native_ptr = qt_new_default_native<{!! $ctx->plainNativeInstantiationType !!}>();
        if (intern->native_ptr == NULL) {
            zend_throw_error(NULL, "{!! $ctx->phpClassName !!} cannot be instantiated directly.");
            RETURN_THROWS();
        }
        intern->native_is_generated_subclass = {!! $ctx->plainInstantiationUsesGeneratedType() ? 'true' : 'false' !!};
        intern->native_is_virtual_trampoline = false;
@endif
    }
@else
    intern->native_ptr = qt_new_default_native<{!! $ctx->nativeInstantiationType !!}>();
    if (intern->native_ptr == NULL) {
        zend_throw_error(NULL, "{!! $ctx->phpClassName !!} cannot be instantiated directly.");
        RETURN_THROWS();
    }
@if($ctx->tracksGeneratedNativeSubclass)
    intern->native_is_generated_subclass = true;
@endif
@endif
@if($ctx->hasPreventDestroy)
    qt_track_native_instance(intern->native_ptr);
@if($ctx->isQObjectDerived)
    qt_runtime_try_hook_about_to_quit();
@endif
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
        auto *_qt_trampoline = static_cast<{!! $ctx->trampolineTypeName !!} *>(intern->native_ptr);
        _qt_trampoline->php_object = &intern->std;
        _qt_trampoline->qt_cache_virtual_overrides(_qt_actual_ce, {!! $ctx->ceVarName !!});
        intern->native_is_generated_subclass = true;
        intern->native_is_virtual_trampoline = true;
    } else {
@if($ctx->isAbstract)
        zend_throw_error(NULL, "Abstract class {!! $ctx->phpClassName !!} cannot be instantiated directly.");
        RETURN_THROWS();
@else
        intern->native_ptr = new {!! $ctx->plainNativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
        intern->native_is_generated_subclass = {!! $ctx->plainInstantiationUsesGeneratedType() ? 'true' : 'false' !!};
        intern->native_is_virtual_trampoline = false;
@endif
    }
@else
    intern->native_ptr = new {!! $ctx->nativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
@if($ctx->tracksGeneratedNativeSubclass)
    intern->native_is_generated_subclass = true;
@endif
@endif
@if($ctx->hasPreventDestroy)
    qt_track_native_instance(intern->native_ptr);
@if($ctx->isQObjectDerived)
    qt_runtime_try_hook_about_to_quit();
@endif
@foreach($method->params as $param)
@if($param->isObject && !$param->isUnion)

    if ({!! $param->cVarName !!} != NULL) {
        intern->prevent_destroy = true;
    }
@endif
@endforeach
@endif
@endif
@endif
}
