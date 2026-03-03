@php
/**
 * Single overload call within dispatch logic.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 * @var \QtBuilder\CodeGen\OverloadContext $overload
 * @var string $indent
 */
$callPlan = $method->callPlan($ctx, $overload);
$declaringClass = $overload->declaringClass !== '' ? $overload->declaringClass : $ctx->nativeCppType;
$callExpr = null;
if (!$overload->isPureVirtual) {
    if ($overload->access === 'protected') {
        $callExpr = $overload->isStatic
            ? "{$ctx->accessShimTypeName}::{$method->accessShimHelperName($index)}(" . implode(', ', $callPlan['args']) . ')'
            : "static_cast<{$ctx->nativeInstantiationType} *>(intern->native_ptr)->{$method->accessShimHelperName($index)}(" . implode(', ', $callPlan['args']) . ')';
    } elseif ($method->isStatic) {
        $callExpr = "{$ctx->nativeCppType}::{$method->cppName}(" . implode(', ', $callPlan['args']) . ')';
    } elseif ($overload->isVirtual || $overload->isPureVirtual) {
        $callExpr = "intern->native_ptr->{$declaringClass}::{$method->cppName}(" . implode(', ', $callPlan['args']) . ')';
    } else {
        $callExpr = "intern->native_ptr->{$method->cppName}(" . implode(', ', $callPlan['args']) . ')';
    }
}
@endphp
@foreach($callPlan['setup_lines'] as $line)
{!! $indent !!}{!! $line !!}
@endforeach
@if($overload->access === 'protected' && !$overload->isStatic && !$overload->isPureVirtual)
{!! $indent !!}if (!intern->native_is_generated_subclass) {
{!! $indent !!}    zend_throw_error(NULL, "Protected method {!! $ctx->phpClassName !!}::{!! $method->name !!}() requires a PHP-created native instance.");
{!! $indent !!}    RETURN_THROWS();
{!! $indent !!}}
@endif
@if($overload->isPureVirtual)
{!! $indent !!}zend_throw_error(NULL, "Pure virtual method {!! $ctx->phpClassName !!}::{!! $method->name !!}() cannot be called directly.");
{!! $indent !!}RETURN_THROWS();
@elseif($overload->returnStrategy === 'void')
{!! $indent !!}{!! $callExpr !!};
@elseif($overload->returnStrategy === 'scalar')
@php
    $macro = $ctx->typeBridge->returnMacro($overload->phpReturnType);
    $returnExpr = $ctx->typeBridge->nativeScalarToPhpExpr($overload->phpReturnType, $overload->cppReturnType, $callExpr);
@endphp
@if($macro === null)
{!! $indent !!}RETURN_NULL();
@else
{!! $indent !!}{!! $macro !!}({!! $returnExpr !!});
@endif
@elseif($overload->returnStrategy === 'string')
{!! $indent !!}auto _result = {!! $callExpr !!};
{!! $indent !!}{!! $ctx->typeBridge->nativeStringToPhpReturn($overload->cppReturnType, '_result') !!};
@elseif($overload->returnStrategy === 'value_object')
@php
    $returnClass = trim(str_replace(['const ', '&', '*'], '', $overload->cppReturnType));
    $returnCe = $ctx->typeBridge->ceVarName($returnClass);
    $returnFromObj = $ctx->typeBridge->fromObjFuncName($returnClass);
    $returnStruct = $ctx->typeBridge->objectStructName($returnClass);
@endphp
{!! $indent !!}{!! $returnClass !!} _result = {!! $callExpr !!};
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
{!! $indent !!}{!! $resultDeclType !!} _result = {!! $callExpr !!};
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
