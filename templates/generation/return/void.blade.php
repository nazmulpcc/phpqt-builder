@php
/**
 * Void return — just call the method.
 *
 * @var \QtBuilder\CodeGen\ClassContext $ctx
 * @var \QtBuilder\CodeGen\MethodContext $method
 */
$overload = $method->overloads[0] ?? null;
$callPrefix = $method->isStatic
    ? "{$ctx->nativeCppType}::"
    : "intern->native_ptr->";
$callPlan = $method->callPlan($ctx, $overload);
@endphp
@if($method->hasNoParams())
    {!! $callPrefix !!}{!! $method->cppName !!}();
@else
@foreach($callPlan['setup_lines'] as $line)
    {!! $line !!}
@endforeach
    {!! $callPrefix !!}{!! $method->cppName !!}({!! implode(', ', $callPlan['args']) !!});
@endif
