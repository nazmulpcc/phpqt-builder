@php
/**
 * Single overload call within dispatch logic.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 * @var \QtBuilder\CodeGen\OverloadContext $overload
 * @var string $indent
 */
$callPrefix = $method->isStatic
    ? "{$ctx->nativeCppType}::"
    : "intern->native_ptr->";
@endphp
@if($overload->returnStrategy === 'void')
{!! $indent !!}{!! $callPrefix !!}{!! $method->cppName !!}(@foreach($overload->params as $i => $op)@php
    $mergedParam = $method->params[$i] ?? null;
    $varName = $mergedParam ? $mergedParam->cVarName : $op->name;
    $expr = $ctx->typeBridge->phpToNativeExpr($op->phpType, $op->cppType, $varName);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach);
@elseif($overload->returnStrategy === 'scalar')
@php
    $macro = $ctx->typeBridge->returnMacro($method->returnType) ?? 'RETURN_LONG';
@endphp
{!! $indent !!}{!! $macro !!}({!! $callPrefix !!}{!! $method->cppName !!}(@foreach($overload->params as $i => $op)@php
    $mergedParam = $method->params[$i] ?? null;
    $varName = $mergedParam ? $mergedParam->cVarName : $op->name;
    $expr = $ctx->typeBridge->phpToNativeExpr($op->phpType, $op->cppType, $varName);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach));
@elseif($overload->returnStrategy === 'string')
@php
    $normalized = trim(str_replace(['const ', '&'], '', $overload->cppReturnType));
@endphp
{!! $indent !!}{!! $normalized !!} _result = {!! $callPrefix !!}{!! $method->cppName !!}(@foreach($overload->params as $i => $op)@php
    $mergedParam = $method->params[$i] ?? null;
    $varName = $mergedParam ? $mergedParam->cVarName : $op->name;
    $expr = $ctx->typeBridge->phpToNativeExpr($op->phpType, $op->cppType, $varName);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach);
{!! $indent !!}{!! $ctx->typeBridge->nativeStringToPhpReturn($overload->cppReturnType, '_result') !!};
@elseif($overload->returnStrategy === 'value_object')
@php
    $returnClass = trim(str_replace(['const ', '&', '*'], '', $overload->cppReturnType));
    $returnCe = $ctx->typeBridge->ceVarName($returnClass);
    $returnFromObj = $ctx->typeBridge->fromObjFuncName($returnClass);
    $returnStruct = $ctx->typeBridge->objectStructName($returnClass);
@endphp
{!! $indent !!}{!! $returnClass !!} _result = {!! $callPrefix !!}{!! $method->cppName !!}(@foreach($overload->params as $i => $op)@php
    $mergedParam = $method->params[$i] ?? null;
    $varName = $mergedParam ? $mergedParam->cVarName : $op->name;
    $expr = $ctx->typeBridge->phpToNativeExpr($op->phpType, $op->cppType, $varName);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach);
{!! $indent !!}object_init_ex(return_value, {!! $returnCe !!});
{!! $indent !!}{!! $returnStruct !!} *_ret_intern = {!! $returnFromObj !!}(Z_OBJ_P(return_value));
{!! $indent !!}_ret_intern->native_ptr = new {!! $returnClass !!}(_result);
@elseif($overload->returnStrategy === 'qobject_pointer')
@php
    $returnClass = trim(str_replace(['const ', '&', '*'], '', $overload->cppReturnType));
    $wrapFunc = $ctx->typeBridge->wrapNativeFuncName($returnClass);
    $returnCe = $ctx->typeBridge->ceVarName($returnClass);
@endphp
{!! $indent !!}{!! $returnClass !!} *_result = {!! $callPrefix !!}{!! $method->cppName !!}(@foreach($overload->params as $i => $op)@php
    $mergedParam = $method->params[$i] ?? null;
    $varName = $mergedParam ? $mergedParam->cVarName : $op->name;
    $expr = $ctx->typeBridge->phpToNativeExpr($op->phpType, $op->cppType, $varName);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach);
{!! $indent !!}{!! $wrapFunc !!}(return_value, _result, {!! $returnCe !!}, true);
@else
{!! $indent !!}/* TODO: unsupported overload return strategy '{!! $overload->returnStrategy !!}' */
@endif
