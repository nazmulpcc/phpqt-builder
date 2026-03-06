@php
/** @var \QtBuilder\CodeGen\EnumHolderContext $ctx */
@endphp
{!! '<?php' !!}

/** @generate-class-entries */

namespace {!! $ctx->phpNamespace !!};

final class {!! $ctx->phpClassName !!}
{
@foreach($ctx->constants as $constant)
    public const {!! $constant['stubType'] !!} {!! $constant['name'] !!} = {!! $constant['stubValue'] !!};

@endforeach
}
