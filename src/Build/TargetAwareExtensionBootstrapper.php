<?php

declare(strict_types=1);

namespace QtBuilder\Build;

final readonly class TargetAwareExtensionBootstrapper implements ExtensionBootstrapper
{
    public function __construct(
        private ExtensionBootstrapper $desktopBootstrapper,
        private ExtensionBootstrapper $iosBootstrapper,
    ) {}

    public function bootstrap(ExtensionBuildContext $context, int $jobs, bool $useCcache = false, ?callable $onEvent = null): BootstrapResult
    {
        if ($context->isIosTarget()) {
            return $this->iosBootstrapper->bootstrap($context, $jobs, $useCcache, $onEvent);
        }

        return $this->desktopBootstrapper->bootstrap($context, $jobs, $useCcache, $onEvent);
    }
}
