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
@endphp
@if($method->hasNoParams())
@php $callExpr = "{$callPrefix}{$method->cppName}()"; @endphp
@else
@php
    $args = [];
    foreach ($method->params as $i => $param) {
        $cppType = $overload && isset($overload->params[$i]) ? $overload->params[$i]->cppType : '';
        $args[] = $ctx->typeBridge->phpToNativeExpr($param->phpType, $cppType, $param->cVarName);
    }
    $callExpr = "{$callPrefix}{$method->cppName}(" . implode(', ', $args) . ')';
@endphp
@endif
@php
    $normalized = trim(str_replace(['const ', '&'], '', $cppReturnType));
@endphp
    {!! $normalized !!} _result = {!! $callExpr !!};
    {!! $ctx->typeBridge->nativeStringToPhpReturn($cppReturnType, '_result') !!};
