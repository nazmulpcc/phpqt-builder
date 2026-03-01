@php
/**
 * Scalar return — use RETURN_LONG / RETURN_DOUBLE / RETURN_BOOL.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
$overload = $method->overloads[0] ?? null;
@endphp
@if($method->hasNoParams())
    {!! $method->returnMacro !!}(intern->native_ptr->{!! $method->cppName !!}());
@else
    {!! $method->returnMacro !!}(intern->native_ptr->{!! $method->cppName !!}(@foreach($method->params as $i => $param)@php
    $cppType = $overload && isset($overload->params[$i]) ? $overload->params[$i]->cppType : '';
    $expr = $ctx->typeBridge->phpToNativeExpr($param->phpType, $cppType, $param->cVarName);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach));
@endif
