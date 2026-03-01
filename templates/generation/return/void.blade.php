@php
/**
 * Void return — just call the method.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
$overload = $method->overloads[0] ?? null;
@endphp
@if($method->hasNoParams())
    intern->native_ptr->{!! $method->cppName !!}();
@else
    intern->native_ptr->{!! $method->cppName !!}(@foreach($method->params as $i => $param)@php
    $cppType = $overload && isset($overload->params[$i]) ? $overload->params[$i]->cppType : '';
    $expr = $ctx->typeBridge->phpToNativeExpr($param->phpType, $cppType, $param->cVarName);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach);
@endif
