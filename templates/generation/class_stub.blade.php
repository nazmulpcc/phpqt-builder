@php
/**
 * Generates a .stub.php file for gen_stub.php to produce _arginfo.h
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 */
@endphp
{!! '<?php' !!}

/** @generate-class-entries */

namespace {!! $ctx->phpNamespace !!};

@php
    $classDecl = '';
    if ($ctx->isAbstract) $classDecl .= 'abstract ';
    if ($ctx->isFinal) $classDecl .= 'final ';
    $classDecl .= 'class ' . $ctx->phpClassName;
    if ($ctx->stubParentClassName) $classDecl .= ' extends ' . $ctx->stubParentClassName;
@endphp
{!! $classDecl !!}
{
@foreach($ctx->methods as $method)
@php
    $modifiers = $method->access;
    if ($method->isStatic) $modifiers .= ' static';
@endphp
@if($method->isConstructor)
    {!! $modifiers !!} function __construct(@foreach($method->params as $param){!! $ctx->stubParamType($param) !!} ${!! $param->name !!}@if($param->isOptional) = {!! $ctx->stubDefault($param) !!}@endif @if(!$loop->last), @endif @endforeach) {}

@elseif($method->isAbstractMethod)
    abstract {!! $modifiers !!} function {!! $method->name !!}(@foreach($method->params as $param){!! $ctx->stubParamType($param) !!} ${!! $param->name !!}@if($param->isOptional) = {!! $ctx->stubDefault($param) !!}@endif @if(!$loop->last), @endif @endforeach): {!! $method->stubReturnType !!};

@else
    {!! $modifiers !!} function {!! $method->name !!}(@foreach($method->params as $param){!! $ctx->stubParamType($param) !!} ${!! $param->name !!}@if($param->isOptional) = {!! $ctx->stubDefault($param) !!}@endif @if(!$loop->last), @endif @endforeach): {!! $method->stubReturnType !!} {}

@endif
@endforeach
@if($ctx->hasSignals())

    public function connect(string $signalSignature, callable $callback): \Qt\Core\QMetaObjectConnection {}
    public function disconnect(\Qt\Core\QMetaObjectConnection $connection): bool {}
@foreach($ctx->signalOverloads as $signal)

    public function {!! $signal->phpMethodName !!}(callable $callback): \Qt\Core\QMetaObjectConnection {}
@endforeach
@endif
}
