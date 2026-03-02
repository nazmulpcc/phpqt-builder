@php
/**
 * String return — convert QString/QByteArray to PHP string.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
$overload = $method->overloads[0] ?? null;
$cppReturnType = $overload ? $overload->cppReturnType : 'QString';
$callPrefix = $method->isStatic
    ? "{$ctx->nativeCppType}::"
    : "intern->native_ptr->";
$callPlan = $method->callPlan($ctx, $overload);
@endphp
@if($method->hasNoParams())
@php $callExpr = "{$callPrefix}{$method->cppName}()"; @endphp
@else
@foreach($callPlan['setup_lines'] as $line)
    {!! $line !!}
@endforeach
@php $callExpr = "{$callPrefix}{$method->cppName}(" . implode(', ', $callPlan['args']) . ')'; @endphp
@endif
    auto _result = {!! $callExpr !!};
    {!! $ctx->typeBridge->nativeStringToPhpReturn($cppReturnType, '_result') !!};
