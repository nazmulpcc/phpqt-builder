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
$callExpr = $method->hasNoParams()
    ? "{$callPrefix}{$method->cppName}()"
    : "{$callPrefix}{$method->cppName}(";
@endphp
@if($method->hasNoParams())
@php
    $cppReturnType = $overload?->cppReturnType ?? $method->returnType;
    $returnExpr = $ctx->typeBridge->nativeScalarToPhpExpr($method->returnType, $cppReturnType, $callExpr);
@endphp
    {!! $method->returnMacro !!}({!! $returnExpr !!});
@else
@php ob_start(); @endphp
{!! $callExpr !!}@foreach($method->params as $i => $param)@php
    $cppType = $overload && isset($overload->params[$i]) ? $overload->params[$i]->cppType : '';
    $expr = $ctx->typeBridge->phpToNativeExpr($param->phpType, $cppType, $param->cVarName, false, $param->isOptional);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach)
@php
    $callExpr = trim(ob_get_clean() ?: '');
    $cppReturnType = $overload?->cppReturnType ?? $method->returnType;
    $returnExpr = $ctx->typeBridge->nativeScalarToPhpExpr($method->returnType, $cppReturnType, $callExpr);
@endphp
    {!! $method->returnMacro !!}({!! $returnExpr !!});
@endif
