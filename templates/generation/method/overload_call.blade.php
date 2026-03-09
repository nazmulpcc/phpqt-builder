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
$postCallLines = $method->postCallLines($ctx, $overload);
$writebackLines = $method->writebackLines($ctx, $overload);
$declaringClass = $overload->declaringClass !== '' ? $overload->declaringClass : $ctx->nativeCppType;
$callExpr = null;
if (!$overload->isPureVirtual) {
    if ($overload->access === 'protected') {
        $callExpr = $overload->isStatic
            ? "{$ctx->accessShimTypeName}::{$method->accessShimHelperName($index)}(" . implode(', ', $callPlan['args']) . ')'
            : "static_cast<{$ctx->protectedCallReceiverType} *>(intern->native_ptr)->{$method->accessShimHelperName($index)}(" . implode(', ', $callPlan['args']) . ')';
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
@foreach($postCallLines as $line)
{!! $indent !!}{!! $line !!}
@endforeach
@foreach($writebackLines as $line)
{!! $indent !!}{!! $line !!}
@endforeach
@elseif($overload->returnStrategy === 'scalar')
@php
    $macro = $ctx->typeBridge->returnMacro($overload->phpReturnType);
    $returnExpr = $ctx->typeBridge->nativeScalarToPhpExpr($overload->phpReturnType, $overload->cppReturnType, $callExpr);
@endphp
@if($macro === null)
{!! $indent !!}RETURN_NULL();
@else
@foreach($writebackLines as $line)
{!! $indent !!}{!! $line !!}
@endforeach
{!! $indent !!}{!! $macro !!}({!! $returnExpr !!});
@endif
@elseif($overload->returnStrategy === 'string')
{!! $indent !!}auto _result = {!! $callExpr !!};
@foreach($writebackLines as $line)
{!! $indent !!}{!! $line !!}
@endforeach
{!! $indent !!}{!! $ctx->typeBridge->nativeStringToPhpReturn($overload->cppReturnType, '_result') !!};
@elseif($overload->returnStrategy === 'value_object')
@php
    $returnPhpClass = trim(str_replace(['const ', '&', '*'], '', $overload->phpReturnType));
    $returnCppClass = $ctx->typeBridge->nativeValueObjectType($overload->cppReturnType);
    $returnCe = $ctx->ceVarNameForPhpType($returnPhpClass);
    $returnFromObj = $ctx->fromObjFuncNameForPhpType($returnPhpClass);
    $returnStruct = $ctx->objectStructNameForPhpType($returnPhpClass);
@endphp
{!! $indent !!}{!! $returnCppClass !!} _result = {!! $callExpr !!};
@foreach($writebackLines as $line)
{!! $indent !!}{!! $line !!}
@endforeach
{!! $indent !!}object_init_ex(return_value, {!! $returnCe !!});
{!! $indent !!}if (UNEXPECTED(Z_TYPE_P(return_value) != IS_OBJECT)) {
{!! $indent !!}    if (!EG(exception)) {
 {!! $indent !!}        zend_throw_error(NULL, "Failed to instantiate PHP wrapper for {!! $returnPhpClass !!}");
{!! $indent !!}    }
{!! $indent !!}    RETURN_THROWS();
{!! $indent !!}}
{!! $indent !!}{!! $returnStruct !!} *_ret_intern = {!! $returnFromObj !!}(Z_OBJ_P(return_value));
{!! $indent !!}_ret_intern->native_ptr = new {!! $returnCppClass !!}(std::move(_result));
@elseif($overload->returnStrategy === 'qobject_pointer' || $overload->returnStrategy === 'smart_pointer_alias')
@php
    $isSmartPointerAlias = $overload->smartPointerReturnTargetCppType !== null
        || $overload->returnStrategy === 'smart_pointer_alias';
    $returnClass = $isSmartPointerAlias
        ? trim($overload->phpReturnType)
        : trim(str_replace(['const ', '&', '*'], '', $overload->cppReturnType));
    $resultDeclType = $ctx->typeBridge->objectPointerReturnDeclarationType($overload->cppReturnType, $returnClass);
    $returnCe = $ctx->ceVarNameForPhpType($returnClass);
    $returnFromObj = $ctx->fromObjFuncNameForPhpType($returnClass);
    $returnStruct = $ctx->objectStructNameForPhpType($returnClass);
    $wrapFunc = $ctx->typeBridge->wrapNativeFuncName($returnClass);
    $isValueType = $isSmartPointerAlias
        ? false
        : $ctx->typeBridge->isValueType($returnClass);
    $writableResultExpr = $ctx->typeBridge->writableObjectPointerExpr($overload->cppReturnType, $returnClass, '_result');
@endphp
{!! $indent !!}{!! $resultDeclType !!} _result = {!! $callExpr !!};
@foreach($writebackLines as $line)
{!! $indent !!}{!! $line !!}
@endforeach
@if($isValueType)
{!! $indent !!}if (_result == NULL) {
{!! $indent !!}    RETURN_NULL();
{!! $indent !!}}
{!! $indent !!}object_init_ex(return_value, {!! $returnCe !!});
{!! $indent !!}if (UNEXPECTED(Z_TYPE_P(return_value) != IS_OBJECT)) {
{!! $indent !!}    if (!EG(exception)) {
{!! $indent !!}        zend_throw_error(NULL, "Failed to instantiate PHP wrapper for {!! $returnClass !!}");
{!! $indent !!}    }
{!! $indent !!}    RETURN_THROWS();
{!! $indent !!}}
{!! $indent !!}{!! $returnStruct !!} *_ret_intern = {!! $returnFromObj !!}(Z_OBJ_P(return_value));
{!! $indent !!}_ret_intern->native_ptr = new {!! $returnClass !!}(*_result);
@else
{!! $indent !!}{!! $wrapFunc !!}(return_value, {!! $writableResultExpr !!}, {!! $returnCe !!}, true);
@endif
@elseif($overload->returnStrategy === 'array')
{!! $indent !!}auto _result = {!! $callExpr !!};
@foreach($writebackLines as $line)
{!! $indent !!}{!! $line !!}
@endforeach
{!! $indent !!}{!! $ctx->typeBridge->nativeContainerToPhpZvalBlock('return_value', $overload->cppReturnType, '_result', $index) !!}
{!! $indent !!}return;
@else
{!! $indent !!}/* TODO: unsupported overload return strategy '{!! $overload->returnStrategy !!}' */
@endif
