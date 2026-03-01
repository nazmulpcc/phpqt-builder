@php
/**
 * Overloaded method template — dispatches by argument count and type.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
@endphp
/* {!! $method->name !!} — {!! $method->overloadCount !!} C++ overloads */
ZEND_METHOD({!! $ctx->zendClassSymbol !!}, {!! $method->name !!})
{
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
@if($param->isUnion || $param->isObject)
        Z_PARAM_ZVAL({!! $param->cVarName !!})
@else
        {!! $param->zppMacro !!}
@endif
@endforeach
    ZEND_PARSE_PARAMETERS_END();

@if(!$method->isStatic)
    {!! $ctx->objectStructName !!} *intern = {!! $ctx->zMacro !!}(ZEND_THIS);

@endif
    zend_long _argc = ZEND_NUM_ARGS();

@php
    // Group overloads by parameter count for dispatch
    $groups = [];
    foreach ($method->overloads as $i => $ol) {
        $groups[$ol->paramCount][] = ['index' => $i, 'overload' => $ol];
    }
    ksort($groups);
    $first = true;
@endphp
@foreach($groups as $paramCount => $overloadsInGroup)
    {!! $first ? 'if' : '} else if' !!} (_argc == {!! $paramCount !!}) {
@if(count($overloadsInGroup) === 1)
@php $ol = $overloadsInGroup[0]['overload']; @endphp
@include('generation.method.overload_call', ['ctx' => $ctx, 'method' => $method, 'overload' => $ol, 'indent' => '        '])
@else
{{-- Multiple overloads with same param count — dispatch by type of first arg --}}
@php $innerFirst = true; @endphp
@foreach($overloadsInGroup as $entry)
@php $ol = $entry['overload']; @endphp
@if($ol->paramCount > 0)
@php
    $firstParam = $ol->params[0];
    $mergedParam = $method->params[0];
@endphp
@if($firstParam->phpType === 'int' || $firstParam->phpType === 'float' || $firstParam->phpType === 'bool' || $firstParam->phpType === 'string')
        {!! $innerFirst ? 'if' : '} else if' !!} (Z_TYPE_P({!! $mergedParam->cVarName !!}) == {!! $firstParam->phpType === 'int' ? 'IS_LONG' : ($firstParam->phpType === 'float' ? 'IS_DOUBLE' : ($firstParam->phpType === 'bool' ? '_IS_BOOL' : 'IS_STRING')) !!}) {
@else
        {!! $innerFirst ? 'if' : '} else if' !!} (Z_TYPE_P({!! $mergedParam->cVarName !!}) == IS_OBJECT && instanceof_function(Z_OBJCE_P({!! $mergedParam->cVarName !!}), {!! $ctx->typeBridge->ceVarName($firstParam->phpType) !!})) {
@endif
@include('generation.method.overload_call', ['ctx' => $ctx, 'method' => $method, 'overload' => $ol, 'indent' => '            '])
@php $innerFirst = false; @endphp
@else
@include('generation.method.overload_call', ['ctx' => $ctx, 'method' => $method, 'overload' => $ol, 'indent' => '            '])
@endif
@endforeach
@if(!$innerFirst)
        } else {
            zend_argument_type_error(1, "must be of type {!! $method->params[0]->phpType !!}, %s given",
                zend_zval_value_name({!! $method->params[0]->cVarName !!}));
            RETURN_THROWS();
        }
@endif
@endif
@php $first = false; @endphp
@endforeach
    } else {
        zend_wrong_parameters_count_error({!! $method->requiredArgCount !!}, {!! $method->maxArgCount !!});
        RETURN_THROWS();
    }
}

