@php
/**
 * Constructor dispatch for overloaded constructors.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
@endphp
    zend_long _argc = ZEND_NUM_ARGS();
@if($ctx->needsArgvStorage)
    if (intern->extra_storage == NULL) {
        intern->extra_storage = new {!! $ctx->argvStorageStructName !!}();
    }
    auto *_qt_argv_storage = static_cast<{!! $ctx->argvStorageStructName !!} *>(intern->extra_storage);
@endif

    int _qt_overload_index = -1;
    int _qt_overload_best_score = -1;
    bool _qt_overload_ambiguous = false;
@foreach($method->overloads as $index => $ol)
@foreach($method->overloadScoreSetupLines($ol, $index) as $line)
{!! $line !!}
@endforeach
    if (_qt_score_{!! $index !!} >= 0) {
        if (_qt_score_{!! $index !!} > _qt_overload_best_score) {
            _qt_overload_best_score = _qt_score_{!! $index !!};
            _qt_overload_index = {!! $index !!};
            _qt_overload_ambiguous = false;
        } else if (_qt_score_{!! $index !!} == _qt_overload_best_score) {
            _qt_overload_ambiguous = true;
        }
    }
@endforeach

    if (_qt_overload_ambiguous && _qt_overload_best_score >= 0) {
        zend_throw_error(NULL, "{!! addslashes($method->ambiguousOverloadMessage()) !!}");
        RETURN_THROWS();
    }

    switch (_qt_overload_index) {
@foreach($method->overloads as $index => $ol)
        case {!! $index !!}: {
@php $callPlan = $method->callPlan($ctx, $ol, $ctx->needsArgvStorage ? '_qt_argv_storage' : null); @endphp
@foreach($callPlan['setup_lines'] as $line)
            {!! $line !!}
@endforeach
@if($ctx->requiresVirtualTrampoline)
            if (_qt_use_trampoline) {
@if(count($callPlan['args']) === 0)
                intern->native_ptr = qt_new_default_native<{!! $ctx->nativeInstantiationType !!}>();
@else
                intern->native_ptr = new {!! $ctx->nativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
@endif
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
@if(count($callPlan['args']) === 0)
                intern->native_ptr = qt_new_default_native<{!! $ctx->plainNativeInstantiationType !!}>();
@else
                intern->native_ptr = new {!! $ctx->plainNativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
@endif
                if (intern->native_ptr == NULL) {
                    zend_throw_error(NULL, "{!! $ctx->phpClassName !!} cannot be instantiated directly.");
                    RETURN_THROWS();
                }
                intern->native_is_generated_subclass = {!! $ctx->plainInstantiationUsesGeneratedType() ? 'true' : 'false' !!};
                intern->native_is_virtual_trampoline = false;
@endif
            }
@else
@if(count($callPlan['args']) === 0)
            intern->native_ptr = qt_new_default_native<{!! $ctx->nativeInstantiationType !!}>();
@else
            intern->native_ptr = new {!! $ctx->nativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
@endif
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
@foreach($method->params as $param)
@if($param->isObject && !$param->isUnion)
            if ({!! $param->cVarName !!} != NULL) {
                intern->prevent_destroy = true;
            }
@endif
@endforeach
@endif
            break;
        }
@endforeach
        default:
            zend_throw_error(NULL, "{!! addslashes($method->noMatchingOverloadMessage()) !!}");
            RETURN_THROWS();
    }
