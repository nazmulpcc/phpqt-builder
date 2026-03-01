@php
/**
 * Generates a .stub.php file for gen_stub.php to produce _arginfo.h
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 */
@endphp
<?php

/** @generate-class-entries */

namespace {!! $ctx->phpNamespace !!};

@php
    $classDecl = '';
    if ($ctx->isAbstract) $classDecl .= 'abstract ';
    if ($ctx->isFinal) $classDecl .= 'final ';
    $classDecl .= 'class ' . $ctx->phpClassName;
    if ($ctx->parentClassName) $classDecl .= ' extends ' . $ctx->parentClassName;
@endphp
{!! $classDecl !!}
{
@foreach($ctx->methods as $method)
@php
    $modifiers = $method->access;
    if ($method->isStatic) $modifiers .= ' static';
@endphp
@if($method->isConstructor)
    {!! $modifiers !!} function __construct(@foreach($method->params as $param){!! $param->phpType !!} ${!! $param->name !!}@if($param->isOptional) = {!! $ctx->stubDefault($param) !!}@endif @if(!$loop->last), @endif @endforeach) {}

@else
    {!! $modifiers !!} function {!! $method->name !!}(@foreach($method->params as $param){!! $param->phpType !!} ${!! $param->name !!}@if($param->isOptional) = {!! $ctx->stubDefault($param) !!}@endif @if(!$loop->last), @endif @endforeach): {!! $method->returnType !!} {}

@endif
@endforeach
}
