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
@if($ctx->nativeCppType === 'QThread' && $method->name === 'start')
    qt_runtime_owner_safe_point();

    zval *_qt_arg0 = NULL;
    zval *_qt_arg1 = NULL;
    zval *_qt_arg2 = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 3)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(_qt_arg0)
        Z_PARAM_ZVAL(_qt_arg1)
        Z_PARAM_ZVAL(_qt_arg2)
    ZEND_PARSE_PARAMETERS_END();

    {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);
    if (intern->native_ptr == NULL) {
        zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!} native instance is not initialized");
        RETURN_THROWS();
    }

    const zend_long _qt_argc = ZEND_NUM_ARGS();
    if (_qt_argc == 0) {
        intern->native_ptr->start();
        return;
    }

    if (_qt_argc == 1 && _qt_arg0 != NULL && Z_TYPE_P(_qt_arg0) == IS_LONG) {
        intern->native_ptr->start((QThread::Priority) zval_get_long(_qt_arg0));
        return;
    }

    if (_qt_arg0 == NULL || Z_TYPE_P(_qt_arg0) != IS_STRING) {
        zend_throw_error(NULL, "No matching overload for QThread::start().");
        RETURN_THROWS();
    }

#if !defined(ZTS)
    zend_throw_exception_ex(
        zend_ce_runtime_exception,
        0,
        "Qt\\Core\\QThread::start() task mode requires a ZTS PHP build (thread start/management APIs are unavailable on NTS)."
    );
    RETURN_THROWS();
#else
    if (!intern->native_is_generated_subclass || intern->native_is_virtual_trampoline) {
        zend_throw_error(NULL, "QThread task mode requires a non-overridden generated QThread instance.");
        RETURN_THROWS();
    }

    if (intern->extra_storage == NULL) {
        zend_throw_error(NULL, "QThread task runtime host is not initialized.");
        RETURN_THROWS();
    }

    if (_qt_arg1 != NULL && Z_TYPE_P(_qt_arg1) != IS_ARRAY) {
        zend_argument_type_error(2, "must be of type array when provided");
        RETURN_THROWS();
    }

    bool _qt_has_priority = false;
    int _qt_priority = 0;
    if (_qt_arg2 != NULL && Z_TYPE_P(_qt_arg2) != IS_NULL) {
        if (Z_TYPE_P(_qt_arg2) != IS_LONG) {
            zend_argument_type_error(3, "must be of type ?int when provided");
            RETURN_THROWS();
        }
        _qt_has_priority = true;
        _qt_priority = (int) zval_get_long(_qt_arg2);
    }

    std::string _qt_callable_error;
    if (!qt_qthreadruntime_validate_callable_string(Z_STR_P(_qt_arg0), &_qt_callable_error)) {
        zend_argument_value_error(1, "%s", _qt_callable_error.c_str());
        RETURN_THROWS();
    }

    zval _qt_args_input;
    if (_qt_arg1 != NULL) {
        ZVAL_COPY(&_qt_args_input, _qt_arg1);
    } else {
        array_init(&_qt_args_input);
    }

    std::string _qt_args_payload;
    std::string _qt_args_error;
    bool _qt_serialized = qt_qthreadruntime_serialize_worker_args(&_qt_args_input, &_qt_args_payload, &_qt_args_error);
    zval_ptr_dtor(&_qt_args_input);
    if (!_qt_serialized) {
        zend_argument_value_error(2, "%s", _qt_args_error.c_str());
        RETURN_THROWS();
    }

    std::string _qt_start_error;
    if (!qt_qthread_task_host_start(
        static_cast<qt_qthread_task_host *>(intern->extra_storage),
        intern->native_ptr,
        std::string(Z_STRVAL_P(_qt_arg0), Z_STRLEN_P(_qt_arg0)),
        _qt_args_payload,
        _qt_has_priority,
        _qt_priority,
        &_qt_start_error
    )) {
        zend_throw_error(NULL, "%s", _qt_start_error.c_str());
        RETURN_THROWS();
    }
#endif

    return;
@else
    qt_runtime_owner_safe_point();

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
@endif
}
