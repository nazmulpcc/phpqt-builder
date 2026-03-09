<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Scanning\HeaderCandidate;

readonly class SupplementalClassCandidate
{
    public function __construct(
        public HeaderCandidate $candidate,
        public string $discoveredFromClass,
        public string $discoveredFromHeader,
        public string $triggerReason,
    ) {}

    /**
     * @return array{
     *   module: string,
     *   class: string,
     *   public_header: string,
     *   parse_header: string,
     *   discovered_from_class: string,
     *   discovered_from_header: string,
     *   trigger_reason: string
     * }
     */
    public function toArray(): array
    {
        return [
            'module' => $this->candidate->module,
            'class' => $this->candidate->className,
            'public_header' => $this->candidate->publicHeader,
            'parse_header' => $this->candidate->parseHeader,
            'discovered_from_class' => $this->discoveredFromClass,
            'discovered_from_header' => $this->discoveredFromHeader,
            'trigger_reason' => $this->triggerReason,
        ];
    }

    public function identityKey(): string
    {
        return $this->candidate->identityKey();
    }
}
