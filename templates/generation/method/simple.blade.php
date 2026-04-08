@php
/**
 * Simple (non-overloaded) method template.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
@endphp
/* {!! $method->name !!} */
ZEND_METHOD({!! $ctx->zendClassSymbol !!}, {!! $method->name !!})
{
@if($ctx->nativeCppType === 'QObject' && $method->name === 'moveToThread')
    qt_runtime_owner_safe_point();

    zval *thread_zv = NULL;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(thread_zv, qt_ce_qthread)
    ZEND_PARSE_PARAMETERS_END();

    {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);
    if (!{!! $ctx->filePrefix !!}_guard_moved_source_method(intern, "moveToThread", false)) {
        RETURN_THROWS();
    }
    if (intern->native_ptr == NULL) {
        zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!} native instance is not initialized");
        RETURN_THROWS();
    }

    qt_qthread_object *target_intern = qt_qthread_from_obj(Z_OBJ_P(thread_zv));
    if (target_intern->native_ptr == NULL) {
        zend_throw_error(NULL, "QThread native instance is not initialized");
        RETURN_THROWS();
    }

    if (target_intern->extra_storage == NULL) {
        zend_throw_error(NULL, "QThread move host is not initialized.");
        RETURN_THROWS();
    }

    QObject *_qt_object = static_cast<QObject *>(intern->native_ptr);
    QThread *_qt_source_thread = _qt_object->thread();
    QThread *_qt_target_thread = target_intern->native_ptr;
    if (_qt_target_thread == NULL) {
        RETURN_FALSE;
    }

    if (!_qt_object->moveToThread(_qt_target_thread)) {
        RETURN_FALSE;
    }

    std::string _qt_move_error;
    uint64_t _qt_moved_token = 0;
    if (!qt_qthread_task_host_register_moved_object(
        static_cast<qt_qthread_task_host *>(target_intern->extra_storage),
        _qt_target_thread,
        _qt_object,
        &intern->std,
        intern->native_is_generated_subclass,
        intern->native_is_virtual_trampoline,
        true,
        intern->native_rebind_php_object,
        &_qt_moved_token,
        &_qt_move_error
    )) {
        if (_qt_source_thread != NULL) {
            (void) _qt_object->moveToThread(_qt_source_thread);
        }
        zend_throw_error(NULL, "%s", _qt_move_error.c_str());
        RETURN_THROWS();
    }

    intern->prevent_destroy = true;
    qt_php_signal_unregister_live_wrapper(_qt_object, &intern->std);
    qt_php_signal_on_object_moved(_qt_object, _qt_target_thread);
    intern->moved_source = true;
    intern->moved_token = _qt_moved_token;
    if (intern->native_is_virtual_trampoline && intern->native_rebind_php_object != NULL) {
        intern->native_rebind_php_object(intern->native_ptr, NULL, NULL);
    }

    RETURN_TRUE;
@elseif($ctx->nativeCppType === 'QThread' && $method->name === 'start')
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
        qt_runtime_exception_ce(),
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
@elseif($ctx->nativeCppType === 'QObject' && $method->name === 'sender')
    ZEND_PARSE_PARAMETERS_NONE();

    if (qt_qobject_current_sender_override().active) {
        zend_object *_qt_live_sender = qt_php_signal_resolve_current_thread_live_wrapper(qt_qobject_current_sender_override().sender);
        if (_qt_live_sender == NULL) {
            _qt_live_sender = qt_qthreadruntime_resolve_current_thread_live_php_object(qt_qobject_current_sender_override().sender);
        }
        if (_qt_live_sender != NULL) {
            ZVAL_OBJ_COPY(return_value, _qt_live_sender);
            return;
        }
        if (!qt_qobject_wrap_runtime_instance(return_value, qt_qobject_current_sender_override().sender, true)) {
            RETURN_NULL();
        }
        return;
    }

    qt_runtime_owner_safe_point();

    {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);
    if (!{!! $ctx->filePrefix !!}_guard_moved_source_method(intern, "sender", false)) {
        RETURN_THROWS();
    }
    if (intern->native_ptr == NULL) {
        zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!} native instance is not initialized");
        RETURN_THROWS();
    }
    if (!intern->native_is_generated_subclass) {
        zend_throw_error(NULL, "Protected method {!! addslashes($ctx->phpClassName) !!}::sender() requires a PHP-created native instance.");
        RETURN_THROWS();
    }

    auto _result = static_cast<{!! $ctx->protectedCallReceiverType !!} *>(intern->native_ptr)->{!! $method->accessShimHelperName(0) !!}();
    if (!qt_qobject_wrap_runtime_instance(return_value, static_cast<QObject *>(_result), true)) {
        RETURN_NULL();
    }
    return;
@elseif($ctx->nativeCppType === 'QObject' && $method->name === 'senderSignalIndex')
    ZEND_PARSE_PARAMETERS_NONE();

    if (qt_qobject_current_sender_override().active) {
        RETURN_LONG((zend_long) qt_qobject_current_sender_override().signal_index);
    }

    qt_runtime_owner_safe_point();

    {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);
    if (!{!! $ctx->filePrefix !!}_guard_moved_source_method(intern, "senderSignalIndex", false)) {
        RETURN_THROWS();
    }
    if (intern->native_ptr == NULL) {
        zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!} native instance is not initialized");
        RETURN_THROWS();
    }
    if (!intern->native_is_generated_subclass) {
        zend_throw_error(NULL, "Protected method {!! addslashes($ctx->phpClassName) !!}::senderSignalIndex() requires a PHP-created native instance.");
        RETURN_THROWS();
    }

    auto _result = static_cast<{!! $ctx->protectedCallReceiverType !!} *>(intern->native_ptr)->{!! $method->accessShimHelperName(0) !!}();
    RETURN_LONG((zend_long) _result);
@else
    qt_runtime_owner_safe_point();

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
@if(!$method->isStatic)
    {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);

@if(!$method->isConstructor)
@if($ctx->isQObjectDerived)
@php
    $qtMovedSourceAllowedMethods = ['thread', 'objectName', 'signalsBlocked', 'dynamicPropertyNames', 'inherits'];
@endphp
    if (!{!! $ctx->filePrefix !!}_guard_moved_source_method(
        intern,
        "{!! $method->name !!}",
        {!! in_array($method->name, $qtMovedSourceAllowedMethods, true) ? 'true' : 'false' !!}
    )) {
        RETURN_THROWS();
    }
@endif
    if (intern->native_ptr == NULL) {
        zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!} native instance is not initialized");
        RETURN_THROWS();
    }

@endif
@endif
@if($method->returnStrategy === 'void')
@include('generation.return.void', ['ctx' => $ctx, 'method' => $method])
@elseif($method->returnStrategy === 'scalar')
@include('generation.return.scalar', ['ctx' => $ctx, 'method' => $method])
@elseif($method->returnStrategy === 'string')
@include('generation.return.string', ['ctx' => $ctx, 'method' => $method])
@elseif($method->returnStrategy === 'value_object')
@include('generation.return.value_object', ['ctx' => $ctx, 'method' => $method])
@elseif($method->returnStrategy === 'qobject_pointer' || $method->returnStrategy === 'smart_pointer_alias')
@include('generation.return.qobject_pointer', ['ctx' => $ctx, 'method' => $method])
@elseif($method->returnStrategy === 'array')
@include('generation.return.array', ['ctx' => $ctx, 'method' => $method])
@else
    /* TODO: unsupported return strategy '{!! $method->returnStrategy !!}' */
@endif
@endif
}
