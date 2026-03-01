@php
/**
 * Void return — just call the method.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
$overload = $method->overloads[0] ?? null;
$callPrefix = $method->isStatic
    ? "{$ctx->nativeCppType}::"
    : "intern->native_ptr->";
@endphp
@if($method->hasNoParams())
    {!! $callPrefix !!}{!! $method->cppName !!}();
@else
    {!! $callPrefix !!}{!! $method->cppName !!}(@foreach($method->params as $i => $param)@php
    $cppType = $overload && isset($overload->params[$i]) ? $overload->params[$i]->cppType : '';
    $expr = $ctx->typeBridge->phpToNativeExpr($param->phpType, $cppType, $param->cVarName, false, $param->isOptional);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach);
@endif
