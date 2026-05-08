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
    qt_runtime_owner_safe_point();

@if($ctx->nativeCppType === 'QThread')
    {
        zval *parent_zv = NULL;
        zend_string *bootstrap_script = NULL;

        ZEND_PARSE_PARAMETERS_START(0, 2)
            Z_PARAM_OPTIONAL
            Z_PARAM_OBJECT_OF_CLASS_OR_NULL(parent_zv, qt_ce_qobject)
            Z_PARAM_STR_OR_NULL(bootstrap_script)
        ZEND_PARSE_PARAMETERS_END();

        {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);
        if (intern->native_ptr != NULL) {
            zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!}::__construct() called twice");
            RETURN_THROWS();
        }

        QObject *_qt_parent = NULL;
        if (parent_zv != NULL && Z_TYPE_P(parent_zv) == IS_OBJECT) {
            qt_qobject_object *_qt_parent_intern = qt_qobject_from_obj(Z_OBJ_P(parent_zv));
            if (_qt_parent_intern->native_ptr == NULL) {
                zend_throw_error(NULL, "QThread parent native instance is not initialized");
                RETURN_THROWS();
            }
            _qt_parent = _qt_parent_intern->native_ptr;
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
        if (_qt_use_trampoline) {
            intern->native_ptr = new {!! $ctx->nativeInstantiationType !!}(_qt_parent);
            auto *_qt_trampoline = static_cast<{!! $ctx->trampolineTypeName !!} *>(intern->native_ptr);
            _qt_trampoline->php_object = &intern->std;
            _qt_trampoline->qt_cache_virtual_overrides(_qt_actual_ce, {!! $ctx->ceVarName !!});
            intern->native_is_generated_subclass = true;
            intern->native_is_virtual_trampoline = true;
            intern->native_rebind_php_object = {!! $ctx->filePrefix !!}_rebind_php_object;
        } else {
@if($ctx->isAbstract)
            zend_throw_error(NULL, "Abstract class {!! addslashes($ctx->phpClassName) !!} cannot be instantiated directly.");
            RETURN_THROWS();
@else
            intern->native_ptr = new {!! $ctx->plainNativeInstantiationType !!}(_qt_parent);
            intern->native_is_generated_subclass = {!! $ctx->plainInstantiationUsesGeneratedType() ? 'true' : 'false' !!};
            intern->native_is_virtual_trampoline = false;
            intern->native_rebind_php_object = NULL;
@endif
        }
@else
        intern->native_ptr = new {!! $ctx->nativeInstantiationType !!}(_qt_parent);
@if($ctx->tracksGeneratedNativeSubclass)
        intern->native_is_generated_subclass = true;
@endif
@endif

        if (intern->extra_storage != NULL && intern->native_ptr != NULL && intern->native_is_generated_subclass) {
            static_cast<{!! $ctx->accessShimTypeName !!} *>(intern->native_ptr)->qt_set_task_runtime_host(intern->extra_storage);
        }

@if($ctx->hasPreventDestroy)
        qt_track_native_instance(intern->native_ptr);
@if($ctx->isQObjectDerived)
        qt_runtime_try_hook_about_to_quit();
@endif
        if (_qt_parent != NULL) {
            intern->prevent_destroy = true;
        }
@endif

        if (bootstrap_script != NULL && intern->extra_storage != NULL) {
            std::string _qt_bootstrap_error;
            if (!qt_qthread_task_host_set_bootstrap_script(
                static_cast<qt_qthread_task_host *>(intern->extra_storage),
                std::string(ZSTR_VAL(bootstrap_script), ZSTR_LEN(bootstrap_script)),
                &_qt_bootstrap_error
            )) {
                zend_throw_error(NULL, "%s", _qt_bootstrap_error.c_str());
                RETURN_THROWS();
            }
        }

@if($ctx->isQObjectDerived)
        if (intern->native_ptr != NULL) {
            qt_php_signal_register_live_wrapper(static_cast<QObject *>(intern->native_ptr), &intern->std);
        }
@endif

        return;
    }
@endif

@if($ctx->nativeCppType !== 'QThread')
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
        zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!}::__construct() called twice");
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
    zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!} cannot be instantiated directly.");
    RETURN_THROWS();
@else

@if($method->hasNoParams())
@if($ctx->requiresVirtualTrampoline)
    if (_qt_use_trampoline) {
        intern->native_ptr = qt_new_default_native<{!! $ctx->nativeInstantiationType !!}>();
        if (intern->native_ptr == NULL) {
            zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!} cannot be instantiated directly.");
            RETURN_THROWS();
        }
        auto *_qt_trampoline = static_cast<{!! $ctx->trampolineTypeName !!} *>(intern->native_ptr);
        _qt_trampoline->php_object = &intern->std;
        _qt_trampoline->qt_cache_virtual_overrides(_qt_actual_ce, {!! $ctx->ceVarName !!});
        intern->native_is_generated_subclass = true;
        intern->native_is_virtual_trampoline = true;
        intern->native_rebind_php_object = {!! $ctx->filePrefix !!}_rebind_php_object;
    } else {
@if($ctx->isAbstract)
        zend_throw_error(NULL, "Abstract class {!! addslashes($ctx->phpClassName) !!} cannot be instantiated directly.");
        RETURN_THROWS();
@else
        intern->native_ptr = qt_new_default_native<{!! $ctx->plainNativeInstantiationType !!}>();
        if (intern->native_ptr == NULL) {
            zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!} cannot be instantiated directly.");
            RETURN_THROWS();
        }
        intern->native_is_generated_subclass = {!! $ctx->plainInstantiationUsesGeneratedType() ? 'true' : 'false' !!};
        intern->native_is_virtual_trampoline = false;
        intern->native_rebind_php_object = NULL;
@endif
    }
@else
    intern->native_ptr = qt_new_default_native<{!! $ctx->nativeInstantiationType !!}>();
    if (intern->native_ptr == NULL) {
        zend_throw_error(NULL, "{!! addslashes($ctx->phpClassName) !!} cannot be instantiated directly.");
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
        intern->native_rebind_php_object = {!! $ctx->filePrefix !!}_rebind_php_object;
    } else {
@if($ctx->nativeCppType === 'QObject')
        zend_class_entry *_qt_php_ce = Z_OBJCE_P(ZEND_THIS);
        if (_qt_php_ce != qt_ce_qobject && qt_php_metaobject_class_needs_bridging(_qt_php_ce)) {
            intern->native_ptr = new QtPhpMetaObjectBridge({!! implode(', ', $callPlan['args']) !!}, _qt_php_ce, &intern->std);
        } else {
@if($ctx->isAbstract)
            zend_throw_error(NULL, "Abstract class {!! addslashes($ctx->phpClassName) !!} cannot be instantiated directly.");
            RETURN_THROWS();
@else
            intern->native_ptr = new {!! $ctx->plainNativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
            intern->native_is_generated_subclass = {!! $ctx->plainInstantiationUsesGeneratedType() ? 'true' : 'false' !!};
            intern->native_is_virtual_trampoline = false;
            intern->native_rebind_php_object = NULL;
@endif
        }
@else
@if($ctx->isAbstract)
        zend_throw_error(NULL, "Abstract class {!! addslashes($ctx->phpClassName) !!} cannot be instantiated directly.");
        RETURN_THROWS();
@else
        intern->native_ptr = new {!! $ctx->plainNativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
        intern->native_is_generated_subclass = {!! $ctx->plainInstantiationUsesGeneratedType() ? 'true' : 'false' !!};
        intern->native_is_virtual_trampoline = false;
        intern->native_rebind_php_object = NULL;
@endif
@endif
    }
@else
@if($ctx->nativeCppType === 'QObject')
    zend_class_entry *_qt_php_ce = Z_OBJCE_P(ZEND_THIS);
    if (_qt_php_ce != qt_ce_qobject && qt_php_metaobject_class_needs_bridging(_qt_php_ce)) {
        intern->native_ptr = new QtPhpMetaObjectBridge({!! implode(', ', $callPlan['args']) !!}, _qt_php_ce, &intern->std);
    } else {
        intern->native_ptr = new QObject({!! implode(', ', $callPlan['args']) !!});
    }
@else
    intern->native_ptr = new {!! $ctx->nativeInstantiationType !!}({!! implode(', ', $callPlan['args']) !!});
@endif
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
@endif

@if($ctx->isQObjectDerived && $ctx->nativeCppType !== 'QThread')
    if (intern->native_ptr != NULL) {
        qt_php_signal_register_live_wrapper(static_cast<QObject *>(intern->native_ptr), &intern->std);
    }
@endif
}
