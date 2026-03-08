@php
/**
 * QObject pointer return — wrap existing C++ pointer in PHP object.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
$overload = $method->overloads[0] ?? null;
$returnClass = $method->returnType;
$cppReturnType = $overload?->cppReturnType ?? ($returnClass . ' *');
$isSmartPointerAlias = ($overload?->smartPointerReturnTargetCppType ?? null) !== null
    || ($overload?->returnStrategy ?? null) === 'smart_pointer_alias';
$returnClass = $isSmartPointerAlias
    ? ($overload?->phpReturnType ?? $returnClass)
    : $returnClass;
$resultDeclType = $ctx->typeBridge->objectPointerReturnDeclarationType($cppReturnType, $returnClass);
$writableResultExpr = $ctx->typeBridge->writableObjectPointerExpr($cppReturnType, $returnClass, '_result');
$returnCe = $ctx->ceVarNameForPhpType($returnClass);
$returnFromObj = $ctx->fromObjFuncNameForPhpType($returnClass);
$returnStruct = $ctx->objectStructNameForPhpType($returnClass);
$wrapFunc = $ctx->typeBridge->wrapNativeFuncName($returnClass);
$isValueType = $isSmartPointerAlias
    ? false
    : $ctx->typeBridge->isValueType($returnClass);
$callPlan = $method->callPlan($ctx, $overload);
$writebackLines = $method->writebackLines($ctx, $overload);
$declaringClass = $overload?->declaringClass !== '' ? $overload->declaringClass : $ctx->nativeCppType;
$callExpr = null;
if ($overload?->isPureVirtual) {
    $callExpr = null;
} elseif (($overload?->access ?? 'public') === 'protected') {
    $callExpr = $method->isStatic
        ? "{$ctx->accessShimTypeName}::{$method->accessShimHelperName(0)}(" . implode(', ', $callPlan['args']) . ')'
        : "static_cast<{$ctx->protectedCallReceiverType} *>(intern->native_ptr)->{$method->accessShimHelperName(0)}(" . implode(', ', $callPlan['args']) . ')';
} elseif ($method->isStatic) {
    $callExpr = "{$ctx->nativeCppType}::{$method->cppName}(" . implode(', ', $callPlan['args']) . ')';
} elseif ($overload?->isVirtual || $overload?->isPureVirtual) {
    $callExpr = "intern->native_ptr->{$declaringClass}::{$method->cppName}(" . implode(', ', $callPlan['args']) . ')';
} else {
    $callExpr = "intern->native_ptr->{$method->cppName}(" . implode(', ', $callPlan['args']) . ')';
}
@endphp
@if((($overload?->access ?? 'public') === 'protected') && !$method->isStatic && !($overload?->isPureVirtual ?? false))
    if (!intern->native_is_generated_subclass) {
        zend_throw_error(NULL, "Protected method {!! $ctx->phpClassName !!}::{!! $method->name !!}() requires a PHP-created native instance.");
        RETURN_THROWS();
    }

@endif
@if($overload?->isPureVirtual)
    zend_throw_error(NULL, "Pure virtual method {!! $ctx->phpClassName !!}::{!! $method->name !!}() cannot be called directly.");
    RETURN_THROWS();
@else
@if(!$method->hasNoParams())
@foreach($callPlan['setup_lines'] as $line)
    {!! $line !!}
@endforeach
@endif
    {!! $resultDeclType !!} _result = {!! $callExpr !!};
@foreach($writebackLines as $line)
    {!! $line !!}
@endforeach
@if($isValueType)
    if (_result == NULL) {
        RETURN_NULL();
    }
    object_init_ex(return_value, {!! $returnCe !!});
    if (UNEXPECTED(Z_TYPE_P(return_value) != IS_OBJECT)) {
        if (!EG(exception)) {
            zend_throw_error(NULL, "Failed to instantiate PHP wrapper for {!! $returnClass !!}");
        }
        RETURN_THROWS();
    }
    {!! $returnStruct !!} *_ret_intern = {!! $returnFromObj !!}(Z_OBJ_P(return_value));
    _ret_intern->native_ptr = new {!! $returnClass !!}(*_result);
@else
    {!! $wrapFunc !!}(return_value, {!! $writableResultExpr !!}, {!! $returnCe !!}, true);
@endif
@endif
