@php
/**
 * Shared signal-binding body used by connect() and generated onFoo() sugar.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\SignalOverloadContext $signal
 */
$lambdaParams = [];
$dispatchCaptureArgs = ['_qt_callback'];
$setupLines = [];
$teardownLines = [];
$paramCount = count($signal->params);
$hasBorrowedSignalArg = false;

foreach ($signal->params as $index => $param) {
    $nativeVar = sprintf('_qt_arg_%d', $index);
    $lambdaParams[] = $param->cppType . ' ' . $nativeVar;
    $borrowed = $ctx->typeBridge->signalArgUsesBorrowedWrap($param->phpType, $param->cppType);
    if ($borrowed) {
        $hasBorrowedSignalArg = true;
        $dispatchCaptureArgs[] = '&' . $nativeVar;
    } else {
        $dispatchCaptureArgs[] = $nativeVar;
    }
    $setupLines[] = $ctx->typeBridge->signalArgToZvalBlock(
        sprintf('&_qt_params[%d]', $index),
        $param->phpType,
        $param->cppType,
        $nativeVar,
        $index,
    );
    $teardownLines[] = sprintf('zval_ptr_dtor(&_qt_params[%d]);', $index);
}
@endphp
    auto _qt_callback = qt_signal_callback_create(static_cast<QObject *>(intern->native_ptr), callback);
    if (_qt_callback == nullptr) {
        RETURN_THROWS();
    }

    QMetaObject::Connection _qt_connection = QObject::connect(
        intern->native_ptr,
        {!! $signal->memberPointerExpr !!},
        [_qt_callback]({!! implode(', ', $lambdaParams) !!}) {
@if($hasBorrowedSignalArg)
            if (!qt_runtime_is_owner_thread()) {
#if defined(PHP_DEBUG)
                php_error_docref(NULL, E_NOTICE, "Skipping borrowed signal callback off owner thread.");
#endif
                return;
            }
@endif
            qt_signal_dispatch([{!! implode(', ', $dispatchCaptureArgs) !!}]() mutable {
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
