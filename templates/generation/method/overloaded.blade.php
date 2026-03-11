@php
/**
 * Overloaded method template — dispatches by argument count and type.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
@endphp
/* {!! $method->name !!} — {!! $method->overloadCount !!} C++ overloads */
ZEND_METHOD({!! $ctx->zendClassSymbol !!}, {!! $method->name !!})
{
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
@if($param->isUnion || $param->isObject)
        Z_PARAM_ZVAL({!! $param->cVarName !!})
@else
        {!! $param->zppMacro !!}
@endif
@endforeach
    ZEND_PARSE_PARAMETERS_END();

@if(!$method->isStatic)
    {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);

@if(!$method->isConstructor)
    if (intern->native_ptr == NULL) {
        zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!} native instance is not initialized");
        RETURN_THROWS();
    }

@endif
@endif
    zend_long _argc = ZEND_NUM_ARGS();

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
@include('generation.method.overload_call', ['ctx' => $ctx, 'method' => $method, 'overload' => $ol, 'indent' => '            ', 'index' => $index])
            break;
        }
@endforeach
        default:
            zend_throw_error(NULL, "{!! addslashes($method->noMatchingOverloadMessage()) !!}");
            RETURN_THROWS();
    }
}
