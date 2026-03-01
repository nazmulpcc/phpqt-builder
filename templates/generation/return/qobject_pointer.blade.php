@php
/**
 * QObject pointer return — wrap existing C++ pointer in PHP object.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
$overload = $method->overloads[0] ?? null;
$returnClass = $method->returnType;
$wrapFunc = $ctx->typeBridge->wrapNativeFuncName($returnClass);
$returnCe = $ctx->typeBridge->ceVarName($returnClass);
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
        $args[] = $ctx->typeBridge->phpToNativeExpr($param->phpType, $cppType, $param->cVarName, false, $param->isOptional);
    }
    $callExpr = "{$callPrefix}{$method->cppName}(" . implode(', ', $args) . ')';
@endphp
@endif
    {!! $returnClass !!} *_result = {!! $callExpr !!};
    {!! $wrapFunc !!}(return_value, _result, {!! $returnCe !!}, true);
