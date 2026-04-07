@php
/**
 * Shared signal-binding body used by connect() and generated onFoo() sugar.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\SignalOverloadContext $signal
 */
$lambdaParams = [];
$dispatchCaptureArgs = ['_qt_callback'];
$snapshotSetupLines = [];
$setupLines = [];
$teardownLines = [];
$paramCount = count($signal->params);

foreach ($signal->params as $index => $param) {
    $nativeVar = sprintf('_qt_arg_%d', $index);
    $lambdaParams[] = $param->cppType . ' ' . $nativeVar;
    $mode = $ctx->typeBridge->signalArgMarshallingMode($param->phpType, $param->cppType);
    if ($mode === 'borrowed_qobject_snapshot') {
        $snapshotVar = sprintf('_qt_snapshot_%d', $index);
        $nativeClass = $ctx->typeBridge->nativeClassNameForPhpAndCppType($param->phpType, $param->cppType);
        $snapshotSetupLines[] = sprintf(
            'QPointer<QObject> %s(static_cast<QObject *>(%s));',
            $snapshotVar,
            $ctx->typeBridge->writableObjectPointerExpr($param->cppType, $param->phpType, '&' . $nativeVar),
        );
        $dispatchCaptureArgs[] = $snapshotVar;
        $setupLines[] = sprintf(
            "if (%s.isNull()) {\n                    ZVAL_NULL(&_qt_params[%d]);\n                } else {\n                    %s(&_qt_params[%d], %s, %s, true);\n                }",
            $snapshotVar,
            $index,
            $ctx->typeBridge->wrapNativeFuncName($param->phpType),
            $index,
            $ctx->typeBridge->writableObjectPointerExpr(
                $param->cppType,
                $param->phpType,
                sprintf('static_cast<%s *>(%s.data())', $nativeClass, $snapshotVar),
            ),
            $ctx->typeBridge->ceVarName($param->phpType),
        );
    } else {
        $dispatchCaptureArgs[] = $nativeVar;
        $setupLines[] = $ctx->typeBridge->signalArgToZvalBlock(
            sprintf('&_qt_params[%d]', $index),
            $param->phpType,
            $param->cppType,
            $nativeVar,
            $index,
        );
    }
    $teardownLines[] = sprintf('zval_ptr_dtor(&_qt_params[%d]);', $index);
}
@endphp
    auto _qt_callback = qt_signal_callback_create(
        static_cast<QObject *>(intern->native_ptr),
        callback,
        {!! $signal->name === 'destroyed' ? 'false' : 'true' !!}
    );
    if (_qt_callback == nullptr) {
        RETURN_THROWS();
    }

    QMetaObject::Connection _qt_connection = QObject::connect(
        intern->native_ptr,
        {!! $signal->memberPointerExpr !!},
        [_qt_callback]({!! implode(', ', $lambdaParams) !!}) {
@foreach($snapshotSetupLines as $line)
            {!! $line !!}
@endforeach
            qt_signal_dispatch(_qt_callback, [{!! implode(', ', $dispatchCaptureArgs) !!}]() mutable {
@if($paramCount > 0)
                zval _qt_params[{!! $paramCount !!}];
@foreach($setupLines as $line)
                {!! $line !!}
@endforeach
                qt_signal_callback_invoke(_qt_callback, {!! $paramCount !!}, _qt_params);
@foreach($teardownLines as $line)
                {!! $line !!}
@endforeach
@else
                qt_signal_callback_invoke(_qt_callback, 0, NULL);
@endif
            });
        }
    );

    qt_qmetaobjectconnection_wrap(return_value, _qt_connection);
    return;
