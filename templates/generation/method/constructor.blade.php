@php
/**
 * Constructor method template.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
@endphp
/* {!! $method->name !!} */
ZEND_METHOD({!! $ctx->zendClassSymbol !!}, __construct)
{
@if($method->hasNoParams())
    ZEND_PARSE_PARAMETERS_NONE();
@else
@foreach($method->params as $param)
    {!! $param->cDeclaration() !!};
@endforeach

    ZEND_PARSE_PARAMETERS_START({!! $method->requiredArgCount !!}, {!! $method->maxArgCount !!})
@php $seenOptional = false; @endphp
@foreach($method->params as $param)
@if($param->isOptional && !$seenOptional)
@php $seenOptional = true; @endphp
        Z_PARAM_OPTIONAL
@endif
        {!! $param->zppMacro !!}
@endforeach
    ZEND_PARSE_PARAMETERS_END();

@endif
    {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);

    if (intern->native_ptr != NULL) {
        zend_throw_error(NULL, "{!! $ctx->phpClassName !!}::__construct() called twice");
        RETURN_THROWS();
    }

@if($method->hasNoParams())
    intern->native_ptr = new {!! $ctx->nativeCppType !!}();
@elseif($method->isOverloaded)
@include('generation.method.constructor_dispatch', ['ctx' => $ctx, 'method' => $method])
@else
@php $callPlan = $method->callPlan($ctx, $method->overloads[0] ?? null, true); @endphp
@foreach($callPlan['setup_lines'] as $line)
    {!! $line !!}
@endforeach
    intern->native_ptr = new {!! $ctx->nativeCppType !!}({!! implode(', ', $callPlan['args']) !!});
@if($ctx->hasPreventDestroy)
@foreach($method->params as $param)
@if($param->isObject && !$param->isUnion)

    if ({!! $param->cVarName !!} != NULL) {
        intern->prevent_destroy = true;
    }
@endif
@endforeach
@endif
@endif
}
