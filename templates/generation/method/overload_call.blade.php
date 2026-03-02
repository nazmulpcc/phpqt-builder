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
    $expr = $ctx->typeBridge->phpToNativeExpr($op->phpType, $op->cppType, $varName, false, $mergedParam?->isOptional ?? false);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach);
@elseif($overload->returnStrategy === 'scalar')
@php
    $macro = $ctx->typeBridge->returnMacro($overload->phpReturnType);
    ob_start();
@endphp
{!! $callPrefix !!}{!! $method->cppName !!}(@foreach($overload->params as $i => $op)@php
    $mergedParam = $method->params[$i] ?? null;
    $varName = $mergedParam ? $mergedParam->cVarName : $op->name;
    $expr = $ctx->typeBridge->phpToNativeExpr($op->phpType, $op->cppType, $varName, false, $mergedParam?->isOptional ?? false);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach));
@php
    $callExpr = trim(ob_get_clean() ?: '');
    $returnExpr = $ctx->typeBridge->nativeScalarToPhpExpr($overload->phpReturnType, $overload->cppReturnType, rtrim($callExpr, ';'));
@endphp
@if($macro === null)
{!! $indent !!}RETURN_NULL();
@else
{!! $indent !!}{!! $macro !!}({!! $returnExpr !!});
@endif
@elseif($overload->returnStrategy === 'string')
{!! $indent !!}auto _result = {!! $callPrefix !!}{!! $method->cppName !!}(@foreach($overload->params as $i => $op)@php
    $mergedParam = $method->params[$i] ?? null;
    $varName = $mergedParam ? $mergedParam->cVarName : $op->name;
    $expr = $ctx->typeBridge->phpToNativeExpr($op->phpType, $op->cppType, $varName, false, $mergedParam?->isOptional ?? false);
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
    $expr = $ctx->typeBridge->phpToNativeExpr($op->phpType, $op->cppType, $varName, false, $mergedParam?->isOptional ?? false);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach);
{!! $indent !!}object_init_ex(return_value, {!! $returnCe !!});
{!! $indent !!}{!! $returnStruct !!} *_ret_intern = {!! $returnFromObj !!}(Z_OBJ_P(return_value));
{!! $indent !!}_ret_intern->native_ptr = new {!! $returnClass !!}(_result);
@elseif($overload->returnStrategy === 'qobject_pointer')
@php
    $returnClass = trim(str_replace(['const ', '&', '*'], '', $overload->cppReturnType));
    $resultDeclType = $ctx->typeBridge->objectPointerReturnDeclarationType($overload->cppReturnType, $returnClass);
    $returnCe = $ctx->typeBridge->ceVarName($returnClass);
    $returnFromObj = $ctx->typeBridge->fromObjFuncName($returnClass);
    $returnStruct = $ctx->typeBridge->objectStructName($returnClass);
    $wrapFunc = $ctx->typeBridge->wrapNativeFuncName($returnClass);
    $isValueType = $ctx->typeBridge->isValueType($returnClass);
    $writableResultExpr = $ctx->typeBridge->writableObjectPointerExpr($overload->cppReturnType, $returnClass, '_result');
@endphp
{!! $indent !!}{!! $resultDeclType !!} _result = {!! $callPrefix !!}{!! $method->cppName !!}(@foreach($overload->params as $i => $op)@php
    $mergedParam = $method->params[$i] ?? null;
    $varName = $mergedParam ? $mergedParam->cVarName : $op->name;
    $expr = $ctx->typeBridge->phpToNativeExpr($op->phpType, $op->cppType, $varName, false, $mergedParam?->isOptional ?? false);
@endphp{!! $expr !!}@if(!$loop->last), @endif @endforeach);
@if($isValueType)
{!! $indent !!}if (_result == NULL) {
{!! $indent !!}    RETURN_NULL();
{!! $indent !!}}
{!! $indent !!}object_init_ex(return_value, {!! $returnCe !!});
{!! $indent !!}{!! $returnStruct !!} *_ret_intern = {!! $returnFromObj !!}(Z_OBJ_P(return_value));
{!! $indent !!}_ret_intern->native_ptr = new {!! $returnClass !!}(*_result);
@else
{!! $indent !!}{!! $wrapFunc !!}(return_value, {!! $writableResultExpr !!}, {!! $returnCe !!}, true);
@endif
@else
{!! $indent !!}/* TODO: unsupported overload return strategy '{!! $overload->returnStrategy !!}' */
@endif
