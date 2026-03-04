<?php

declare(strict_types=1);

namespace QtBuilder\Build;

interface ExtensionBootstrapper
{
    public function bootstrap(ExtensionBuildContext $context, int $jobs, ?callable $onEvent = null): BootstrapResult;
}
