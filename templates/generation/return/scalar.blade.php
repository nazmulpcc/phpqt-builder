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
$callPlan = $method->callPlan($ctx, $overload);
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
@foreach($callPlan['setup_lines'] as $line)
    {!! $line !!}
@endforeach
@php
    $callExpr = "{$callPrefix}{$method->cppName}(" . implode(', ', $callPlan['args']) . ')';
    $cppReturnType = $overload?->cppReturnType ?? $method->returnType;
    $returnExpr = $ctx->typeBridge->nativeScalarToPhpExpr($method->returnType, $cppReturnType, $callExpr);
@endphp
    {!! $method->returnMacro !!}({!! $returnExpr !!});
@endif
