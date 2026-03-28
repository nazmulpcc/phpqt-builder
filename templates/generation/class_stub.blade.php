@php
/**
 * Generates a .stub.php file for gen_stub.php to produce _arginfo.h
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 */
@endphp
{!! '<' . '?php' !!}

/** @generate-class-entries */

namespace {!! $ctx->phpNamespace !!};

@php
    $classDecl = '';
    if ($ctx->isAbstract) {
        $classDecl .= 'abstract ';
    }
    if ($ctx->isFinal) {
        $classDecl .= 'final ';
    }
    $classDecl .= 'class ' . $ctx->phpClassName;
    if ($ctx->stubParentClassName) {
        $classDecl .= ' extends ' . $ctx->stubParentClassName;
    }
    if ($ctx->nativeCppType === 'QString') {
        $classDecl .= ' implements \Stringable';
    }
@endphp
{!! $classDecl !!}
{
@if($ctx->hasClassConstants())
@foreach($ctx->classConstants as $constant)
    public const {!! $constant['stubType'] !!} {!! $constant['name'] !!} = {!! $constant['stubValue'] !!};

@endforeach
@endif
@foreach($ctx->methods as $method)
@php
    $modifiers = $method->access;
    if ($method->isStatic) {
        $modifiers .= ' static';
    }
@endphp
@if($ctx->nativeCppType === 'QThread' && $method->isConstructor)
    {!! $modifiers !!} function __construct(\Qt\Core\QObject|null $parent = null, ?string $bootstrapScript = null) {}

@elseif($ctx->nativeCppType === 'QThread' && $method->name === 'start')
    {!! $modifiers !!} function start(int|string|null $taskOrPriority = null, array $args = [], ?int $priority = null): void {}

@elseif($method->isConstructor)
    {!! $modifiers !!} function __construct(@foreach($method->params as $param){!! $ctx->stubParamType($param) !!} @if($param->isByRef)&@endif${!! $param->name !!}@if($param->isOptional) = {!! $ctx->stubDefault($param) !!}@endif @if(!$loop->last), @endif @endforeach) {}

@elseif($method->isAbstractMethod)
    abstract {!! $modifiers !!} function {!! $method->name !!}(@foreach($method->params as $param){!! $ctx->stubParamType($param) !!} @if($param->isByRef)&@endif${!! $param->name !!}@if($param->isOptional) = {!! $ctx->stubDefault($param) !!}@endif @if(!$loop->last), @endif @endforeach): {!! $method->stubReturnType !!};

@else
    {!! $modifiers !!} function {!! $method->name !!}(@foreach($method->params as $param){!! $ctx->stubParamType($param) !!} @if($param->isByRef)&@endif${!! $param->name !!}@if($param->isOptional) = {!! $ctx->stubDefault($param) !!}@endif @if(!$loop->last), @endif @endforeach): {!! $method->stubReturnType !!} {}

@endif
@endforeach
@if($ctx->nativeCppType === 'QThread')

    public function on(string $event, callable $listener): int {}
    public function off(int $listenerId): bool {}
    public function drainEvents(int $maxItems = -1): int {}
    public function send(string $event, array $payload = []): bool {}
    public function startFuture(string $callable, array $args = [], ?int $priority = null): \Qt\Core\QFuture {}
    public static function publish(string $event, array $payload = []): bool {}
    /** @return array{event:string,payload:array}|null */
    public static function receive(int $timeoutMs = 0): ?array {}
@endif
@if($ctx->nativeCppType === 'QString')
    public function __toString(): string {}

@endif
@if($ctx->isQObjectClass)

    public function property(string $name): mixed {}
    public function setProperty(string $name, mixed $value): bool {}
    public function hasProperty(string $name): bool {}
    public function propertyNames(): array {}
    public function propertyInfo(string $name): array {}
    public function connectPropertyNotify(string $name, callable $callback): \Qt\Core\QMetaObjectConnection {}
@endif
@if($ctx->hasSignals())

    public function connect(string $signalSignature, callable $callback): \Qt\Core\QMetaObjectConnection {}
    public function disconnect(\Qt\Core\QMetaObjectConnection $connection): bool {}
@foreach($ctx->signalOverloads as $signal)

    public function {!! $signal->phpMethodName !!}(callable $callback): \Qt\Core\QMetaObjectConnection {}
@endforeach

@endif
}
