@php
/**
 * Scalar return — use RETURN_LONG / RETURN_DOUBLE / RETURN_BOOL.
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
    {!! $method->returnMacro !!}({!! $callPrefix !!}{!! $method->cppName !!}());
@else
    {!! $method->returnMacro !!}({!! $callPrefix !!}{!! $method->cppName !!}(@foreach($method->params as $i => $param)@php
    $cppType = $overload && isset($overload->params[$i]) ? $overload->params[$i]->cppType : '';
    $expr = $ctx->typeBridge->phpToNativeExpr($param->phpType, $cppType, $param->cVarName, false, $param->isOptional);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach));
@endif
