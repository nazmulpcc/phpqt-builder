@php
/**
 * Shared signal-binding body used by connectSignal() and generated onFoo() sugar.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\SignalOverloadContext $signal
 */
$lambdaParams = [];
$captureArgs = ['_qt_callback'];
$setupLines = [];
$teardownLines = [];
$paramCount = count($signal->params);

foreach ($signal->params as $index => $param) {
    $nativeVar = sprintf('_qt_arg_%d', $index);
    $lambdaParams[] = $param->cppType . ' ' . $nativeVar;
    $captureArgs[] = $nativeVar;
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

    QObject::connect(
        intern->native_ptr,
        {!! $signal->memberPointerExpr !!},
        [_qt_callback]({!! implode(', ', $lambdaParams) !!}) {
            qt_signal_dispatch([{!! implode(', ', $captureArgs) !!}]() mutable {
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

    RETURN_NULL();
