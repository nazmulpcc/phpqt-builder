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
    bool _qt_overload_ambiguous = false;
@foreach($method->overloads as $index => $ol)
    if ({!! $method->overloadMatchCondition($ol) !!}) {
        if (_qt_overload_index != -1) {
            _qt_overload_ambiguous = true;
        } else {
            _qt_overload_index = {!! $index !!};
        }
    }
@endforeach

    if (_qt_overload_ambiguous) {
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
            intern->native_ptr = new {!! $ctx->nativeCppType !!}({!! implode(', ', $callPlan['args']) !!});
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
