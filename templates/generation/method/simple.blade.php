@php
/**
 * Simple (non-overloaded) method template.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
@endphp
/* {!! $method->name !!} */
ZEND_METHOD({!! $ctx->zendClassSymbol !!}, {!! $method->name !!})
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
@if(!$method->isStatic)
    {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);

@if(!$method->isConstructor)
    if (intern->native_ptr == NULL) {
        zend_throw_error(NULL, "{!! $ctx->phpClassName !!} native instance is not initialized");
        RETURN_THROWS();
    }

@endif
@endif
@if($method->returnStrategy === 'void')
@include('generation.return.void', ['ctx' => $ctx, 'method' => $method])
@elseif($method->returnStrategy === 'scalar')
@include('generation.return.scalar', ['ctx' => $ctx, 'method' => $method])
@elseif($method->returnStrategy === 'string')
@include('generation.return.string', ['ctx' => $ctx, 'method' => $method])
@elseif($method->returnStrategy === 'value_object')
@include('generation.return.value_object', ['ctx' => $ctx, 'method' => $method])
@elseif($method->returnStrategy === 'qobject_pointer')
@include('generation.return.qobject_pointer', ['ctx' => $ctx, 'method' => $method])
@else
    /* TODO: unsupported return strategy '{!! $method->returnStrategy !!}' */
@endif
}
