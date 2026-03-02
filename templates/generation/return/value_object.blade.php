@php
/**
 * Value object return — copy-construct into a new PHP object.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
$overload = $method->overloads[0] ?? null;
$returnClass = $method->returnType;
$returnCe = $ctx->typeBridge->ceVarName($returnClass);
$returnFromObj = $ctx->typeBridge->fromObjFuncName($returnClass);
$returnStruct = $ctx->typeBridge->objectStructName($returnClass);
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
    {!! $returnClass !!} _result = {!! $callExpr !!};
    object_init_ex(return_value, {!! $returnCe !!});
    {!! $returnStruct !!} *_ret_intern = {!! $returnFromObj !!}(Z_OBJ_P(return_value));
    _ret_intern->native_ptr = new {!! $returnClass !!}(_result);
