@php
/**
 * Constructor dispatch for overloaded constructors.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
@endphp
@php
    $groups = [];
    foreach ($method->overloads as $i => $ol) {
        $groups[$ol->paramCount][] = ['index' => $i, 'overload' => $ol];
    }
    ksort($groups);
    $first = true;
@endphp
    zend_long _argc = ZEND_NUM_ARGS();
@if($ctx->needsArgvStorage)
    if (intern->extra_storage == NULL) {
        intern->extra_storage = new {!! $ctx->argvStorageStructName !!}();
    }
    auto *_qt_argv_storage = static_cast<{!! $ctx->argvStorageStructName !!} *>(intern->extra_storage);
@endif

@foreach($groups as $paramCount => $overloadsInGroup)
    {!! $first ? 'if' : '} else if' !!} (_argc == {!! $paramCount !!}) {
@if(count($overloadsInGroup) === 1)
@php $ol = $overloadsInGroup[0]['overload']; @endphp
@php $callPlan = $method->callPlan($ctx, $ol, $ctx->needsArgvStorage ? '_qt_argv_storage' : null); @endphp
@foreach($callPlan['setup_lines'] as $line)
        {!! $line !!}
@endforeach
        intern->native_ptr = new {!! $ctx->nativeCppType !!}({!! implode(', ', $callPlan['args']) !!});
@else
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
@php $callPlan = $method->callPlan($ctx, $ol, $ctx->needsArgvStorage ? '_qt_argv_storage' : null); @endphp
@foreach($callPlan['setup_lines'] as $line)
            {!! $line !!}
@endforeach
            intern->native_ptr = new {!! $ctx->nativeCppType !!}({!! implode(', ', $callPlan['args']) !!});
@php $innerFirst = false; @endphp
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
@if($ctx->hasPreventDestroy)
@foreach($method->params as $param)
@if($param->isObject && !$param->isUnion)
        if ({!! $param->cVarName !!} != NULL) {
            intern->prevent_destroy = true;
        }
@endif
@endforeach
@endif
@php $first = false; @endphp
@endforeach
    } else {
        intern->native_ptr = new {!! $ctx->nativeCppType !!}();
    }
